// The host-side controller (isolation/bin/inspector-isolation), install.sh and rollback.sh, run for real in a temporary
// directory with stand-ins for nft, docker and systemctl. What is under test is the DECISION logic: when the policy counts
// as intact, and what the guard does the moment it is not. (That the policy itself blocks what it should is tested against
// a real kernel in test/isolation/netns-test.sh.)
import { test, describe, before, after, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, existsSync, readdirSync, statSync, chmodSync, appendFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = path.join(ROOT, 'isolation/bin/inspector-isolation');
const REAL_RULES = path.join(ROOT, 'isolation/inspector-isolation.nft');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let sandbox, bin, env, state;

// ---- stand-ins -------------------------------------------------------------------------------------------------
const NFT = `#!/usr/bin/env bash
S="$SANDBOX/kernel"; mkdir -p "$S"
case "$1 $2" in
  "-c -f") [ -r "$3" ] && ! grep -q SYNTAX_ERROR "$3" ;;
  "-f "*)  if grep -q SYNTAX_ERROR "$2"; then echo "syntax error" >&2; exit 1; fi
           # the "kernel" lists the table with a hash of the file it was loaded from, so a different file lists differently
           printf 'table inet inspector_isolation {\\n  chain forward { type filter hook forward priority filter - 10; policy accept; }\\n  chain input { type filter hook input priority filter - 10; policy accept; }\\n  # loaded from %s\\n}\\n' "$(shasum -a 256 "$2" | cut -c1-16)" >"$S/table"
           echo "load" >>"$SANDBOX/nft.log" ;;
  "list table") [ -r "$S/table" ] && cat "$S/table" || { echo "Error: No such file or directory" >&2; exit 1; } ;;
  "delete table") rm -f "$S/table"; echo "delete" >>"$SANDBOX/nft.log" ;;
  "monitor "*|"monitor") exit 0 ;;
  *) echo "nft stub: unexpected $*" >&2; exit 2 ;;
esac
`;
const DOCKER = `#!/usr/bin/env bash
case "$1" in
  ps)   [ -s "$SANDBOX/containers" ] && cat "$SANDBOX/containers" ;;
  kill) shift; echo "kill $*" >>"$SANDBOX/docker.log"; : >"$SANDBOX/containers" ;;
  *) exit 0 ;;
esac
`;
const SYSTEMCTL = `#!/usr/bin/env bash
echo "$*" >>"$SANDBOX/systemctl.log"
`;

before(() => {
    sandbox = mkdtempSync(path.join(tmpdir(), 'isolation-test-'));
    bin = path.join(sandbox, 'bin');
    mkdirSync(bin);
    for (const [name, body] of [['nft', NFT], ['docker', DOCKER], ['systemctl', SYSTEMCTL]]) {
        writeFileSync(path.join(bin, name), body);
        chmodSync(path.join(bin, name), 0o755);
    }
});
after(() => rmSync(sandbox, { recursive: true, force: true }));

beforeEach(() => {
    state = path.join(sandbox, 'state');
    rmSync(state, { recursive: true, force: true });
    rmSync(path.join(sandbox, 'kernel'), { recursive: true, force: true });
    for (const f of ['nft.log', 'docker.log', 'systemctl.log', 'containers']) rmSync(path.join(sandbox, f), { force: true });
    mkdirSync(path.join(sandbox, 'conf'), { recursive: true });
    writeFileSync(path.join(sandbox, 'conf/rules.nft'), 'table inet inspector_isolation { }\n# version one\n');
    env = {
        ...process.env,
        SANDBOX: sandbox,
        PATH: `${bin}:${process.env.PATH}`,
        INSPECTOR_ISOLATION_RULES: path.join(sandbox, 'conf/rules.nft'),
        INSPECTOR_ISOLATION_STATE: state,
        INSPECTOR_GUARD_INTERVAL: '0.1',
    };
});

const run = (...args) => spawnSync('bash', [SCRIPT, ...args], { env, encoding: 'utf8' });
const log = (name) => (existsSync(path.join(sandbox, name)) ? readFileSync(path.join(sandbox, name), 'utf8') : '');
const kernelTable = () => path.join(sandbox, 'kernel/table');

describe('verify: the policy counts as intact only when the kernel holds exactly what was applied', () => {
    test('before anything was applied it is not intact', () => {
        const r = run('verify');
        assert.equal(r.status, 1);
        assert.match(r.stderr, /never applied/);
    });

    test('after apply it is intact, and apply loads in a single step', () => {
        assert.equal(run('apply').status, 0);
        assert.equal(run('verify').status, 0);
        assert.equal(log('nft.log').trim(), 'load');
    });

    test('a deleted table is not intact', () => {
        run('apply');
        rmSync(kernelTable());
        const r = run('verify');
        assert.equal(r.status, 1);
        assert.match(r.stderr, /not loaded/);
    });

    test('a table that was changed in place is not intact', () => {
        run('apply');
        appendFileSync(kernelTable(), '  # someone added a rule\n');
        assert.match(run('verify').stderr, /differs from what was applied/);
    });

    test('a rules file edited after it was applied is not intact', () => {
        run('apply');
        appendFileSync(path.join(sandbox, 'conf/rules.nft'), '# edited\n');
        assert.match(run('verify').stderr, /rules file changed/);
    });

    test('a table whose base chains moved off priority -10 is not intact even if the hashes were forged to match', () => {
        run('apply');
        const forged = readFileSync(kernelTable(), 'utf8').replace(/priority filter - 10/g, 'priority filter');
        writeFileSync(kernelTable(), forged);
        // recompute the recorded fingerprint over the forged listing
        const hash = spawnSync('bash', ['-c', `printf '%s\\n' "$(cat ${kernelTable()})" | shasum -a 256 | cut -d' ' -f1`], { encoding: 'utf8' }).stdout.trim();
        const [fileHash] = readFileSync(path.join(state, 'fingerprint'), 'utf8').split('\n');
        writeFileSync(path.join(state, 'fingerprint'), `${fileHash}\n${hash}\n`);
        assert.match(run('verify').stderr, /priority -10/);
    });

    test('a rules file that does not pass nft -c is never loaded, and the previous policy stays in force', () => {
        run('apply');
        const before = readFileSync(kernelTable(), 'utf8');
        writeFileSync(path.join(sandbox, 'conf/rules.nft'), 'SYNTAX_ERROR\n');
        assert.notEqual(run('apply').status, 0);
        assert.equal(readFileSync(kernelTable(), 'utf8'), before, 'the loaded policy was not replaced');
        assert.equal(log('nft.log').trim(), 'load', 'no second load happened');
    });

    test('remove deletes the table and the fingerprint', () => {
        run('apply');
        run('remove');
        assert.equal(existsSync(kernelTable()), false);
        assert.match(run('verify').stderr, /never applied/);
    });
});

describe('guard: when isolation is lost the worker is killed first, then the policy restored', () => {
    const start = () => spawn('bash', [SCRIPT, 'guard'], { env, stdio: 'ignore' });

    test('while the policy is intact it does nothing at all', async () => {
        run('apply');
        writeFileSync(path.join(sandbox, 'containers'), 'abc123\n');
        const g = start();
        await sleep(800);
        g.kill();
        assert.equal(log('docker.log'), '');
        assert.equal(log('systemctl.log'), '');
    });

    test('a deleted table kills the labelled containers, stops the unit and restores the policy', async () => {
        run('apply');
        writeFileSync(path.join(sandbox, 'containers'), 'abc123\ndef456\n');
        const g = start();
        await sleep(400);
        rmSync(kernelTable());
        await sleep(1200);
        g.kill();

        assert.match(log('docker.log'), /kill abc123 def456/, 'both containers killed');
        assert.match(log('systemctl.log'), /stop --no-block career-toolkit-browser-worker\.service/);
        assert.equal(existsSync(kernelTable()), true, 'the policy is back');
        assert.equal(run('verify').status, 0, 'and verifies');
    });

    test('a table altered in place is contained the same way', async () => {
        run('apply');
        writeFileSync(path.join(sandbox, 'containers'), 'abc123\n');
        const g = start();
        await sleep(400);
        appendFileSync(kernelTable(), '  # tampered\n');
        await sleep(1200);
        g.kill();

        assert.match(log('docker.log'), /kill abc123/);
        assert.equal(run('verify').status, 0);
    });

    test('a guard that starts with no policy at all contains the worker immediately rather than waiting', async () => {
        writeFileSync(path.join(sandbox, 'containers'), 'abc123\n');
        const g = start();
        await sleep(700);
        g.kill();

        assert.match(log('docker.log'), /kill abc123/);
        assert.equal(run('verify').status, 0, 'and it applied the policy');
    });

    test('it only touches containers carrying the isolation label', async () => {
        // The stub docker only lists what it is given for the label filter; the real filter is asserted here on the command line.
        const src = readFileSync(SCRIPT, 'utf8');
        assert.match(src, /ps -q --filter "label=\$LABEL"/);
        assert.match(src, /LABEL=\$\{INSPECTOR_ISOLATION_LABEL:-com\.well-applied\.isolation=required\}/);
    });
});

describe('install.sh and rollback.sh', () => {
    const install = (root, ...extra) => spawnSync('bash', [path.join(ROOT, 'isolation/install.sh'), '--user', 'daniel', '--dir', '/home/daniel/career-toolkit-browser-worker', '--root', root, ...extra], { encoding: 'utf8' });
    const rollback = (root, ...extra) => spawnSync('bash', [path.join(ROOT, 'isolation/rollback.sh'), '--root', root, ...extra], { encoding: 'utf8' });
    const files = (root) => {
        const out = [];
        const walk = (d) => readdirSync(d, { withFileTypes: true }).forEach((e) => (e.isDirectory() ? walk(path.join(d, e.name)) : out.push(path.join(d, e.name).slice(root.length))));
        walk(root);
        return out.sort();
    };

    test('installs the script, the policy, three units and the Docker drop-in, with correct modes', () => {
        const root = mkdtempSync(path.join(tmpdir(), 'install-'));
        try {
            const r = install(root);
            assert.equal(r.status, 0, r.stderr);
            assert.deepEqual(files(root).filter((f) => !f.endsWith('README.md')), [
                '/etc/inspector-isolation/inspector-isolation.nft',
                '/etc/systemd/system/career-toolkit-browser-worker.service',
                '/etc/systemd/system/docker.service.d/inspector-isolation.conf',
                '/etc/systemd/system/inspector-isolation-guard.service',
                '/etc/systemd/system/inspector-isolation.service',
                '/usr/local/sbin/inspector-isolation',
            ]);
            assert.equal(statSync(path.join(root, 'usr/local/sbin/inspector-isolation')).mode & 0o777, 0o755);
            assert.equal(statSync(path.join(root, 'etc/inspector-isolation/inspector-isolation.nft')).mode & 0o022, 0, 'the policy is not group/world writable');
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    test('renders the worker unit for the given user and directory, leaving no placeholder behind', () => {
        const root = mkdtempSync(path.join(tmpdir(), 'install-'));
        try {
            install(root);
            const unit = readFileSync(path.join(root, 'etc/systemd/system/career-toolkit-browser-worker.service'), 'utf8');
            assert.match(unit, /^User=daniel$/m);
            assert.match(unit, /^WorkingDirectory=\/home\/daniel\/career-toolkit-browser-worker$/m);
            assert.ok(!unit.includes('@'), 'no @PLACEHOLDER@ left');
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    test('refuses a user or directory with spaces or shell characters', () => {
        const root = mkdtempSync(path.join(tmpdir(), 'install-'));
        try {
            const r = spawnSync('bash', [path.join(ROOT, 'isolation/install.sh'), '--user', 'dan; rm -rf /', '--dir', '/x', '--root', root], { encoding: 'utf8' });
            assert.notEqual(r.status, 0);
            assert.equal(files(root).length, 0, 'nothing was written');
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    test('is idempotent, and a dry run changes nothing', () => {
        const root = mkdtempSync(path.join(tmpdir(), 'install-'));
        try {
            assert.equal(spawnSync('bash', [path.join(ROOT, 'isolation/install.sh'), '--user', 'daniel', '--dir', '/x', '--root', root, '--dry-run'], { encoding: 'utf8' }).status, 0);
            assert.equal(files(root).length, 0);
            install(root);
            const first = files(root);
            install(root);
            assert.deepEqual(files(root), first);
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    test('rollback removes everything it installed, twice over, and leaves other files alone', () => {
        const root = mkdtempSync(path.join(tmpdir(), 'install-'));
        try {
            mkdirSync(path.join(root, 'etc/systemd/system'), { recursive: true });
            writeFileSync(path.join(root, 'etc/systemd/system/other.service'), 'keep me');
            install(root);
            assert.equal(rollback(root, '--purge').status, 0);
            assert.equal(rollback(root, '--purge').status, 0);
            assert.deepEqual(files(root), ['/etc/systemd/system/other.service']);
        } finally {
            rmSync(root, { recursive: true, force: true });
        }
    });

    test('the installer never starts, builds or stops a container, and never restarts Docker', () => {
        // Only what the script RUNS: comments and the instructions it prints (the MSG heredoc) are not commands.
        const src = readFileSync(path.join(ROOT, 'isolation/install.sh'), 'utf8').replace(/<<MSG[\s\S]*?\nMSG\n/g, '').replace(/^\s*#.*$/gm, '');
        assert.ok(!/docker\s+(compose|run|start|stop|restart|build|kill|rm)/.test(src), 'no docker commands');
        assert.ok(!/systemctl\s+(restart|reload-or-restart)\s+docker/.test(src));
        assert.ok(!/systemctl[^\n]*\b(start|enable --now)\b[^\n]*career-toolkit-browser-worker/.test(src), 'the worker unit is enabled, never started');
        assert.ok(!/flush ruleset|nft\s+flush|iptables|ufw/.test(src), 'touches no firewall but its own table');
    });
});
