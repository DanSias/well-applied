// Launches Chromium with the sandbox REQUESTED (SANDBOX=true, the default here) or with Playwright's default options
// (SANDBOX=default) and reports, from /proc, whether the sandbox is really in force: the command lines, and each Chromium
// child's seccomp mode, capabilities and namespaces. Prints one JSON line with `sandboxActive`; exits 1 if it was requested
// and is not active. Run inside the container whose hardening is being judged (isolation/validate-hardening.sh).
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { readFileSync, readlinkSync } from 'node:fs';
const mode = process.env.SANDBOX ?? 'true';
const out = { mode };
let browser;
try {
  browser = await chromium.launch({ headless: true, timeout: 20000, ...(mode === 'true' ? { chromiumSandbox: true } : {}) });
  const page = await browser.newPage();
  await page.setContent('<p>hello</p>');
  await page.evaluate(() => 1 + 1);
  const procs = execSync('ps -eo pid,args').toString().split('\n').filter((l) => /headless_shell|chrom/.test(l) && !/ps -eo/.test(l));
  out.noSandboxFlag = procs.some((l) => /--no-sandbox/.test(l));
  out.children = procs.map((l) => {
    const pid = Number(l.trim().split(/\s+/)[0]);
    const type = /--type=([a-z-]+)/.exec(l)?.[1] ?? 'browser';
    const status = (() => { try { return readFileSync(`/proc/${pid}/status`, 'utf8'); } catch { return ''; } })();
    const f = (k) => new RegExp(`^${k}:\\s*(\\S+)`, 'm').exec(status)?.[1];
    const ns = (n) => { try { return readlinkSync(`/proc/${pid}/ns/${n}`).replace(/\D/g, ''); } catch { return '?'; } };
    return { type, seccomp: f('Seccomp'), noNewPrivs: f('NoNewPrivs'), capEff: f('CapEff'), userNs: ns('user'), pidNs: ns('pid'), netNs: ns('net') };
  }).filter((c) => c.type !== 'browser' || true);
  out.launched = true;
  // Evidence that Chromium's sandbox is IN FORCE, not merely that Chromium started: no --no-sandbox on any process, a
  // renderer that lives in its own user and pid namespaces (not the browser's), behind its own seccomp filter, with no
  // capabilities.
  const browserProc = out.children.find((c) => c.type === 'browser');
  const renderer = out.children.find((c) => c.type === 'renderer');
  out.sandboxActive = Boolean(
    !out.noSandboxFlag && browserProc && renderer &&
    renderer.userNs !== browserProc.userNs && renderer.pidNs !== browserProc.pidNs &&
    renderer.seccomp === '2' && /^0+$/.test(renderer.capEff),
  );
} catch (e) {
  out.launched = false;
  out.error = String(e.message).split('\n').filter(Boolean).slice(0, 6).join(' / ');
} finally {
  await browser?.close().catch(() => {});
}
console.log(JSON.stringify(out));
// SANDBOX=default is the negative control (Playwright's default launch): there the answer is expected to be "not active".
process.exit(mode === 'true' ? (out.sandboxActive ? 0 : 1) : 0);
