# Evidence consolidation

A small review queue for correcting career evidence that is already approved: long first-person
answers filed under one role, the same claim stored twice, places that were never established.
It reuses the existing revision and approval workflows; it is not a general evidence editor.

## What it is

`evidence_consolidations` holds one **review item** per proposed correction. Nothing in it is canonical.
`App\Support\Consolidation\ApplyConsolidation` is the only code that turns an item into a change, and
only when the user approves that item. Items are prepared from `ConsolidationPlan` (reviewed data, no
model call) by `php artisan evidence:consolidate [--dry-run]`, which is idempotent and never touches
an existing item, fact, proposal or answer.

| Action | Approval does |
| --- | --- |
| `revise` | `ReviseApprovedEvidence`: a new revision of the same fact (key, evidence and the superseded wording are kept). May move the place and join a shared claim (`claim_group`). |
| `create` | A new fact through the normal proposal approval (scope guard, duplicate checks), quoting the original answer as evidence. |
| `tag` | Attaches skills the fact's own wording names. Never changes wording. |
| `advice` | Rejects a redundant undecided proposal. Declining leaves it alone. |

## Rules

- **Nothing is deleted.** The original answer, every previous wording and the evidence rows remain.
- **Wording stays inside the answer.** Every item is checked by `ScopeGuard` against the answer it came from.
- **One claim, one count.** Places of one claim share a `claim_group` and must agree on wording
  (`assertGroupAgrees`); `SharedClaims::collapse` offers the group once.
- **No assumed places.** An item with a required decision (`decision.required`) cannot be approved
  until a place is chosen. The whole career (`career_profile`) is a valid, employer-free place for
  personal or cross-employer claims.
- **Skills are named, not inferred.** A skill is attached only if the fact's statement mentions it.
- **Private evidence is never reproduced** in the queue (`statement` is null for Private facts).

## Capability matching

`CapabilityCoverage` reads a statement as evidence only when it affirms the capability
("Docker rather than Terraform" is Docker evidence, not Terraform evidence). A statement that only
mentions a capability in passing (`weak_patterns`) is `underdocumented`, never direct. The review
card shows how the matcher would read the proposed wording (`capabilitiesIn`).
