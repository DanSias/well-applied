#!/usr/bin/env python3
"""Derives isolation/chromium-seccomp.json from Docker's default seccomp profile.

Usage: make-chromium-seccomp.py DEFAULT.json OUTPUT.json

Why: Chromium's own sandbox (the one that confines each renderer in its own user, pid and network namespaces with a seccomp
filter) needs four syscalls that Docker's default profile allows only to a container holding CAP_SYS_ADMIN:

  clone    (with CLONE_NEWUSER etc.: the default profile blocks those flags without CAP_SYS_ADMIN)
  unshare  setns   (create or join namespaces)
  chroot   (the sandbox chroots into an empty directory inside its new user namespace; the default allows it only with CAP_SYS_CHROOT)

Playwright launches Chromium with --no-sandbox unless told otherwise, and with Docker's default profile `chromiumSandbox: true`
fails ("Chromium sandboxing failed!"). Dropping seccomp entirely (`seccomp=unconfined`) would also work and is much weaker. This
profile changes ONLY those four syscalls, for unprivileged processes, and leaves every other rule of the default profile as it
is (mount, bpf, ptrace restrictions, keyctl, module loading, reboot, ... stay blocked or capability-gated). It does not give a
container any capability: it only lets a process create a user namespace of its own, which Chromium does for every renderer.

The cost, stated plainly: user namespaces enlarge the kernel attack surface a compromised process can reach (a kernel bug in
namespace code becomes reachable from inside the container). The container still has no capabilities, no-new-privileges, a
read-only root and the host network policy; the alternative is a browser with NO sandbox at all.

Provenance: derived from github.com/moby/profiles seccomp/default.json (Apache-2.0). Docker's built-in profile can change between
releases and this file does not follow it: re-run this script against the new default when upgrading Docker, and diff.
"""
import copy
import json
import sys

UNCONDITIONAL = ["clone", "setns", "unshare", "chroot"]


def derive(default):
    out = copy.deepcopy(default)
    rules = out["syscalls"]

    # 1. take the four names out of the capability-gated allow rules
    for rule in rules:
        if rule.get("action") == "SCMP_ACT_ALLOW" and rule.get("includes", {}).get("caps"):
            rule["names"] = [n for n in rule["names"] if n not in UNCONDITIONAL]

    # 2. drop the argument-filtered clone rules (they exist only to block CLONE_NEWUSER and friends without CAP_SYS_ADMIN)
    rules[:] = [r for r in rules if not (r.get("names") == ["clone"] and r.get("args") is not None)]
    rules[:] = [r for r in rules if r.get("names")]

    # 3. add one unconditional rule for the four syscalls. clone3 is deliberately left as the default has it (ENOSYS for a
    #    container without CAP_SYS_ADMIN) so that libc falls back to clone, which is allowed above.
    assert any(r.get("names") == ["clone3"] and r.get("action") == "SCMP_ACT_ERRNO" for r in rules), "unexpected default profile"
    rules.append({
        "names": UNCONDITIONAL,
        "action": "SCMP_ACT_ALLOW",
        "comment": "Chromium sandbox: namespaces and chroot for unprivileged processes (Docker's default allows these only with CAP_SYS_ADMIN / CAP_SYS_CHROOT).",
    })

    return out


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    with open(sys.argv[1]) as f:
        default = json.load(f)
    derived = derive(default)
    with open(sys.argv[2], "w") as f:
        json.dump(derived, f, indent=2)
        f.write("\n")
    print(f"{len(default['syscalls'])} syscall rules -> {len(derived['syscalls'])}")
