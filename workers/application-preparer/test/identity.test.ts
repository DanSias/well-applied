import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import type { DiscoveredField } from '../src/contracts.ts';
import { locateField } from '../src/identity.ts';

const field = (
    over: Partial<DiscoveredField> & { id: string },
): DiscoveredField => ({
    autocomplete: null,
    group: null,
    checked: null,
    selected: null,
    key: `file|file||${over.id}|attach`,
    occurrence: 0,
    ambiguous: false,
    position: 0,
    kind: 'file',
    tag: 'input',
    inputType: 'file',
    name: null,
    domId: null,
    label: 'Attach',
    labelSource: 'dom_label_for',
    required: false,
    options: null,
    sensitive: false,
    ...over,
});
const ids = (fs: DiscoveredField[]) => new Set(fs.map((f) => f.id));

const resume = field({
    id: 'f6',
    key: 'file|file||resume|attach',
    domId: 'resume',
});
const cover = field({
    id: 'f7',
    key: 'file|file||cover_letter|attach',
    domId: 'cover_letter',
});
const name = field({
    id: 'f0',
    key: 'text|text|first_name|first_name|first name',
    kind: 'text',
    inputType: 'text',
});

describe('locateField: identity is not positional', () => {
    it('the same element is found by id even when fields are inserted before it (positions shift)', () => {
        const now = [
            field({
                id: 'f20',
                key: 'text|text|x||x',
                kind: 'text',
                position: 0,
            }),
            name,
            { ...resume, position: 5 },
            cover,
        ];
        const r = locateField(resume, now, ids([name, resume, cover]));
        assert.equal(r.tracking, 'same_element');
        assert.equal(r.field?.id, 'f6');
    });

    it('is unaffected by reordering', () => {
        const r = locateField(
            resume,
            [cover, resume, name],
            ids([name, resume, cover]),
        );
        assert.equal(r.tracking, 'same_element');
        assert.equal(r.field?.id, 'f6');
    });

    it('still the same element when its label changed (element identity wins over the key)', () => {
        const r = locateField(
            resume,
            [
                {
                    ...resume,
                    key: 'file|file||resume|resume/cv',
                    label: 'Resume/CV',
                },
            ],
            ids([resume]),
        );
        assert.equal(r.tracking, 'same_element');
    });

    it('REGRESSION (Greenhouse): the resume input disappears and the cover-letter input is NOT mistaken for it', () => {
        // Before the fix the cover letter inherited the resume's positional id.
        const r = locateField(
            resume,
            [name, cover],
            ids([name, resume, cover]),
        );
        assert.equal(r.tracking, 'removed');
        assert.equal(r.field, null);
    });

    it('a re-rendered input (new element, same identifying properties) is re-found by its key', () => {
        const rerendered = field({
            id: 'f30',
            key: resume.key,
            domId: 'resume',
        });
        const r = locateField(
            resume,
            [name, rerendered, cover],
            ids([name, resume, cover]),
        );
        assert.equal(r.tracking, 'rematched_by_key');
        assert.equal(r.field?.id, 'f30');
    });

    it('resume and cover-letter coexisting stay distinct: each is tracked to itself', () => {
        const now = [name, resume, cover];
        assert.equal(locateField(resume, now, ids(now)).field?.id, 'f6');
        assert.equal(locateField(cover, now, ids(now)).field?.id, 'f7');
    });

    it('a pre-existing field that happens to share the key is not a re-render', () => {
        // Two looks-alike fields existed before; one vanished; the survivor is not "the" one.
        const a = field({ id: 'f1', key: 'file|file|||attach' });
        const b = field({ id: 'f2', key: 'file|file|||attach' });
        const r = locateField({ ...a, ambiguous: false }, [b], ids([a, b]));
        assert.equal(r.tracking, 'removed');
    });

    it('AMBIGUOUS: the original shared its identifying properties with another field, so no replacement is attributed', () => {
        const a = field({
            id: 'f1',
            key: 'file|file|||attach',
            ambiguous: true,
        });
        const b = field({
            id: 'f2',
            key: 'file|file|||attach',
            ambiguous: true,
        });
        const fresh = field({ id: 'f9', key: 'file|file|||attach' });
        const r = locateField(a, [b, fresh], ids([a, b]));
        assert.equal(r.tracking, 'ambiguous');
        assert.equal(r.field, null);
    });

    it('AMBIGUOUS: two new fields match the original after it vanished', () => {
        const x = field({ id: 'f40', key: resume.key });
        const y = field({ id: 'f41', key: resume.key });
        const r = locateField(resume, [x, y], ids([resume]));
        assert.equal(r.tracking, 'ambiguous');
        assert.match(r.note, /2 new fields/);
    });

    it('the same id but a different kind is not the same element', () => {
        const r = locateField(
            resume,
            [{ ...resume, kind: 'text', inputType: 'text' }],
            ids([resume]),
        );
        assert.notEqual(r.tracking, 'same_element');
    });
});
