// Structural test: the worker has no arbitrary click / key-press /
// submission capability, and each mutating Playwright primitive is
// confined to the one module that is allowed to use it. This is a
// source-level check, not a proof (see README "Safety controls and
// limits"), but it makes adding a capability a deliberate, test-changing
// act instead of an accident.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', 'src');

function sources(dir = SRC): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
        e.isDirectory()
            ? sources(join(dir, e.name))
            : e.name.endsWith('.ts')
              ? [join(dir, e.name)]
              : [],
    );
}

// Forbidden everywhere except guard-init.ts (which neutralises, never invokes, them).
const FORBIDDEN = [
    /\.click\s*\(/,
    /\.dblclick\s*\(/,
    /\.tap\s*\(/,
    /\.press\s*\(/,
    /\.type\s*\(/,
    /\.pressSequentially\s*\(/,
    /\.keyboard\b/,
    /\.mouse\b/,
    /\.touchscreen\b/,
    /\.dragTo\s*\(/,
    /\.dispatchEvent\s*\(/,
    /\.submit\s*\(/,
    /requestSubmit/,
    /\.requestSubmit/,
    /evaluateHandle/,
    /\.\$eval/,
    /\.\$\$eval/,
    /new Function\b/,
    /\beval\s*\(/,
    /\.setContent\s*\(/,
    /\.route\.fulfill|route\.fulfill/,
    /\.request\.(post|put|patch|delete|fetch)\b/,
    /APIRequestContext|request\.newContext/,
    /\.waitForNavigation|\.goBack|\.goForward|\.reload\s*\(/,
    /\.hover\s*\(/,
    /\.focus\s*\(/,
];

// Primitive -> the only modules allowed to use it.
const CONFINED: Array<[RegExp, string[]]> = [
    [/\.fill\s*\(/, ['fill.ts']],
    [/\.selectOption\s*\(/, ['fill.ts']],
    [/\.check\s*\(/, ['fill.ts']],
    [/\.setInputFiles\s*\(/, ['fill.ts']],
    [/\.goto\s*\(/, ['prepare.ts']],
    [/\.evaluate\s*\(/, ['discover.ts', 'verify.ts', 'evidence.ts']],
    [/\.screenshot\s*\(/, ['evidence.ts']],
    [/\.route\s*\(/, ['guard.ts']],
];

describe('absent capabilities', () => {
    for (const file of sources()) {
        const name = relative(SRC, file);
        if (name === 'guard-init.ts') continue;
        const text = readFileSync(file, 'utf8')
            .replace(/\/\/.*$/gm, '')
            .replace(/\/\*[\s\S]*?\*\//g, '');

        it(`${name} contains no click/key/submit primitive`, () => {
            for (const pattern of FORBIDDEN)
                assert.doesNotMatch(text, pattern, `${name} uses ${pattern}`);
        });

        it(`${name} only uses confined primitives where allowed`, () => {
            for (const [pattern, allowed] of CONFINED) {
                if (pattern.test(text))
                    assert.ok(
                        allowed.includes(name.split('/').pop()!),
                        `${name} uses ${pattern}; allowed only in ${allowed.join(', ')}`,
                    );
            }
        });
    }

    it('exposes no generic action API from fill.ts', () => {
        let text = '';
        try {
            text = readFileSync(join(SRC, 'fill.ts'), 'utf8');
        } catch {
            return; // fill.ts not written yet
        }
        const exported = [
            ...text.matchAll(/export (?:async )?function (\w+)/g),
        ].map((m) => m[1]);
        for (const fn of exported)
            assert.match(
                fn!,
                /^(fill|select|choose|upload)\w*$/,
                `unexpected export ${fn}`,
            );
    });
});
