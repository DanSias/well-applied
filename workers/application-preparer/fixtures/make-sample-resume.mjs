// Regenerates fixtures/sample-resume.pdf: a tiny, valid, obviously synthetic
// one-page PDF. Not a real person's resume. Run: node fixtures/make-sample-resume.mjs
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const text = 'SYNTHETIC SAMPLE RESUME - Alex Example - test data only';
const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    null,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
];
const stream = `BT /F1 14 Tf 72 720 Td (${text}) Tj ET`;
objs[3] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`;
let pdf = '%PDF-1.4\n';
const offsets = [];
objs.forEach((o, i) => {
    offsets.push(pdf.length);
    pdf += `${i + 1} 0 obj\n${o}\nendobj\n`;
});
const xref = pdf.length;
pdf += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}`;
pdf += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
writeFileSync(
    join(dirname(fileURLToPath(import.meta.url)), 'sample-resume.pdf'),
    pdf,
    'latin1',
);
