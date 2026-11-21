# Application inspection evaluation

Configuration `generic-off` · manifest 0.1.0 (`0026ac32f6c9`) · inspector `e5761d1fd96b` (source `ada0fb9cebc0`)

Offline evaluation of committed evidence. Each metric is `value (numerator/denominator, scorable cases)`. A case with missing or inapplicable evidence is **unscored**, never counted as a pass or a failure. Synthetic and real-world cases are reported separately and never pooled.

Evidence integrity: **all files verified**.

## High-severity findings

None.

## Other findings

- medium **unverified_complete** · real-chainguard-4722145006: claimed complete; the ground truth does not establish whether the form is complete

## Real-world captured evidence (2 cases)

| Metric | Result |
|---|---|
| application_url_resolution | 100.0% (1/1, 1 case) |
| platform_classification | 100.0% (2/2, 2 cases) |
| field_precision | 95.0% (19/20, 1 case) |
| field_recall | 100.0% (33/33, 2 cases) |
| field_f1 | 97.4% (38/39, 1 case) |
| field_type_accuracy | 74.2% (23/31, 2 cases) · against inferred truth: 0/2 |
| required_accuracy | 100.0% (33/33, 2 cases) · against inferred truth: 0/0 |
| unresolved_label_rate | 4.1% (2/49, 2 cases) |
| provenance_expectation | 100.0% (49/49, 2 cases) |
| outcome_classification | 100.0% (1/1, 1 case) |
| barrier_classification | unscored (0 of 2 cases scorable) |
| false_barrier_rate | 0.0% (0/1, 1 case) |
| false_complete_claims | 0/1 evaluable claims of complete |

Provenance of extracted rows: dom 7, both 13, provider_schema 29. Label sources: dom_label_for 19, unresolved 2, provider_schema 28.

<details><summary>Unscored</summary>

- application_url_resolution: real-nerdwallet-258 (schema_only_result_carries_no_url)
- field_precision: real-nerdwallet-258 (truth_not_exhaustive)
- field_f1: real-nerdwallet-258 (truth_not_exhaustive)
- outcome_classification: real-chainguard-4722145006 (no_ground_truth)
- barrier_classification: real-chainguard-4722145006 (no_ground_truth)
- barrier_classification: real-nerdwallet-258 (truth_is_not_a_barrier)
- false_barrier_rate: real-chainguard-4722145006 (no_ground_truth)

</details>

## Synthetic regression fixtures (9 cases)

| Metric | Result |
|---|---|
| application_url_resolution | 66.7% (6/9, 9 cases) |
| platform_classification | 100.0% (9/9, 9 cases) |
| field_precision | unscored (0 of 9 cases scorable) |
| field_recall | 0.0% (0/53, 7 cases) |
| field_f1 | unscored (0 of 9 cases scorable) |
| field_type_accuracy | unscored (0 of 9 cases scorable) |
| required_accuracy | unscored (0 of 9 cases scorable) |
| unresolved_label_rate | unscored (0 of 9 cases scorable) |
| provenance_expectation | unscored (0 of 9 cases scorable) |
| outcome_classification | 22.2% (2/9, 9 cases) |
| barrier_classification | 100.0% (2/2, 2 cases) |
| false_barrier_rate | 100.0% (7/7, 7 cases) |
| false_complete_claims | 0/0 evaluable claims of complete |

Provenance of extracted rows: none. Label sources: none.

<details><summary>Unscored</summary>

