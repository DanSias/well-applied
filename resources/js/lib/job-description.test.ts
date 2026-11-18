import { describe, expect, it } from 'vitest';
import { parseDescription } from './job-description';

describe('parseDescription', () => {
    it('groups bullets separated by blank lines into one list', () => {
        expect(
            parseDescription('- one\n\n- two\n\n\n• three\n\n* four'),
        ).toEqual([{ kind: 'list', items: ['one', 'two', 'three', 'four'] }]);
    });

    it('joins a wrapped bullet, indented or not', () => {
        expect(
            parseDescription('- first part\n  second part\n- other\ncontinues'),
        ).toEqual([
            {
                kind: 'list',
                items: ['first part second part', 'other continues'],
            },
        ]);
    });

    it('keeps ordinary paragraphs and their line breaks', () => {
        expect(parseDescription('Para one\nline two\n\nPara two')).toEqual([
            { kind: 'paragraph', text: 'Para one\nline two' },
            { kind: 'paragraph', text: 'Para two' },
        ]);
    });

    it('handles mixed content: heading, list, then a paragraph after a blank line', () => {
        expect(
            parseDescription(
                'Intro text.\n\nRequirements:\n\n- 5 years\n\n- React\n\nWe offer great pay.\n\nBenefits:\n- Health',
            ),
        ).toEqual([
            { kind: 'paragraph', text: 'Intro text.' },
            { kind: 'paragraph', text: 'Requirements:' },
            { kind: 'list', items: ['5 years', 'React'] },
            { kind: 'paragraph', text: 'We offer great pay.' },
            { kind: 'paragraph', text: 'Benefits:' },
            { kind: 'list', items: ['Health'] },
        ]);
    });

    it('does not treat bold markers or hyphenated words as bullets', () => {
        expect(parseDescription('**Bold** start\n-not a bullet')).toEqual([
            { kind: 'paragraph', text: '**Bold** start\n-not a bullet' },
        ]);
    });

    it('handles CRLF and empty input', () => {
        expect(parseDescription('- a\r\n\r\n- b')).toEqual([
            { kind: 'list', items: ['a', 'b'] },
        ]);
        expect(parseDescription('')).toEqual([]);
    });

    describe('standalone markers', () => {
        it('folds a lone marker into the next text line, with or without blank lines', () => {
            expect(
                parseDescription(
                    "What you'll do:\n\n-\nDesigning secure features.\n\n-\n\nWorking across the stack.\n•\nThird",
                ),
            ).toEqual([
                { kind: 'paragraph', text: "What you'll do:" },
                {
                    kind: 'list',
                    items: [
                        'Designing secure features.',
                        'Working across the stack.',
                        'Third',
                    ],
                },
            ]);
        });

        it('keeps multiline bullet content and ends the list at a paragraph boundary', () => {
            expect(
                parseDescription(
                    '*\nLine one\nline two\n\nA closing paragraph.',
                ),
            ).toEqual([
                { kind: 'list', items: ['Line one line two'] },
                { kind: 'paragraph', text: 'A closing paragraph.' },
            ]);
        });

        it('mixes with normal bullets', () => {
            expect(parseDescription('- a\n-\nb\n- c')).toEqual([
                { kind: 'list', items: ['a', 'b', 'c'] },
            ]);
        });

        it('leaves ambiguous markers as text: trailing, doubled, before a heading, rules', () => {
            expect(parseDescription('Intro\n\n-')).toEqual([
                { kind: 'paragraph', text: 'Intro' },
                { kind: 'paragraph', text: '-' },
            ]);
            expect(parseDescription('-\n\n-\nItem')).toEqual([
                { kind: 'paragraph', text: '-' },
                { kind: 'list', items: ['Item'] },
            ]);
            expect(parseDescription('-\nBenefits:')).toEqual([
                { kind: 'paragraph', text: '-\nBenefits:' },
            ]);
            expect(parseDescription('---\nText')).toEqual([
                { kind: 'paragraph', text: '---\nText' },
            ]);
        });
    });
});
