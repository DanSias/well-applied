// Path rules from the dated robots snapshot. A path a host's robots.txt disallows is refused BEFORE it is requested. Pure.
const toRegex = (rule) => new RegExp(`^${rule.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*')}`);

export function createRobots(snapshot) {
    const rules = snapshot.rules.map((r) => ({ host: r.host.toLowerCase(), patterns: r.disallow.map((d) => ({ raw: d, re: toRegex(d.replace(/\$$/, '')) })) }));

    return {
        /** @returns {string|null} the matching Disallow rule, or null when the path is allowed (or the host has no snapshot) */
        disallows(hostname, pathAndQuery) {
            const host = hostname.toLowerCase();
            const r = rules.find((x) => host === x.host || host.endsWith(`.${x.host}`));

            return r?.patterns.find((p) => p.re.test(pathAndQuery))?.raw ?? null;
        },
    };
}