- field_precision: syn-apply-button-nonget (no_denominator)
- field_precision: syn-auth-gate-aggregator (no_denominator)
- field_precision: syn-combobox-nested-search (no_denominator)
- field_precision: syn-conditional-hidden-fields (no_denominator)
- field_precision: syn-greenhouse-style-form (no_denominator)
- field_precision: syn-labels-vs-headings (no_denominator)
- field_precision: syn-multi-step (no_denominator)
- field_precision: syn-sensitive-demographics (no_denominator)
- field_precision: syn-unknown-ats-form (no_denominator)
- field_recall: syn-apply-button-nonget (no_scorable_truth_fields)
- field_recall: syn-auth-gate-aggregator (no_scorable_truth_fields)
- field_f1: syn-apply-button-nonget (no_scorable_truth_fields)
- field_f1: syn-auth-gate-aggregator (no_scorable_truth_fields)
- field_f1: syn-combobox-nested-search (no_denominator)
- field_f1: syn-conditional-hidden-fields (no_denominator)
- field_f1: syn-greenhouse-style-form (no_denominator)
- field_f1: syn-labels-vs-headings (no_denominator)
- field_f1: syn-multi-step (no_denominator)
- field_f1: syn-sensitive-demographics (no_denominator)
- field_f1: syn-unknown-ats-form (no_denominator)
- field_type_accuracy: syn-apply-button-nonget (no_matched_fields)
- field_type_accuracy: syn-auth-gate-aggregator (no_matched_fields)
- field_type_accuracy: syn-combobox-nested-search (no_matched_fields)
- field_type_accuracy: syn-conditional-hidden-fields (no_matched_fields)
- field_type_accuracy: syn-greenhouse-style-form (no_matched_fields)
- field_type_accuracy: syn-labels-vs-headings (no_matched_fields)
- field_type_accuracy: syn-multi-step (no_matched_fields)
- field_type_accuracy: syn-sensitive-demographics (no_matched_fields)
- field_type_accuracy: syn-unknown-ats-form (no_matched_fields)
- required_accuracy: syn-apply-button-nonget (no_matched_fields)
- required_accuracy: syn-auth-gate-aggregator (no_matched_fields)
- required_accuracy: syn-combobox-nested-search (no_matched_fields)
- required_accuracy: syn-conditional-hidden-fields (no_matched_fields)
- required_accuracy: syn-greenhouse-style-form (no_matched_fields)
- required_accuracy: syn-labels-vs-headings (no_matched_fields)
- required_accuracy: syn-multi-step (no_matched_fields)
- required_accuracy: syn-sensitive-demographics (no_matched_fields)
- required_accuracy: syn-unknown-ats-form (no_matched_fields)
- unresolved_label_rate: syn-apply-button-nonget (no_rows)
- unresolved_label_rate: syn-auth-gate-aggregator (no_rows)
- unresolved_label_rate: syn-combobox-nested-search (no_rows)
- unresolved_label_rate: syn-conditional-hidden-fields (no_rows)
- unresolved_label_rate: syn-greenhouse-style-form (no_rows)
- unresolved_label_rate: syn-labels-vs-headings (no_rows)
- unresolved_label_rate: syn-multi-step (no_rows)
- unresolved_label_rate: syn-sensitive-demographics (no_rows)
- unresolved_label_rate: syn-unknown-ats-form (no_rows)
- provenance_expectation: syn-apply-button-nonget (no_rows)
- provenance_expectation: syn-auth-gate-aggregator (no_rows)
- provenance_expectation: syn-combobox-nested-search (no_rows)
- provenance_expectation: syn-conditional-hidden-fields (no_rows)
- provenance_expectation: syn-greenhouse-style-form (no_rows)
- provenance_expectation: syn-labels-vs-headings (no_rows)
- provenance_expectation: syn-multi-step (no_rows)
- provenance_expectation: syn-sensitive-demographics (no_rows)
- provenance_expectation: syn-unknown-ats-form (no_rows)
- barrier_classification: syn-combobox-nested-search (truth_is_not_a_barrier)
- barrier_classification: syn-conditional-hidden-fields (truth_is_not_a_barrier)
- barrier_classification: syn-greenhouse-style-form (truth_is_not_a_barrier)
- barrier_classification: syn-labels-vs-headings (truth_is_not_a_barrier)
- barrier_classification: syn-multi-step (truth_is_not_a_barrier)
- barrier_classification: syn-sensitive-demographics (truth_is_not_a_barrier)
- barrier_classification: syn-unknown-ats-form (truth_is_not_a_barrier)
- false_barrier_rate: syn-apply-button-nonget (truth_is_a_barrier)
- false_barrier_rate: syn-auth-gate-aggregator (truth_is_a_barrier)

</details>

## Cases

