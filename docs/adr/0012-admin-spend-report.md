# ADR-0012: Admin spend report (stretch)

- Status: Accepted (implemented in phase 10, verified against live /v1/usage)
- Date: 2026-11-19

## Context

Operators want credit spend (what users were charged), provider cost (what Pixelforge charged us) and
profit, **per day and per user**. Provider costs must match `GET /v1/usage`, which is reported per API key.

## Decision

1. `SpendReportService` aggregates **succeeded** generations with `SUM(actual_provider_cost)`,
   `SUM(actual_user_cost)`, profit = difference, grouped by `DATE(completed_at AT TIME ZONE 'UTC')`
   and `user_id`. `completed_at` is **Pixelforge's** completion time, because Pixelforge bills on the UTC day a
   generation completed.
2. A **reconciliation column** fetches `GET /v1/usage?start_date&end_date` (UTC, inclusive, ≤ 92 days)
   for the same range (cached 1 h for days that are over, 5 min when the range includes today, and refetched as soon
   as one of our generations succeeds, so a fresh charge is never compared with older Pixelforge figures) and compares our provider cost per day with `cost_usd`
   (exact, 9 decimal places). Mismatches are highlighted. `/v1/usage` is per API key, so the deployed
   environment's key is compared with the deployed environment's records.
3. Access: `users.is_admin` (not mass-assignable, set by seeder/artisan command) + a `viewAdminReports`
   Gate + middleware on an `/admin` route group.
4. Filters: date range (default last 30 days, max 92). Totals row.

**Implementation notes (phase 10):**

- `SpendReportQuery` (Postgres sums as text), `ProviderUsageService` (`GET /v1/usage`, `null` when unreachable; caching lives in
  `CachedPixelforgeClient`), `SpendReportAssembler` (pure: every day of the range newest first, per-day users, per-user totals),
  `BuildSpendReportAction`.
- A day **matches** only if both the provider cost (to the last decimal) and the generation count equal Pixelforge's.
  Costs are shown with 9 decimals, like `cost_usd`.
- Access: `can:viewAdminReports` on `/admin/*`. Grant with `php artisan users:admin {email}` (`--revoke` to remove).
  The sidebar link only shows for admins.
- _Verified 2026-11-19:_ 3 generations, $0.167082500 ours = $0.167082500 Pixelforge's; profit exactly 25%.

## Alternatives considered

- **Report from the ledger instead of generations**: equivalent for charges, but provider cost isn't on the ledger.
- **Materialised daily rollup table**: faster at scale, but it's a cache that must be kept correct. Unneeded now.
- **Filament admin panel**: a fast CRUD/report UI, but it's a second UI stack next to Inertia. Rejected for consistency.

## Consequences

### Positive

- Mismatches with Pixelforge are visible immediately. That doubles as a check that our pricing config is correct.

### Drawbacks

- Live aggregate queries get slower with volume (fine with an index on `(status, completed_at)`).
- Day-boundary or attribution mismatch (created vs completed, UTC vs other) makes days that are
  actually correct look wrong until confirmed against the docs.
- Calling `/v1/usage` on page load uses rate-limit budget and makes the report depend on Pixelforge being up.
  The cache softens this, and the report still renders without that column.
- Each successful generation costs one extra `/v1/usage` call, the next time an admin opens the report. If Pixelforge's
  own usage figures lag behind its webhook, a just-finished generation can still show as a brief mismatch.
- A boolean `is_admin` is coarse. Roles/permissions would be needed for more operator types.
