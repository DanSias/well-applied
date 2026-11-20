// Validates, and builds, the only request bodies this adapter would ever allow. It decides; it never sends. A future transport
// calls buildRequest() and sends exactly what it returns, so there is one place that says what may leave.
//
// A body is accepted only if ALL of these hold: a single plain object (never a batch array); exactly the keys
// operationName, variables and query; an operationName from the approved list; a query whose whitespace-collapsed SHA-256
// equals the pinned hash for THAT operation; and variables that are exactly the declared ones, each of the declared kind and
// length. Nothing about a document is inferred from its name: the hash is the proof, and diagnose() only explains a refusal.
import { APPROVED_OPERATIONS, MAX_DOCUMENT_CHARS, hashDocument } from './documents.js';
import { SLUG, UUID } from './target.js';

export const MAX_REQUEST_CHARS = 12000;
const BODY_KEYS = ['operationName', 'variables', 'query'];

export class AshbyRequestError extends Error {
    constructor(reason, detail = null) {
        super(detail ? `${reason}: ${detail}` : reason);
        this.reason = reason;
        this.detail = detail;
    }
}

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v) && [Object.prototype, null].includes(Object.getPrototypeOf(v));
const refuse = (reason, detail = null) => ({ ok: false, reason, detail });

/** Why a document is not the pinned one, as hints for a person reading a refusal. Never used to ACCEPT anything. */
export function diagnoseDocument(query, pinned = null) {
    const hints = [];
    if (/\bmutation\b/.test(query)) hints.push('contains_mutation');
    if (/\bsubscription\b/.test(query)) hints.push('contains_subscription');
    if ((query.match(/\b(query|mutation|subscription)\s+[A-Za-z_]\w*/g) ?? []).length > 1) hints.push('multiple_operations');
    if (/@\s*[A-Za-z_]/.test(query)) hints.push('contains_directive');
    if (/#/.test(query)) hints.push('contains_comment');
    if (/__schema|__type\b/.test(query)) hints.push('contains_introspection');
    const aliases = (q) => (q.match(/(?<![$\w])[A-Za-z_]\w*\s*:\s*[A-Za-z_]\w*\s*[({]/g) ?? []).length;
    if (pinned !== null && aliases(query) !== aliases(pinned)) hints.push('alias_count_differs');

    return hints;
}

function checkVariable(name, spec, value) {
    if (typeof value !== 'string') return `${name} must be a string`;
    if (spec.kind === 'slug') return SLUG.test(value) ? null : `${name} is not a valid hosted jobs page name`;
    if (spec.kind === 'uuid') return UUID.test(value) ? null : `${name} is not a valid job posting id`;
    if (spec.kind === 'enum') return spec.values.includes(value) ? null : `${name} is not an approved value`;

    return `${name} has an unknown kind`;
}

/** @returns {{ok: true, operationName: string, variables: object} | {ok: false, reason: string, detail: string|null}} */
export function validateRequestBody(body) {
    if (Array.isArray(body)) return refuse('batch_not_allowed');
    if (!isPlainObject(body)) return refuse('not_an_object');
    const keys = Object.keys(body);
    if (keys.length !== BODY_KEYS.length || !BODY_KEYS.every((k) => keys.includes(k))) return refuse('unexpected_keys', keys.filter((k) => !BODY_KEYS.includes(k)).join(',') || 'missing keys');

    const { operationName, variables, query } = body;
    if (typeof operationName !== 'string' || !Object.hasOwn(APPROVED_OPERATIONS, operationName)) return refuse('unknown_operation');
    const approved = APPROVED_OPERATIONS[operationName];

    if (typeof query !== 'string') return refuse('document_not_a_string');
    if (query.length > MAX_DOCUMENT_CHARS) return refuse('document_too_large');
    if (hashDocument(query) !== approved.sha256) return refuse('document_not_pinned', diagnoseDocument(query, approved.query).join(',') || 'differs from the pinned document');

    if (!isPlainObject(variables)) return refuse('variables_not_an_object');
    const given = Object.keys(variables);
    const declared = Object.keys(approved.variables);
    const extra = given.filter((k) => !declared.includes(k));
    if (extra.length > 0) return refuse('unexpected_variable', extra.join(','));
    const missing = declared.filter((k) => !given.includes(k));
    if (missing.length > 0) return refuse('missing_variable', missing.join(','));
    for (const name of declared) {
        const problem = checkVariable(name, approved.variables[name], variables[name]);
        if (problem !== null) return refuse('invalid_variable', problem);
    }
    if (JSON.stringify(body).length > MAX_REQUEST_CHARS) return refuse('request_too_large');

    return { ok: true, operationName, variables };
}

/** The one way to produce a request body. Throws AshbyRequestError rather than ever returning something unvalidated. */
export function buildRequest(operationName, variables) {
    const body = { operationName, variables, query: Object.hasOwn(APPROVED_OPERATIONS, operationName) ? APPROVED_OPERATIONS[operationName].query : undefined };
    const verdict = validateRequestBody(body);
    if (!verdict.ok) throw new AshbyRequestError(verdict.reason, verdict.detail);

    return body;
}
