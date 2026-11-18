// Serves the fixture forms locally for the Laravel end-to-end script. Prints its origin to the file named by argv[2]
// and runs until killed. It serves test/fixtures/* only; nothing live is involved.
import { writeFileSync } from 'node:fs';
import { startServer } from './support/server.ts';

const server = await startServer();
writeFileSync(process.argv[2] ?? 'fixture-origin.txt', server.origin);
process.on('SIGTERM', () => server.close().then(() => process.exit(0)));
process.on('SIGINT', () => server.close().then(() => process.exit(0)));
setInterval(() => {}, 1 << 30);
