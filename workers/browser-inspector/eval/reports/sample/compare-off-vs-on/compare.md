# Evaluation comparison

Before: `generic-off` · inspector `e5761d1fd96b`
After: `generic-on` · inspector `e5761d1fd96b`

> both runs used identical inspector source: any difference comes from the configuration, not the code

**4 improved · 0 regressed · 15 unchanged · 7 not comparable**

| Origin | Metric | Before | After | Verdict |
|---|---|---|---|---|
| real_world | application_url_resolution | 100.0% (1/1) | 100.0% (1/1) | unchanged |
| real_world | barrier_classification | n/a (0/0) | n/a (0/0) | not_comparable — unscored in one or both reports |
| real_world | false_barrier_rate | 0.0% (0/1) | 0.0% (0/1) | unchanged |
| real_world | field_f1 | 97.4% (38/39) | 97.4% (38/39) | unchanged |
| real_world | field_precision | 95.0% (19/20) | 95.0% (19/20) | unchanged |
| real_world | field_recall | 100.0% (33/33) | 100.0% (33/33) | unchanged |
| real_world | field_type_accuracy | 74.2% (23/31) | 74.2% (23/31) | unchanged |
| real_world | outcome_classification | 100.0% (1/1) | 100.0% (1/1) | unchanged |
| real_world | platform_classification | 100.0% (2/2) | 100.0% (2/2) | unchanged |
| real_world | provenance_expectation | 100.0% (49/49) | 100.0% (49/49) | unchanged |
| real_world | required_accuracy | 100.0% (33/33) | 100.0% (33/33) | unchanged |
| real_world | unresolved_label_rate | 4.1% (2/49) | 4.1% (2/49) | unchanged |
| real_world | false_complete_claims | 0/1 | 0/1 | unchanged |
| synthetic | application_url_resolution | 66.7% (6/9) | 100.0% (9/9) | improved |
| synthetic | barrier_classification | 100.0% (2/2) | 100.0% (2/2) | unchanged |
| synthetic | false_barrier_rate | 100.0% (7/7) | 0.0% (0/7) | improved |
| synthetic | field_f1 | n/a (0/0) | 100.0% (106/106) | not_comparable — unscored in one or both reports |
| synthetic | field_precision | n/a (0/0) | 100.0% (53/53) | not_comparable — unscored in one or both reports |
| synthetic | field_recall | 0.0% (0/53) | 100.0% (53/53) | improved |
| synthetic | field_type_accuracy | n/a (0/0) | 100.0% (53/53) | not_comparable — unscored in one or both reports |
| synthetic | outcome_classification | 22.2% (2/9) | 100.0% (9/9) | improved |
| synthetic | platform_classification | 100.0% (9/9) | 100.0% (9/9) | unchanged |
| synthetic | provenance_expectation | n/a (0/0) | 100.0% (53/53) | not_comparable — unscored in one or both reports |
| synthetic | required_accuracy | n/a (0/0) | 100.0% (52/52) | not_comparable — unscored in one or both reports |
| synthetic | unresolved_label_rate | n/a (0/0) | 0.0% (0/53) | not_comparable — unscored in one or both reports |
| synthetic | false_complete_claims | 0/0 | 0/4 | unchanged — the number of evaluable claims of complete differs |

## Findings

No change.

## Cases whose claimed outcome changed

- syn-combobox-nested-search: outcome application_not_found → complete
- syn-conditional-hidden-fields: outcome application_not_found → partial
- syn-greenhouse-style-form: outcome unsupported → partial
- syn-labels-vs-headings: outcome unsupported → complete
- syn-multi-step: outcome unsupported → partial
- syn-sensitive-demographics: outcome application_not_found → complete
- syn-unknown-ats-form: outcome unsupported → complete
