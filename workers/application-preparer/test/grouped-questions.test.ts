// Through the engine: a Palantir-shaped Lever form. Many controls, far fewer questions.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { startServer, type TestServer } from './support/server.ts';
import { LEVER_AUTH_QUESTION, fixtureHtml, runOn } from './support/run.ts';

let server: TestServer;
let base: string;
before(async () => {
    server = await startServer();
    base = await fixtureHtml('lever-form.html');
    const langs = [
        'English (ENG)',
        'Spanish (SPA)',
        'French (FRA)',
        'German (DEU)',
        'Japanese (JPN)',
        'Cantonese (CANT)',
        'Mandarin (MAN)',
        'Dutch (NLD)',
        'Hindi (HIN)',
        'Arabic (ARA)',
        'Danish (DAN)',
        'Turkish (TUR)',
        'Portuguese (POR)',
        'Hebrew (HEB)',
        'Swedish (SWE)',
        'Norwegian (NOR)',
        'Italian (ITA)',
        'Vietnamese (VIE)',
        'Tagalog (TGL)',
        'Khmer (KHM)',
        'Hmong (HMN)',
        'Telugu (TEL)',
        'Bengali (BNE)',
        'Marathi (MAR)',
        'Urdu (URD)',
        'Gujarati (GUJ)',
        'Lithuanian (LIT)',
        'Korean (KOR)',
        'American Sign Language (ASL)',
        'British Sign Language (BSL)',
        'Choose not to disclose',
        'Polish',
        'Other',
    ];
    const group = (
        name: string,
        label: string | null,
        options: string[],
        required: boolean,
    ) =>
        `<li class="application-question"><label>${label ? `<div class="application-label">${label}${required ? ' <span class="required">✱</span>' : ''}</div>` : ''}<div class="application-field"><ul>${options
            .map(
                (o) =>
                    `<li><label><input type="checkbox" name="${name}" ${required ? 'required' : ''}> ${o}</label></li>`,
            )
            .join('')}</ul></div></label></li>`;
    const injected = `<ul id="palantir-like">
      ${group('cards[lang][field0]', 'Language Skill(s)', langs, true)}
      ${group('cards[emp][field0]', null, ['No', 'Yes - Intern', 'Yes - Full Time Employment'], true)}
      ${group('cards[opt][field0]', 'Anything else we should know? (select any)', ['Relocation', 'Remote only', 'Part time', 'Contract'], false)}
      <li class="application-question"><div class="application-label">Describe your favourite project</div><div><textarea name="cards[proj][field0]" required></textarea></div></li>
      <li class="application-question"><div><select name="cards[unl][field0]"><option value="">Select</option><option>One</option></select></div></li>
    </ul>`;
    server.add(
        '/palantir-like.html',
        base.replace('<h4>Links</h4>', `${injected}<h4>Links</h4>`),
    );
});
after(async () => server.close());

const run = () =>
    runOn(server, '/palantir-like.html', 'lever', {
        input: { answers: { [LEVER_AUTH_QUESTION]: 'Yes' } },
        options: { parserWaitMs: 500 },
    });

