// Wires a LIVE run: read the source records read-only, build the adapter, and hand everything to the runner core, which validates the manifest hash and
// the separate authorization before it does anything. Imported only by the CLI's `live` command.
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runBatch } from '../runner/batch.js';
import { createLiveAdapter } from './adapter.js';
import { openSourceReader } from './source-db.js';

const here = path.dirname(fileURLToPath(import.meta.url));
export const loadRobotsSnapshot = () => JSON.parse(readFileSync(path.join(here, 'robots-snapshot.json'), 'utf8'));

/** @param {{manifestBytes: Buffer, expectSha256: string, authorization: object, sourceDb: string, clock: object, signal?: AbortSignal, onCheckpoint?: Function, reader?: object, testing?: object}} p */
export async function runLive({ manifestBytes, expectSha256, authorization, sourceDb, clock, signal, onCheckpoint, reader, testing }) {
    // The core validates the hash and the authorization before this adapter does anything; the reader is only opened once they pass.
    let opened = null;
    const lazyReader = { getSourceUrl: (id) => (reader ?? (opened ??= openSourceReader(sourceDb))).getSourceUrl(id) };
    const adapter = createLiveAdapter({ reader: lazyReader, robotsSnapshot: loadRobotsSnapshot(), signal, testing });
    try {
        return await runBatch({ manifestBytes, expectSha256, mode: 'live', authorization, clock, signal, onCheckpoint, getUrl: adapter.getUrl, resolve: adapter.resolve });
    } finally {
        opened?.close();
    }
}
