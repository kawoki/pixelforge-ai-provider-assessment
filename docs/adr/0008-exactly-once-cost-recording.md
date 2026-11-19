# ADR-0008: Exactly-once cost recording (settlement)

- Status: Accepted
- Date: 2026-11-19

## Context

"Every generation is recorded at exactly the right cost, never twice, and failed generations are free."
This is the most heavily weighted criterion. Settlement can be triggered by a webhook, a duplicate
webhook, a different webhook for the same completion, or reconciliation polling. Two of these can
even run at the same time in different workers.

## Decision

`SettlementService::settle(Generation, TokenUsageData)` runs **only** inside the `→ succeeded`
transition (ADR-0006), inside one DB transaction:

1. `lockForUpdate()` the generation row. If it is already terminal → return (no-op).
2. Compute `actual` with `PricingService::actual()` from Pixelforge's reported `usage` and the generation's
   **snapshotted** rate card: Pixelforge's own `rates` for that generation + our markup at creation.
3. Write `usage` (jsonb), `actual_provider_cost`, `actual_user_cost`, `status = succeeded`, `completed_at`.
4. Insert a `credit_transactions` row: `type = charge`, `amount = -actual_user_cost`,
   `idempotency_key = "charge:{generation_id}"`, which has a **UNIQUE** constraint. This is the
   database's last line of defence: even a logic bug can't double-charge. A partial unique index
   (`generation_id WHERE type = 'charge'`) also blocks a second charge with a differently built key, and
   CHECK constraints require `generations.actual_*` to be set **iff** status is `succeeded`, and charges
   to be ≤ 0 with a generation (implemented in phase 2).
5. Update the cached `users.balance` under a user row lock (`CreditLedgerService`, the only ledger writer).
   Implemented in phase 5 via `GenerationLifecycleService::transition(…, $effects)`: the charge runs inside the
   same lock and transaction as the `→ succeeded` change, and only if that change is allowed.
6. Commit.

Failure path: `→ failed` writes no ledger row and `actual_*` stays `NULL` (shown as "$0.00 — not charged").

Lock order is always **generation → user**, so it can't deadlock with `CreateGenerationAction`
(which locks only the user).

## Alternatives considered

- **Check-then-insert without a unique constraint**: two concurrent workers can race. Rejected.
- **Charge at submission, refund on failure**: "failed costs nothing" becomes "charged then refunded",
  which is noisier in the ledger and the report and confuses users. Rejected.
- **Charge outside the status transaction (event listener after commit)**: you can end up with
  status updated but charge missing. Rejected in favour of one atomic transaction.

## Consequences

### Positive

- Correctness is enforced by the database (unique key + row lock + transaction), not only by app logic.
- The ledger gives an append-only audit trail that the admin report can sum.

### Drawbacks

- Until balances ship (phase 9, ADR-0011), charges make every user's cached balance negative. Purchases and
  enforcement arrive with that phase.
- A unique-key violation on `charge:{id}` is not caught. It can only happen through a bug, and when it does it
  surfaces as a failed job instead of being silently absorbed. No double charge is possible either way.

- Long transactions if usage has to be fetched from the API. The fetch must happen **before**
  opening the transaction, never inside it.
- The unique-violation path must be handled (caught → treated as already settled), or a duplicate
  shows up as a failed job.
- If Pixelforge's reported `usage` were wrong, our stored cost would be wrong too. We trust the provider's
  numbers, and the admin report's `/v1/usage` comparison (ADR-0012) is what would reveal a mismatch.
  Fixing a recorded charge needs a manual `adjustment` entry.
