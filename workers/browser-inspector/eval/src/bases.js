// What KIND of result each case's score rests on. A score is only as strong as the evidence behind it, and the three bases
// below are not interchangeable: only the first is a real inspection of a real page, and Stage 0 has exactly one such case.
export const BASES = Object.freeze({
    captured_live_inspection: {
        label: 'Captured live inspection',
        live_inspection: true,
        statement: 'A stored result of a real production inspection run. It is fixed: it cannot reflect a later inspector.',
    },
    offline_provider_schema_snapshot: {
        label: 'Offline provider-schema snapshot',
        live_inspection: false,
        statement: 'The output of an offline schema adapter over committed provider-schema evidence. No page was opened and no live inspection took place.',
    },
    synthetic_fixture_replay: {
        label: 'Synthetic fixture replay',
        live_inspection: false,
        statement: 'The real inspector run over a local synthetic fixture. Regression evidence only: it says nothing about real sites.',
    },
});

export const MIXED_EVIDENCE_NOTICE = 'These figures pool cases whose results rest on different evidence bases. They are NOT a measured live-inspection success rate.';
