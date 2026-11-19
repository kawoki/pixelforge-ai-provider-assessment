# ADR-0014: Testing strategy

- Status: Accepted
- Date: 2026-11-19

## Context

Correct money and robustness against provider behaviour are what gets graded. These must be proven
by tests, not by manual clicking.

## Decision

- **Pest**, running against **Postgres** (same engine as prod, ADR-0001), with `RefreshDatabase`.
- **Unit**: `Usd` arithmetic/formatting, `PricingService` (datasets of usage → expected exact
  cost), `GenerationStatus` transitions (dataset of current × incoming → expected),
  `WebhookSignatureVerifier` (valid, tampered body, wrong secret, stale timestamp).
- **Feature**:
    - Webhook: duplicate event → one ledger row. Out-of-order (`succeeded` then `processing`) → stays
      succeeded. Bad signature → 401. Unknown generation → retried later.
    - Settlement: two concurrent settlements → one charge (simulate by calling the action twice and
      asserting the unique key holds).
    - Failed generation → no charge, `actual_*` null.
    - Submission: 429 with `Retry-After` → job released with that delay. 422 → failed, no charge.
    - Authorization: user B can't view user A's generation. Non-admin can't open the report.
    - Balance (stretch): insufficient funds → blocked. `balance == SUM(ledger)` invariant.
- **Provider fake**: `FakePixelforgeClient` bound to the `PixelforgeClient` contract in tests, plus
  `Http::fake()` tests for the real client's HTTP mapping. `Http::preventStrayRequests()` globally.
- **Manual/E2E against real Pixelforge**: prompt keywords `[fail]`, `[slow]`, `[duplicate]` (and any
  others in the docs), and resending events from the Pixelforge dashboard.
- CI (GitHub Actions): Pint, Larastan, oxlint/oxfmt, vue-tsc, Pest with Postgres service.

## Alternatives considered

- **SQLite in-memory tests**: faster, but they can't check locking/numeric behaviour honestly.
- **Browser E2E (Dusk/Playwright)**: valuable later. Not worth the setup time for the required scope.

## Consequences

### Positive

- The grading criteria map directly onto named tests, which also makes the interview walkthrough easier.

### Drawbacks

- A Postgres-backed suite is slower and needs a DB service locally and in CI.
- The fake client can diverge from real Pixelforge behaviour. Contract tests with `Http::fake` using
  payloads copied from the docs reduce, but don't remove, that risk.
- True concurrency (two workers at once) is hard to test deterministically. We rely on DB constraints instead.
