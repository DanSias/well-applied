// The isolated deployment repeats a handful of facts in several files (bridge names, the Well Applied address, resolvers,
// gateways, blocked ranges). If one drifts the isolation silently stops matching the deployment, so this test reads every
// file and fails when they disagree. It also pins the properties the design depends on.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (f) => readFileSync(path.join(ROOT, f), 'utf8');
const strip = (text) => text.replace(/^\s*#.*$/gm, '');

const compose = read('compose.yaml');
const nft = read('isolation/inspector-isolation.nft');
const nginx = read('relay/nginx.conf');
const urlSafety = read('src/url-safety.js');
const units = Object.fromEntries(['inspector-isolation.service', 'inspector-isolation-guard.service', 'career-toolkit-browser-worker.service', 'docker.service.d-inspector-isolation.conf'].map((f) => [f, read(`isolation/${f}`)]));

const BRIDGES = { egress: 'br-inspector', 'relay-link': 'br-relaylink', 'relay-out': 'br-relay' };
const WELL_APPLIED = { host: '192.168.1.114', port: '8000' };

/** The text of one top-level service block of compose.yaml. */
const service = (name) => compose.split(/^  (?=[a-z-]+:\n)/m).find((b) => b.startsWith(`${name}:`)) ?? '';

describe('the same facts in every file', () => {
    test('each bridge name appears in compose, the policy and the behavioural test', () => {
        const harness = read('test/isolation/netns-test.sh');
        for (const [network, bridge] of Object.entries(BRIDGES)) {
            assert.match(compose, new RegExp(`com\\.docker\\.network\\.bridge\\.name: ${bridge}\\b`), `${network} -> ${bridge} in compose`);
            assert.match(nft, new RegExp(`"${bridge}"`), `${bridge} in the policy`);
            assert.match(harness, new RegExp(bridge), `${bridge} in the netns test`);
        }
    });

    test('Well Applied is the same address and port in the policy, the relay, the canaries and the relay test', () => {
        const { host, port } = WELL_APPLIED;
        assert.match(nft, new RegExp(`ip daddr ${host.replaceAll('.', '\\.')} tcp dport ${port} accept`));
        assert.match(nginx, new RegExp(`server ${host.replaceAll('.', '\\.')}:${port};`));
        assert.match(compose, new RegExp(`${host.replaceAll('.', '\\.')}:9`), 'the Mac is a canary (closed port)');
        assert.match(read('test/isolation/relay-test.py'), new RegExp(`${host.replaceAll('.', '\\.')}', ${port}`));
        assert.equal((strip(nft).match(/192\.168\.1\.114/g) ?? []).length, 1, 'the policy names the Mac exactly once');
    });

    test('the container resolvers are exactly the resolvers the policy allows', () => {
        const dns = [...service('browser-inspector').matchAll(/^\s+- (\d+\.\d+\.\d+\.\d+)\s*$/gm)].map((m) => m[1]).sort();
        const allowed = /set resolvers4 \{[\s\S]*?elements = \{ ([^}]+) \}/.exec(nft)[1].split(',').map((s) => s.trim()).sort();
        assert.deepEqual(dns, allowed);
        assert.deepEqual(dns, ['1.1.1.1', '9.9.9.9']);
    });

    test('the gateway canaries are the networks\' real gateways', () => {
        const gateways = [...compose.matchAll(/gateway: (\d+\.\d+\.\d+\.\d+)/g)].map((m) => m[1]).sort();
        const canaries = /BROWSER_WORKER_ISOLATION_CANARIES: "([^"]+)"/.exec(compose)[1].split(',');
        for (const g of gateways) assert.ok(canaries.includes(`${g}:9`), `${g} is a canary`);
        assert.equal(gateways.length, 3);
    });

    test('the policy blocks exactly the ranges the worker\'s own URL guard refuses', () => {
        const fromNft = /set blocked4 \{[\s\S]*?elements = \{([\s\S]*?)\}/.exec(nft)[1].split(',').map((s) => s.trim()).filter(Boolean).sort();
        const fromJs = [...urlSafety.matchAll(/\['(\d+\.\d+\.\d+\.\d+)', (\d+)\],/g)].map((m) => `${m[1]}/${m[2]}`).sort();
        assert.deepEqual(fromNft, fromJs);
        assert.ok(fromNft.includes('100.64.0.0/10') && fromNft.includes('169.254.0.0/16') && fromNft.includes('192.168.0.0/16'));
    });

    test('the default canaries sit inside blocked ranges, so a correct policy refuses all of them', async () => {
        const { DEFAULT_BLOCKED_TARGETS } = await import('../src/isolation-check.js');
        const ranges = read('src/url-safety.js');
        for (const t of DEFAULT_BLOCKED_TARGETS) {
            const [ip] = t.split(':');
            const [a, b] = ip.split('.').map(Number);
            const inside = a === 10 || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 169 && b === 254) || (a === 100 && b >= 64 && b <= 127);
            assert.ok(inside, `${ip} is in a blocked range`);
        }
        assert.ok(ranges.includes('BLOCKED'));
    });

    test('.env.example points the worker at the relay, not at the Mac', () => {
        assert.match(read('.env.example'), /^CAREER_TOOLKIT_URL=http:\/\/api-relay:8081$/m);
    });
});

