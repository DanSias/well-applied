import { mkdtemp, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { leverResumeParseException } from '../../src/adapters/index.ts';
import type { AtsName, PrepareInput } from '../../src/contracts.ts';
import {
    prepareApplication,
    type PrepareOptions,
    type PrepareResult,
} from '../../src/prepare.ts';
import type { TestServer } from './server.ts';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
export const RESUME_PATH = join(ROOT, 'fixtures', 'sample-resume.pdf');
const FIXTURES = join(ROOT, 'test', 'fixtures');

export const LEVER_AUTH_QUESTION =
    'are you legally authorized to work in the united states?';
export const GH_SPONSOR_QUESTION =
    'will you now or in the future require sponsorship?';

export async function fixtureHtml(name: string): Promise<string> {
    return readFile(join(FIXTURES, name), 'utf8');
}

export async function syntheticInput(
    url: string,
    over: Partial<PrepareInput> = {},
): Promise<PrepareInput> {
    const base = JSON.parse(
        await readFile(
            join(ROOT, 'fixtures', 'applicant.synthetic.json'),
            'utf8',
        ),
    ) as PrepareInput;
    return {
        ...base,
        applicationUrl: url,
        resumePdfPath: RESUME_PATH,
        ...over,
    };
}

/** Runs the real engine, headless, against a page served by the test server. Runs go to a temp dir. */
export async function runOn(
    server: TestServer,
    path: string,
    ats: AtsName,
    over: { input?: Partial<PrepareInput>; options?: PrepareOptions } = {},
): Promise<PrepareResult & { runsDir: string }> {
    const runsDir = await mkdtemp(join(tmpdir(), 'preparer-test-'));
    const input = await syntheticInput(`${server.origin}${path}`, over.input);
    server.mutations.length = 0;
    // Mirrors production: the Lever adapter grants its (identical) resume-parse exception.
    // Tests pass options.uploadExceptions to override it (e.g. [] to prove the guard blocks).
    const uploadExceptions =
        ats === 'lever'
            ? [leverResumeParseException(server.origin)]
            : undefined;
    const result = await prepareApplication(input, {
        headless: true,
        allowLocalFixture: true,
        ats,
        runsDir,
        ...(uploadExceptions ? { uploadExceptions } : {}),
        ...over.options,
    });
    return { ...result, runsDir };
}