describe('grouped questions through the engine', () => {
    it('collapses 45 checkbox controls into 4 questions, keeps the per-control evidence, and reports each question once', async () => {
        const { report } = await run();

        const checkboxFields = report.fields.filter(
            (f) =>
                f.kind === 'checkbox' &&
                /cards\[(lang|emp|opt)\]/.test(f.name ?? ''),
        );
        assert.equal(
            checkboxFields.length,
            33 + 3 + 4,
            'the controls are all still there as evidence',
        );
        assert.ok(report.fields.length > report.questions.length + 35);

        const lang = report.questions.find(
            (q) => q.label === 'Language Skill(s)',
        )!;
        assert.equal(lang.kind, 'checkbox_group');
        assert.equal(lang.options!.length, 33);
        assert.equal(lang.controls.length, 33);
        assert.equal(lang.required, true);
        assert.equal(lang.status, 'unresolved_required');
        assert.equal(lang.review, 'needs_answer');
        assert.equal(lang.labelConfidence, 'structural');
        assert.deepEqual(lang.selected, []);
        assert.deepEqual(lang.provenance, { source: 'none' });

        const emp = report.questions.find(
            (q) => q.name === 'cards[emp][field0]',
        )!;
        assert.equal(emp.label, null);
        assert.equal(emp.labelConfidence, 'unresolved');
        assert.deepEqual(emp.options, [
            'No',
            'Yes - Intern',
            'Yes - Full Time Employment',
        ]);
        assert.match(emp.note!, /no question label/);

        const opt = report.questions.find((q) =>
            /Anything else/.test(q.label ?? ''),
        )!;
        assert.equal(opt.required, false);
        assert.equal(opt.review, 'optional_skipped');
    });

    it('lists each unresolved REQUIRED question once in the outcome reasons, and never an option as a question', async () => {
        const { report } = await run();
        assert.equal(report.outcome, 'input_required');
        const reasons = report.reasons;
        assert.equal(
            reasons.filter(
                (r) => r === 'unresolved_required: Language Skill(s)',
            ).length,
            1,
        );
        assert.equal(
            reasons.filter(
                (r) =>
                    r ===
                    'unresolved_required: (no label found: cards[emp][field0])',
            ).length,
            1,
        );
        assert.ok(
            !reasons.some((r) =>
                /English \(ENG\)|Spanish|Yes - Intern|Relocation/.test(r),
            ),
            'no option leaks in as a question',
        );
        // Required questions here: languages, the unlabelled group, the textarea (inferred label) and the location typeahead.
        assert.equal(
            reasons.length,
            report.questions.filter(
                (q) =>
                    [
                        'unresolved_required',
                        'unresolved_uncertain',
                        'error',
                    ].includes(q.status) ||
                    (q.required &&
                        ['manual_action_required', 'unverified'].includes(
                            q.status,
                        )),
            ).length,
        );
        assert.ok(reasons.length <= 6, JSON.stringify(reasons));
        assert.ok(report.questionSummary.needsAnswer >= 3);
    });

    it('uses an inferred container label where the control has none, and says it is inferred; leaves a no-evidence control unlabelled', async () => {
        const { report } = await run();
        const proj = report.questions.find(
            (q) => q.name === 'cards[proj][field0]',
        )!;
        assert.equal(proj.label, 'Describe your favourite project');
        assert.equal(proj.labelSource, 'inferred_proximity');
        assert.equal(proj.labelConfidence, 'inferred');
        const unl = report.questions.find(
            (q) => q.name === 'cards[unl][field0]',
        )!;
        assert.equal(unl.label, null);
        assert.equal(unl.labelConfidence, 'unresolved');
        assert.ok(report.questionSummary.unlabelled >= 2);
    });

    it('still never selects a checkbox group: nothing is checked, nothing is typed, no request beyond the allowed parse', async () => {
        const { report } = await run();
        const groups = report.questions.filter(
            (q) => q.kind === 'checkbox_group',
        );
        assert.ok(groups.length >= 3);
        assert.ok(groups.every((q) => q.selected.length === 0));
        assert.ok(
            report.fields
                .filter((f) => f.kind === 'checkbox')
                .every((f) => f.status !== 'filled_verified'),
        );
        assert.deepEqual(server.unexpected(), []);
        assert.equal(report.submission.blockedAttempts, 0);
    });

    it('keeps the existing questions intact: contact fields verified, location a manual action, sensitive groups sensitive', async () => {
        const { report } = await run();
        const email = report.questions.find((q) => q.label === 'Email')!;
        assert.equal(email.review, 'complete');
        assert.deepEqual(email.provenance, {
            source: 'applicant_fact',
            key: 'email',
        });
        const loc = report.questions.find(
            (q) => q.label === 'Current location',
        )!;
        assert.equal(loc.review, 'needs_person');
        assert.equal(loc.expected, 'Springfield, Exampleland');
        const pronouns = report.questions.find((q) => q.label === 'Pronouns')!;
        assert.equal(pronouns.kind, 'checkbox_group');
        assert.equal(pronouns.sensitive, true);
        assert.equal(pronouns.options!.length, 10);
        const authorized = report.questions.find((q) =>
            /legally authorized/.test(q.label ?? ''),
        )!;
        assert.deepEqual(authorized.provenance, { source: 'approved_answer' });
        assert.deepEqual(authorized.selected, ['Yes']);
    });
});
