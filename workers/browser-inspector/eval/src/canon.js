// Deterministic serialisation and hashing. Nothing here reads a clock, a locale or the environment.
import { createHash } from 'node:crypto';

export const sha256 = (data) => createHash('sha256').update(data).digest('hex');

const sortKeys = (v) => (Array.isArray(v) ? v.map(sortKeys) : v && typeof v === 'object' ? Object.fromEntries(Object.keys(v).sort().map((k) => [k, sortKeys(v[k])])) : v);

/** JSON with every object's keys sorted, two-space indent and a trailing newline: the same value is always the same bytes. */
export const stableJson = (value) => `${JSON.stringify(sortKeys(value), null, 2)}\n`;

/** Four decimals; null stays null. A ratio is only ever computed from integers, so this is the single rounding point. */
export const ratio = (numerator, denominator) => (denominator > 0 ? Math.round((numerator / denominator) * 10000) / 10000 : null);
