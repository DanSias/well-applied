// The ONLY place a production record is read in a live run, and it reads exactly one column of one table by id.
//   - the file is opened read-only, and the connection is further set query_only, so a write is refused by SQLite itself
//   - one prepared SELECT, fixed in this file; no query text ever comes from a caller
//   - nothing but `source_url` is selected (never title, company, description, location or any profile, resume, answer or application table)
//   - no handle is exposed; the only method is getSourceUrl(id)
import { DatabaseSync } from 'node:sqlite';

const SQL = 'SELECT source_url FROM job_postings WHERE id = ?';

export function openSourceReader(dbPath) {
    const db = new DatabaseSync(dbPath, { readOnly: true });
    db.exec('PRAGMA query_only = 1');
    const stmt = db.prepare(SQL);

    return {
        getSourceUrl(jobPostingId) {
            if (!Number.isInteger(jobPostingId) || jobPostingId < 1) throw new Error('a job posting id must be a positive integer');
            const row = stmt.get(jobPostingId);
            if (!row || typeof row.source_url !== 'string' || row.source_url === '') throw new Error(`job posting ${jobPostingId} has no source URL`);

            return row.source_url;
        },
        close: () => db.close(),
    };
}
