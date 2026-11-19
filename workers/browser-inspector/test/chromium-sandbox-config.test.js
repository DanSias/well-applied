// Chromium's own sandbox is off unless requested (Playwright adds --no-sandbox), and under Docker's default seccomp profile it
// cannot start at all. These tests pin the configuration that gets it working without dropping seccomp, and the properties of
// the custom profile. That the sandbox is really IN FORCE inside the container is checked by isolation/validate-hardening.sh
// (it needs the real image on the real host).
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromiumSandboxRequested } from '../src/browser.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (f) => readFileSync(path.join(ROOT, f), 'utf8');
const profile = JSON.parse(read('isolation/chromium-seccomp.json'));
const FOUR = ['chroot', 'clone', 'setns', 'unshare'];

describe('the worker asks for the sandbox explicitly', () => {
    test('only BROWSER_WORKER_CHROMIUM_SANDBOX=1 requests it', () => {
        assert.equal(chromiumSandboxRequested({}), false);
        assert.equal(chromiumSandboxRequested({ BROWSER_WORKER_CHROMIUM_SANDBOX: '0' }), false);
        assert.equal(chromiumSandboxRequested({ BROWSER_WORKER_CHROMIUM_SANDBOX: 'true' }), false);
        assert.equal(chromiumSandboxRequested({ BROWSER_WORKER_CHROMIUM_SANDBOX: '1' }), true);
    });

    test('the launch passes the choice to Playwright as an explicit boolean, never leaving its --no-sandbox default in place', () => {
        const src = read('src/browser.js');
        assert.match(src, /chromiumSandbox: chromiumSandboxRequested\(env\)/);
    });

    test('compose requests the sandbox and the custom profile, and never runs seccomp unconfined or privileged', () => {
        const compose = read('compose.yaml').replace(/^\s*#.*$/gm, '');
        assert.match(compose, /BROWSER_WORKER_CHROMIUM_SANDBOX: "1"/);
        assert.match(compose, /- "seccomp=\.\/isolation\/chromium-seccomp\.json"/);
        assert.ok(!/unconfined|^\s*privileged:|apparmor/im.test(compose), 'no unconfined seccomp/apparmor, no privileged');
    });
});

describe('the custom seccomp profile', () => {
    const rules = profile.syscalls;
    const unconditionalAllow = rules.filter((r) => r.action === 'SCMP_ACT_ALLOW' && !r.includes && !r.excludes && !r.args);

    test('keeps Docker\'s default action: everything not listed is refused', () => {
        assert.equal(profile.defaultAction, 'SCMP_ACT_ERRNO');
        assert.ok(rules.length > 40, 'it is still the full default profile, not a short allow-list');
    });

    test('allows exactly clone, unshare, setns and chroot to unprivileged processes, in one rule', () => {
        const mine = unconditionalAllow.filter((r) => FOUR.every((n) => r.names.includes(n)));
        assert.equal(mine.length, 1);
        assert.deepEqual([...mine[0].names].sort(), FOUR);
        // nowhere else, in any form (no capability-gated duplicate, no argument-filtered clone left behind)
        for (const n of FOUR) {
            assert.equal(rules.filter((r) => r.names.includes(n)).length, 1, `${n} appears in exactly one rule`);
        }
    });

    test('leaves clone3 answering ENOSYS exactly as the default does, so libc falls back to the allowed clone', () => {
        const clone3 = rules.filter((r) => r.names.includes('clone3'));
        assert.ok(clone3.some((r) => r.action === 'SCMP_ACT_ERRNO' && r.errnoRet === 38));
        assert.ok(!unconditionalAllow.some((r) => r.names.includes('clone3')));
    });

    test('never allows an administrative syscall unconditionally', () => {
        const dangerous = ['mount', 'umount', 'umount2', 'pivot_root', 'bpf', 'kexec_load', 'kexec_file_load', 'init_module', 'finit_module', 'delete_module', 'reboot', 'swapon', 'swapoff', 'settimeofday', 'clock_settime', 'acct', 'iopl', 'ioperm', 'open_by_handle_at', 'keyctl', 'add_key', 'request_key', 'userfaultfd', 'quotactl', 'sethostname', 'setdomainname', 'vhangup', 'lookup_dcookie', 'nfsservctl'];
        const allowedNames = new Set(unconditionalAllow.flatMap((r) => r.names));
        assert.deepEqual(dangerous.filter((n) => allowedNames.has(n)), []);
    });
});

describe('make-chromium-seccomp.py changes only those four syscalls', () => {
    test('on a synthetic default profile, exactly the gated names move and everything else is untouched', () => {
        const dir = mkdtempSync(path.join(tmpdir(), 'seccomp-'));
        try {
            const def = {
                defaultAction: 'SCMP_ACT_ERRNO',
                syscalls: [
                    { names: ['read', 'write', 'close'], action: 'SCMP_ACT_ALLOW' },
                    { names: ['mount', 'bpf', 'clone', 'clone3', 'setns', 'unshare', 'umount2'], action: 'SCMP_ACT_ALLOW', includes: { caps: ['CAP_SYS_ADMIN'] } },
                    { names: ['chroot'], action: 'SCMP_ACT_ALLOW', includes: { caps: ['CAP_SYS_CHROOT'] } },
                    { names: ['clone'], action: 'SCMP_ACT_ALLOW', args: [{ index: 0, value: 2114060288, op: 'SCMP_CMP_MASKED_EQ' }], excludes: { caps: ['CAP_SYS_ADMIN'], arches: ['s390'] } },
                    { names: ['clone3'], action: 'SCMP_ACT_ERRNO', errnoRet: 38, excludes: { caps: ['CAP_SYS_ADMIN'] } },
                ],
            };
            writeFileSync(path.join(dir, 'default.json'), JSON.stringify(def));
            const r = spawnSync('python3', [path.join(ROOT, 'isolation/make-chromium-seccomp.py'), path.join(dir, 'default.json'), path.join(dir, 'out.json')], { encoding: 'utf8' });
            assert.equal(r.status, 0, r.stderr);
            const out = JSON.parse(readFileSync(path.join(dir, 'out.json'), 'utf8'));

            const gated = out.syscalls.find((x) => x.includes?.caps?.includes('CAP_SYS_ADMIN'));
            assert.deepEqual(gated.names, ['mount', 'bpf', 'clone3', 'umount2'], 'mount, bpf, umount2 and clone3 stay capability-gated');
            assert.ok(out.syscalls.find((x) => x.names.length === 1 && x.names[0] === 'read' || x.names.includes('read')), 'unrelated rules untouched');
            assert.ok(!out.syscalls.some((x) => x.args), 'the argument-filtered clone rule is gone');
            assert.ok(!out.syscalls.some((x) => x.names.includes('chroot') && x.includes), 'chroot is no longer capability-gated');
            const last = out.syscalls.at(-1);
            assert.deepEqual([...last.names].sort(), FOUR);
            assert.equal(last.includes, undefined);
            assert.ok(out.syscalls.some((x) => x.names.includes('clone3') && x.action === 'SCMP_ACT_ERRNO'));
        } finally {
            rmSync(dir, { recursive: true, force: true });
        }
    });
});