describe('the properties the design rests on', () => {
    test('the policy is its own table, at priority -10, never flushing anything else and never changing a policy', () => {
        const rules = strip(nft);
        assert.match(rules, /add table inet inspector_isolation\ndelete table inet inspector_isolation/);
        assert.equal((rules.match(/hook forward priority -10; policy accept;/g) ?? []).length, 1);
        assert.equal((rules.match(/hook input priority -10; policy accept;/g) ?? []).length, 1);
        assert.ok(!/flush|policy drop|policy reject|delete table (?!inet inspector_isolation)|add table (?!inet inspector_isolation)/.test(rules));
        assert.ok(!/\bsnat\b|\bdnat\b|masquerade|redirect/.test(rules), 'no NAT: it can only remove access');
    });

    test('IPv6 from every isolated bridge is dropped, and nothing is ever accepted without a destination restriction', () => {
        for (const chain of ['from_worker', 'from_relay', 'deny_all']) {
            const body = new RegExp(`chain ${chain} \\{([\\s\\S]*?)\\n  \\}`).exec(nft)[1];
            assert.match(body, /meta nfproto ipv6 drop/, `${chain} drops IPv6`);
            assert.match(body, /meta nfproto ipv4 reject with icmp type admin-prohibited\s*$/, `${chain} ends in a reject`);
        }
        const worker = /chain from_worker \{([\s\S]*?)\n  \}/.exec(nft)[1];
        assert.ok(worker.indexOf('@blocked4 reject') < worker.indexOf('tcp dport { 80, 443 } accept'), 'private ranges are refused BEFORE the web-port accepts');
        const relay = /chain from_relay \{([\s\S]*?)\n  \}/.exec(nft)[1];
        assert.equal((relay.match(/ accept$/gm) ?? []).length, 1, 'the relay has one allow rule');
    });

    test('every container is non-privileged, capability-free, read-only, label-marked and never restarted by Docker', () => {
        for (const name of ['browser-inspector', 'api-relay']) {
            const block = name === 'api-relay' ? service('api-relay') : service('browser-inspector');
            assert.ok(block.length > 50, `found ${name}`);
        }
        const anchor = /x-isolated: &isolated\n([\s\S]*?)\n\nservices:/.exec(compose)[1];
        assert.match(anchor, /restart: "no"/);
        assert.match(anchor, /com\.well-applied\.isolation: required/);
        assert.match(anchor, /cap_drop: \[ALL\]/);
        assert.match(anchor, /no-new-privileges:true/);
        assert.match(anchor, /read_only: true/);
        assert.equal((compose.match(/<<: \*isolated/g) ?? []).length, 2, 'both services use the hardened defaults');
        const live = strip(compose);
        assert.ok(!/^\s*privileged:|cap_add|network_mode|pid: host|ipc: host|devices:|docker\.sock|^\s*ports:|^\s*expose:/m.test(live), 'no privileges, host namespaces, devices, published ports or socket mounts');
        const mounts = [...live.matchAll(/^\s+- (\.?\/[^\s]*:[^\s]+)$/gm)].map((m) => m[1]).filter((m) => !m.includes('size=')); // (tmpfs lines carry size=)
        assert.deepEqual(mounts, ['./relay/nginx.conf:/etc/nginx/nginx.conf:ro'], 'the only bind mount is the relay config, read-only');
    });

    test('the worker is on the egress and relay-link networks only; the relay on relay-link and relay-out only', () => {
        const nets = (block) => [...(/networks:\n((?:      .*\n)+)/.exec(block)?.[1] ?? '').matchAll(/^      ([a-z-]+):/gm)].map((m) => m[1]).sort();
        assert.deepEqual(nets(service('browser-inspector')), ['egress', 'relay-link']);
        assert.deepEqual(nets(service('api-relay')), ['relay-link', 'relay-out']);
        assert.match(compose, /relay-link:\n    driver: bridge\n    internal: true/);
    });

    test('the worker has resource limits, IPv6 off, and is told to demand isolation', () => {
        const w = service('browser-inspector');
        for (const k of ['mem_limit: 2g', 'cpus: 2', 'pids_limit: 512', 'shm_size: 512m', 'net.ipv6.conf.all.disable_ipv6: "1"', 'BROWSER_WORKER_REQUIRE_ISOLATION: "1"', 'CAREER_TOOLKIT_URL: http://api-relay:8081']) {
            assert.ok(w.includes(k), k);
        }
    });

    test('the relay has exactly two proxy_pass lines, one upstream, no resolver, and refuses on the raw request line', () => {
        const conf = strip(nginx);
        assert.equal((conf.match(/proxy_pass /g) ?? []).length, 2);
        assert.equal((conf.match(/^\s*server \d/gm) ?? []).length, 1);
        assert.ok(!/resolver|proxy_pass \$|set \$/.test(conf));
        assert.match(conf, /if \(\$request_uri !~ "\^\/api\/worker\/agent-runs\/\(claim\|\[0-9\]\{1,12\}\/result\)\$"\) \{ return 404; \}/);
        assert.match(conf, /if \(\$request_method != POST\) \{ return 405; \}/);
        assert.match(conf, /access_log off;/);
    });

    test('the units order and bind the way fail-closed startup needs', () => {
        const worker = units['career-toolkit-browser-worker.service'];
        assert.match(worker, /^Requires=docker\.service inspector-isolation\.service inspector-isolation-guard\.service$/m);
        assert.match(worker, /^ExecStartPre=\+\/usr\/local\/sbin\/inspector-isolation verify$/m, 'verify runs as root before every start');
        assert.match(worker, /^ExecStart=\/usr\/bin\/docker compose up --no-build --pull never --abort-on-container-exit/m);
        assert.match(worker, /^Restart=on-failure$/m, 'a restart goes through verify again');
        assert.ok(!/^Restart=always/m.test(worker));

        const guard = units['inspector-isolation-guard.service'];
        assert.match(guard, /^BindsTo=inspector-isolation\.service$/m);
        assert.match(guard, /^Restart=always$/m);

        const policy = units['inspector-isolation.service'];
        assert.match(policy, /^Before=docker\.service$/m);
        assert.match(policy, /^Type=oneshot$/m);

        const dropin = units['docker.service.d-inspector-isolation.conf'];
        assert.match(dropin, /^Wants=inspector-isolation\.service$/m);
        assert.ok(!/Requires=|BindsTo=/.test(strip(dropin)), 'Docker (and every other container) never depends on the policy loading');
    });

    test('nothing in the deployment files touches another service\'s firewall, containers or configuration', () => {
        const all = [read('isolation/install.sh'), read('isolation/rollback.sh'), read('isolation/bin/inspector-isolation'), ...Object.values(units)].map(strip).join('\n');
        assert.ok(!/\bufw\b|iptables|ip6tables|tailscale|flush ruleset|nft (flush|delete (?!table))/.test(all), 'no ufw/iptables/tailscale and no flushing');
        assert.ok(!/(open-webui|ollama|application-preparer|silverbullet)/i.test(all));
    });
});
