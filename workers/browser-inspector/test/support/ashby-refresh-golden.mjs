// Offline: rewrites the committed golden for the NerdWallet fixture from the current mapper. Review the diff before committing.
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { adaptResponses } from '../../src/providers/ashby/index.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'ashby');
const fx = (n) => JSON.parse(readFileSync(path.join(dir, n), 'utf8'));
const r = adaptResponses({
    url: 'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application',
    organizationResponse: fx('nerdwallet-org.response.json'),
    jobPostingResponse: fx('nerdwallet-job.response.json'),
});
if (!r.ok) throw new Error(`refused: ${r.stage}/${r.reason}`);
writeFileSync(path.join(dir, 'expected', 'nerdwallet-258.controls.json'), JSON.stringify(r.result, null, 2) + '\n');
console.log('golden written:', r.result.controls.length, 'controls');
