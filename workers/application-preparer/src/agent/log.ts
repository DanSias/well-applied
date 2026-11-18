// The agent's only logger. It takes a fixed event name and a small bag of non-sensitive scalars, so nothing that is
// applicant data, a token, resume bytes or report content can be logged by accident: callers pass numbers and
// enumerated words, never bodies, headers, URLs or values.
export type Log = (
    event: string,
    facts?: Record<string, string | number | boolean | null>,
) => void;

export const consoleLog: Log = (event, facts = {}) => {
    const parts = Object.entries(facts).map(([k, v]) => `${k}=${String(v)}`);
    process.stderr.write(
        `[agent] ${event}${parts.length ? ` ${parts.join(' ')}` : ''}\n`,
    );
};

export const silentLog: Log = () => {};
