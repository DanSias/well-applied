import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { rankApplyCandidates, destinationKey, normalizeText } from '../src/apply-links.js';

const page = 'https://jobicy.com/jobs/1-platform-engineer';
const links = (anchors, extra = {}) => ({ anchors, frames: [], buttons: [], ...extra });

describe('which links count as Apply', () => {
    test('only an exact Apply wording, never a partial one', () => {
        const { candidates } = rankApplyCandidates(links([
            { href: 'https://employer.example.com/a', text: 'Apply Now' },
            { href: 'https://example.com/filters', text: 'Apply filters' },
            { href: 'https://example.com/coupon', text: 'Apply coupon code' },
            { href: 'https://example.com/applied', text: 'Applied jobs' },
        ]), page);

        assert.deepEqual(candidates.map((c) => c.url), ['https://employer.example.com/a']);
    });

    test('wording variants, arrows and punctuation are normalised', () => {
        for (const text of ['APPLY NOW →', 'Apply on company site ↗', 'Apply for this job!', '  apply   here ']) {
            assert.equal(rankApplyCandidates(links([{ href: 'https://e.example.com/x', text }]), page).candidates.length, 1, text);
        }
        assert.equal(normalizeText('Apply Now »'), 'apply now');
    });

    test('relative links resolve against the page', () => {
        const { candidates } = rankApplyCandidates(links([{ href: '/go/123', text: 'Apply' }]), page);
        assert.equal(candidates[0].url, 'https://jobicy.com/go/123');
    });

    test('mailto, javascript, tel, fragments and the same page are never candidates', () => {
        const { candidates, warnings } = rankApplyCandidates(links([
            { href: 'mailto:jobs@x.test', text: 'Apply' },
            { href: 'javascript:void(0)', text: 'Apply Now' },
            { href: 'tel:+1555', text: 'Apply' },
            { href: '#apply', text: 'Apply' },
            { href: page, text: 'Apply' },
            { href: `${page}#top`, text: 'Apply Now' },
        ]), page);

        assert.deepEqual(candidates, []);
        assert.deepEqual(warnings, ['apply_is_email']);
    });

    test('an accessible-name-only link (aria-label or title) counts through the text the page collected', () => {
        assert.equal(rankApplyCandidates(links([{ href: 'https://e.example.com/x', text: 'Apply now' }]), page).candidates.length, 1);
    });
});

describe('duplicates and ambiguity', () => {
    test('the same destination differing only by tracking, fragment, parameter order or trailing slash is one candidate', () => {
        const { candidates } = rankApplyCandidates(links([
            { href: 'https://e.example.com/apply/?utm_source=a&b=2&a=1', text: 'Apply Now' },
            { href: 'https://e.example.com/apply?a=1&b=2&gclid=zz#top', text: 'Apply' },
        ]), page);

        assert.equal(candidates.length, 1);
    });

    test('a functional parameter makes two destinations distinct', () => {
        const { candidates } = rankApplyCandidates(links([
            { href: 'https://boards.greenhouse.io/acme/jobs/1?gh_jid=1', text: 'Apply Now' },
            { href: 'https://boards.greenhouse.io/acme/jobs/1?gh_jid=2', text: 'Apply Now' },
        ]), page);

        assert.equal(candidates.length, 2);
        assert.notEqual(destinationKey('https://x.example.com/a?gh_jid=1'), destinationKey('https://x.example.com/a?gh_jid=2'));
    });

    test('exact wording outranks a path that merely contains /apply', () => {
        const { candidates } = rankApplyCandidates(links([
            { href: 'https://e.example.com/real', text: 'Apply Now' },
            { href: '/apply', text: 'Our application process' },
        ]), page);

        assert.deepEqual(candidates.map((c) => c.kind), ['link']);
    });

    test('with no wording match, an /apply path on the same origin or a known ATS is a (weaker) candidate', () => {
        const { candidates } = rankApplyCandidates(links([
            { href: '/jobs/1/apply', text: 'Start here' },
            { href: 'https://jobs.lever.co/acme/1/apply', text: 'Go' },
            { href: 'https://elsewhere.example.com/apply', text: 'Go' },
        ]), page);

        assert.deepEqual(candidates.map((c) => c.kind), ['path', 'path']);
    });
});

describe('what is reported instead of clicked', () => {
    test('an Apply button that is not a link is a warning, and never a candidate', () => {
        const { candidates, warnings } = rankApplyCandidates(links([], { buttons: ['Apply Now', 'Save job'] }), page);

        assert.deepEqual(candidates, []);
        assert.deepEqual(warnings, ['apply_is_button']);
    });

    test('an embedded Greenhouse application frame is a candidate, and other frames are not', () => {
        const { candidates } = rankApplyCandidates(links([], {
            frames: [
                { src: 'https://boards.greenhouse.io/embed/job_app?for=acme&token=9' },
                { src: 'https://www.youtube.com/embed/abc' },
                { src: 'https://boards.greenhouse.io/embed/job_board?for=acme' },
            ],
        }), page);

        assert.deepEqual(candidates.map((c) => [c.kind, c.url]), [['embed', 'https://boards.greenhouse.io/embed/job_app?for=acme&token=9']]);
    });
});