| Case | Origin | Result basis | Claimed outcome | Findings |
|---|---|---|---|---|
| real-chainguard-4722145006 | real_world | captured_live | complete | unverified_complete |
| real-nerdwallet-258 | real_world | snapshot | partial | — |
| syn-apply-button-nonget | synthetic | replay | application_not_found | — |
| syn-auth-gate-aggregator | synthetic | replay | application_not_found | — |
| syn-combobox-nested-search | synthetic | replay | application_not_found | — |
| syn-conditional-hidden-fields | synthetic | replay | application_not_found | — |
| syn-greenhouse-style-form | synthetic | replay | unsupported | — |
| syn-labels-vs-headings | synthetic | replay | unsupported | — |
| syn-multi-step | synthetic | replay | unsupported | — |
| syn-sensitive-demographics | synthetic | replay | application_not_found | — |
| syn-unknown-ats-form | synthetic | replay | unsupported | — |

A `captured_live` result is a past production run and cannot change with the inspector under evaluation.

## Evidence

| Case | Role | Class | SHA-256 | Status |
|---|---|---|---|---|
| real-chainguard-4722145006 | run12_agent_run | observed | `0a4f08ed421c` | verified |
| real-chainguard-4722145006 | run12_questions | observed | `1b963a40841e` | verified |
| real-chainguard-4722145006 | golden | curated | `587149f60870` | verified |
| real-chainguard-4722145006 | api_schema | api_derived | `3443a2b1a2a6` | verified |
| real-nerdwallet-258 | dom_observation | observed | `5bafdfe8a69d` | verified |
| real-nerdwallet-258 | contract_result | result | `e67887cac5a5` | verified |
| real-nerdwallet-258 | api_schema | api_derived | `b7f2c5add31b` | verified |
| syn-apply-button-nonget | fixture_html | authored | `6fb2afbedf57` | verified |
| syn-apply-button-nonget | expected | authored | `1b329c52c226` | verified |
| syn-apply-button-nonget | replayed_result | observed | `98748d1364e3` | verified |
| syn-auth-gate-aggregator | fixture_html | authored | `e98db850678e` | verified |
| syn-auth-gate-aggregator | expected | authored | `d2a33eae2ef9` | verified |
| syn-auth-gate-aggregator | replayed_result | observed | `bbece5107565` | verified |
| syn-combobox-nested-search | fixture_html | authored | `9ddf462108fb` | verified |
| syn-combobox-nested-search | expected | authored | `01c33a74fe53` | verified |
| syn-combobox-nested-search | replayed_result | observed | `2ddd57d3c171` | verified |
| syn-conditional-hidden-fields | fixture_html | authored | `2062a31c9285` | verified |
| syn-conditional-hidden-fields | expected | authored | `17cd0e34f583` | verified |
| syn-conditional-hidden-fields | replayed_result | observed | `9f97a82917c4` | verified |
| syn-greenhouse-style-form | fixture_html | authored | `2a276dbee9bc` | verified |
| syn-greenhouse-style-form | expected | authored | `306bdc73743b` | verified |
| syn-greenhouse-style-form | replayed_result | observed | `da81abe40422` | verified |
| syn-labels-vs-headings | fixture_html | authored | `5f0d76f90bac` | verified |
| syn-labels-vs-headings | expected | authored | `59384f8500e8` | verified |
| syn-labels-vs-headings | replayed_result | observed | `3996fa5142d0` | verified |
| syn-multi-step | fixture_html | authored | `a6de8bae8bcf` | verified |
| syn-multi-step | expected | authored | `684f92ee97da` | verified |
| syn-multi-step | replayed_result | observed | `0e6f43a5f52b` | verified |
| syn-sensitive-demographics | fixture_html | authored | `bb22f91dfeb3` | verified |
| syn-sensitive-demographics | expected | authored | `ae36ce1e8871` | verified |
| syn-sensitive-demographics | replayed_result | observed | `591d4a1afbcf` | verified |
| syn-unknown-ats-form | fixture_html | authored | `9ec17da9f49c` | verified |
| syn-unknown-ats-form | expected | authored | `07df0978dbd7` | verified |
| syn-unknown-ats-form | replayed_result | observed | `39d055420faf` | verified |
