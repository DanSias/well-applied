// A local stand-in for an S3 upload bucket, on its OWN origin. It records what it
// received without keeping any values: only the form-field NAMES and the SHA-256 of
// the file part, which is all a test needs to prove what reached it.
import { createHash } from 'node:crypto';
import { createServer, type Server } from 'node:http';
import { parseMultipart } from '../../src/request-policy.ts';

export interface S3Upload {
    method: string;
    path: string;
    contentType: string | null;
    fieldNames: string[];
    fileSha256: string | null;
    bytes: number;
}

export interface S3Standin {
    origin: string;
    uploads: S3Upload[];
    /** Status the bucket answers with (204 like S3, or an error). */
    status: number;
    close(): Promise<void>;
}

export async function startS3Standin(): Promise<S3Standin> {
    const uploads: S3Upload[] = [];
    const state = { status: 204 };
    const server: Server = createServer((req, res) => {
        const chunks: Buffer[] = [];
        req.on('data', (c: Buffer) => chunks.push(c));
        req.on('end', () => {
            const body = Buffer.concat(chunks);
            const contentType = req.headers['content-type'] ?? null;
            const parts =
                req.method === 'POST'
                    ? parseMultipart(body, contentType)
                    : null;
            const file = parts?.find((p) => p.name === 'file') ?? null;
            uploads.push({
                method: req.method ?? '',
                path: new URL(req.url ?? '/', 'http://x').pathname,
                contentType,
                fieldNames: (parts ?? [])
                    .filter((p) => p !== file)
                    .map((p) => p.name ?? ''),
                fileSha256: file
                    ? createHash('sha256').update(file.bytes).digest('hex')
                    : null,
                bytes: body.length,
            });
            res.writeHead(state.status, {
                'access-control-allow-origin': '*',
            }).end();
        });
    });
    await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
    const address = server.address();
    if (address === null || typeof address === 'string')
        throw new Error('no address');
    return {
        origin: `http://127.0.0.1:${address.port}`,
        uploads,
        get status() {
            return state.status;
        },
        set status(v: number) {
            state.status = v;
        },
        close: () => new Promise<void>((r) => server.close(() => r())),
    };
}

/** Builds a multipart/form-data body the way a browser's FormData would. */
export function multipart(
    parts: Array<{ name: string; value?: string | Buffer; filename?: string }>,
    boundary = '----WebKitFormBoundaryTest1234',
): { body: Buffer; contentType: string } {
    const chunks: Buffer[] = [];
    for (const p of parts) {
        const disposition = `Content-Disposition: form-data; name="${p.name}"${p.filename !== undefined ? `; filename="${p.filename}"` : ''}`;
        const type =
            p.filename !== undefined ? '\r\nContent-Type: application/pdf' : '';
        chunks.push(
            Buffer.from(`--${boundary}\r\n${disposition}${type}\r\n\r\n`),
            Buffer.isBuffer(p.value) ? p.value : Buffer.from(p.value ?? ''),
            Buffer.from('\r\n'),
        );
    }
    chunks.push(Buffer.from(`--${boundary}--\r\n`));
    return {
        body: Buffer.concat(chunks),
        contentType: `multipart/form-data; boundary=${boundary}`,
    };
}
