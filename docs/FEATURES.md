# Features

Each feature lists what it does, its acceptance criteria (AC), and its **drawbacks**.
Priority: **P0** = required by the brief, **P1** = stretch goal, **P2** = nice to have.
Correct money comes before polish. A P0 isn't done until its tests pass.

---

## F-00 Project foundation (P0)

Scaffold Laravel 13 + Vue starter kit (Inertia v3, Fortify, Wayfinder, TS), Postgres, Pest, Larastan, Pint, oxlint/oxfmt, vue-tsc,
GitHub Actions CI, `brick/math`, `config/pixelforge.php`, `.env.example` entries.
Custom PHPStan rule enforces the 15-line method rule for PHP. **Status: done (phase 1).**

- **AC**: `composer ci:check` green locally and in CI (Postgres service).
- **Drawbacks**: setup time eats into the 2–4h budget. The TypeScript 15-line rule can't be linted
  (oxlint counts nested functions), so it relies on review. Starter-kit code needed small refactors to pass the PHP rule.

## F-01 Authentication (P0)

Starter-kit (Fortify) register/login/logout/password reset, 2FA, passkeys. All app routes require auth.
**Status: done (phase 1). Comes from the kit, covered by the kit's tests.**

- **AC**: guests are redirected to login. Users only ever see their own data.
- **Drawbacks**: email verification adds friction for reviewers on the deployed app. We'll provide a
  seeded demo account instead of disabling verification.

## F-02 Money & pricing core (P0)

`Usd` value object + cast, `RateCardData`, `TokenEstimator`, `PricingService::estimate()` / `::actual()`, `CostBreakdownData`.
Plus the domain schema (generations, webhook_events, credit_transactions, user billing columns), models and factories.
**Status: done (phase 2), revised after the docs**: four token types, rates per 1M tokens read live from
`/v1/pricing` (cached, config fallback), the generation's own `rates` snapshotted for billing, min–max estimate.
Tests reproduce the docs' worked numbers ($0.373875 max example; `/v1/usage` $0.169080).

- **AC**: unit tests prove exact decimal results for a dataset of usages. Markup is exactly 25%. No floats anywhere.
- **Drawbacks**: depends on the rate card (Q2–Q4). The estimate quality is only as good as the configured expected-token values.
- **Drawbacks (from implementation)**: strict money math means a misconfigured rate card fails loudly
  and blocks all pricing until fixed (by design, but it's an outage instead of a slightly-off price).
  The CHECK constraints and partial unique index are Postgres-specific raw SQL, so switching DB engines
  means rewriting them. Factories bypass `CreditLedgerService`, so factory-made ledger rows don't move `users.balance`.

## F-03 Generate page (P0)

**Status: done (phase 4)**: `generations/Create.vue`, live range via Inertia `useHttp` → `POST /generations/estimate`
(debounced 300 ms, previous request cancelled), per-token-type breakdown, testing-keyword hint. After login
users land here (`fortify.home`).

Prompt textarea, size select, quality select, 0..N reference image URLs (add/remove rows), live cost
estimate **range** with breakdown, submit button. Options come from server config props
(sizes `1024x1024`/`1536x1024`/`1024x1536`, qualities `low/medium/high`, ≤4 reference URLs, prompt ≤4,000 chars).

- **AC**:
    - The estimate updates (debounced ~300ms) on every input change and shows a min–max range, why it's a range
      (reasoning tokens vary), and "failed generations are free".
    - Validation errors appear inline. The submit button is disabled while submitting (no double submit).
    - On submit → redirect to History with a flash message "Generation queued".
    - (With F-10) Insufficient balance → button disabled, with an explanation and a "Buy credits" link.
- **Drawbacks**: the server round-trip for the estimate feels slower than client-side math. A range (wide for
  high quality) is less satisfying than one number. Plain UI.

## F-04 Generation submission pipeline (P0)

**Status: done (phase 4)**, verified against the real API.
`CreateGenerationAction` (server-side estimate, `client_reference` persisted first) → `SubmitGenerationJob`
(`afterCommit`, `retryUntil` 30 min, releases with `Retry-After` + ≤25% jitter, `maxExceptions` 3) →
`SubmitGenerationToProviderAction` (one attempt: accept / reject with a user-readable reason / rethrow retryable).
On acceptance the generation's own `rates` replace the estimate's in the rate-card snapshot. When the retry window
closes, only submissions Pixelforge never accepted are failed (`abandonSubmission`).

`CreateGenerationAction` → `SubmitGenerationJob` → `PixelforgeClient`. Retries and backoff per ADR-0005.
Typed provider exceptions. Idempotent submission.

- **AC**:
    - 429/503 → job released with `Retry-After`/backoff. Retries exhausted → `failed`, no charge.
    - 4xx validation → `failed` with a readable reason, no charge.
    - A timeout never results in two provider generations for one of ours (same `Idempotency-Key` on every retry).
    - `daily_limit_exceeded`, `content_policy_violation`, `validation_failed` → failed at once, not retried.
    - Repeated `overloaded`/rate-limit refusals → failed after 5 tries (~1 min), not after the 30 min window.
    - Every failure message says "You were not charged" exactly once.
- **Drawbacks (client)**: requests/responses aren't logged yet (F-12). The fake can drift from the real API.
  Contract tests use the docs' payloads verbatim (`tests/Support/PixelforgeFixtures.php`), which limits that risk.
  Limits are per account (3 concurrent, 60/min, 500/day), so bursts queue up and retries spend the minute budget.
- **Drawbacks (phase 4)**: if a create response is a replay whose status already advanced (e.g. `succeeded`), we
  only record acceptance. The webhook or reconciliation (phases 5/7) completes it, which adds a little latency
  in a rare case. Our per-user limits (10 submits/min, 120 estimates/min) are guesses.
- **Drawbacks**: needs a queue worker in every environment. Adds latency before "queued". Webhooks can
  arrive before the provider id is saved (documented), so generations must also be matched by `metadata.client_reference`.

## F-05 Webhook endpoint (P0)

**Status: done (phase 5)**, verified with real signed Pixelforge deliveries through a tunnel.
`VerifyPixelforgeSignature` + `WebhookSignatureVerifier` → `ReceiveWebhookEventAction` (unique `evt_` id, 204) →
`ProcessWebhookEventJob` → `ProcessWebhookEventAction` → `ApplyProviderUpdateAction`. Tests post signed payloads
built from the docs' examples.

`POST /webhooks/pixelforge`: verify signature → persist with unique event id → 200 → queued processing.

- **AC**:
    - Bad or missing `Pixelforge-Signature` (`t=…,v1=…`, HMAC-SHA256 of `"t.rawBody"`) or `t` older than 300 s → 4xx, nothing stored.
    - `ping` events are acknowledged and ignored.
    - The same event delivered twice → one `webhook_events` row, one state change, one charge.
    - `succeeded` then `processing` → final state `succeeded`.
    - Event for a not-yet-known generation → processed after retry or by reconciliation.
    - The endpoint responds in under ~200ms (no provider calls inline).
- **Drawbacks**: the table grows (pruning needed). Processing failures aren't visible in the Pixelforge dashboard.

## F-06 Lifecycle & settlement (P0)

**Status: done (phase 5)**: `SettlementService` (usage × the generation's own rates × 1.25, warning if above the
estimate max), `CreditLedgerService::charge`, provider failure messages, matching by `metadata.client_reference`
for webhooks that arrive before the create call returns.

`GenerationLifecycleService` (monotonic transitions), `SettlementService` (exactly-once charge, ledger
entry with unique key), failure handling.

- **AC**: concurrency/duplicate tests prove a single `charge:{id}` ledger row. Failed → no ledger row,
  `actual_*` null. Actual cost is computed from the snapshotted rate card.
- **Drawbacks**: assumes terminal states are final (Q8). Settling with a wrong config rate stores wrong
  numbers consistently (fixed only through adjustments).

## F-07 History page (P0)

**Status: done (phase 6)**: push over Reverb with polling as the fallback (ADR-0009), image thumbnail linking to
the full image, "available until", expired/broken-image placeholders, working/failed states, https-only image URLs.

Paginated list (newest first): prompt (truncated), size/quality, status badge, estimated cost,
actual cost (or "Not charged" / "Pending"), image thumbnail (click → full), failure reason, timestamps.
Live updates via Inertia polling while any item is non-terminal.

- **AC**:
    - Status moves without a manual refresh. Polling stops when everything on the page is terminal.
    - Expired image (24 h after completion) → "Image expired" placeholder (no broken icon).
    - The difference between estimate and actual is visible ("+$0.0006 vs estimate").
- **Drawbacks**: up to 3s latency and continuous requests while generations are in flight. Images vanish after expiry.

## F-08 Reconciliation job (P0)

**Status: done (phase 7)**: `pixelforge:reconcile` every minute; polls silent generations through the webhook
update path, requeues lost submissions, stops on rate limits. Verified on real data (see ADR-0010).

Scheduled `ReconcileStaleGenerationsJob` per ADR-0010.

- **AC**: with webhooks disabled, a generation still reaches its terminal state and is charged once.
  Stuck `pending` items get a clear failure.
- **Drawbacks**: uses rate-limit budget. Silent if the scheduler isn't running.

## F-09 Deployment (P0)

Laravel Cloud: app, Postgres, queue worker, scheduler, env secrets, separate Pixelforge key + webhook
URL for production. Seed a demo user and leave several generations (succeeded, failed, `[slow]`).

- **AC**: the public URL works, webhooks show 2xx in the Pixelforge dashboard, and the numbers match `/v1/usage`.
- **Drawbacks**: platform lock-in and cost. Debugging production webhooks is harder than local.

## F-10 Credit balance & purchases (P1, stretch)

**Status: done (phase 9)**: Credits page (available / balance / held, packs, ledger history), affordability on the
Generate page, `credits` shared prop. See ADR-0011.

Ledger + cached balance (ADR-0011). Balance in header (shared Inertia prop). Buy credits page with
fixed packs. Affordability check against available balance when generating.

- **AC**: `balance == SUM(ledger)` always. Two concurrent submits can't overspend. A double-click on
  "Buy" credits once. Generating reserves the estimate's **max**, so the balance can't go negative.
- **Drawbacks**: reserving the max blocks users who could afford a typical generation. A fake purchase means no
  real payment safety.

## F-11 Admin spend report (P1, stretch)

**Status: done (phase 10)**: `/admin/reports/spend`, per day (UTC completion) × user, totals, per-user totals,
day-by-day match against `/v1/usage`. Verified on live data. See ADR-0012.

`/admin/reports/spend`: per day × per user: charged, provider cost, profit. Totals. Date range filter.
Per-day comparison with `GET /v1/usage`, mismatches highlighted.

- **AC**: our provider cost per UTC completion day equals `/v1/usage` `cost_usd` (9 dp) for the production key.
  Only admins can access it.
- **Drawbacks**: live aggregation and an external API call on page load. Day-boundary assumptions (Q12).

## F-12 Observability (P2)

**Status: done (2026-11-20)**: admin-only **System status** page (`/admin/system`): background health, waiting/failed
jobs and unprocessed webhooks. Every alert is recorded in its own table (`admin_alert_logs`, not `laravel.log`).
Open problems and the alert log are **hidden from the page for now** (product owner's call); the data is still kept.
See ADR-0015.

- **AC**: only admins can open it (others get 403). Every alert email attempt is recorded with its result, including
  failed sends. Alert logging never breaks a request; if the table is unavailable it falls back to the app log.

- **Drawbacks**: more surface area to maintain. Easy to over-build for an assessment.

## F-13 Webhook event pruning (P2)

**Status: done (2026-11-20)**: `MassPrunable` on `WebhookEvent` (processed and older than 60 days), plus alert
log pruning (180 days). Both run daily from the scheduler.

- **Drawbacks**: we lose the audit trail for old disputes. Pick the retention period deliberately.

## F-14 Real-time push via Reverb (P2)

**Status: done (phase 6)**, see F-07 and ADR-0009.

Replace polling with broadcast events (ADR-0009 upgrade path).

- **Drawbacks**: extra infra (Reverb server), channel auth, and more deploy complexity.

## F-15 Background health, degraded mode & admin alerts (P0, added in phase 6)

**Status: done (phase 6)**: see ADR-0015. Heartbeats + backlog detection, `GET /health/background` (200/503),
degraded-mode catch-up from the History page, synchronous admin emails for: background processing down, webhook
signatures rejected, webhooks not arriving, API key rejected, daily limit reached, permanently failed jobs.

- **AC**: with the worker stopped, a user on the History page still sees their generation submitted, finished and
  charged once. Admins get one email per problem per hour plus a recovery email. A broken mailer never breaks a request.
- **Drawbacks**: a dead scheduler with no traffic needs an external monitor of `/health/background`. Degraded mode
  only helps users with the page open. It spends up to 20 Pixelforge requests/min while active.

## F-16 Hardening batch (P1, added 2026-11-20)

**Status: done.**

- **Fair use per user**: max 3 generations in progress and 100 per UTC day (`pixelforge.per_user`). AC: the 4th
  concurrent or 101st daily submission is refused with a plain message; refused submissions don't count. ADR-0011.
- **Lost generations**: a generation Pixelforge reports `not_found` 5 polls in a row is failed at $0 with a clear
  message, and admins are alerted. ADR-0010/0015.
- **Credit adjustments**: `php artisan credits:adjust {email} {amount} --reason=…` (refunds/goodwill) through the
  ledger. AC: balance always equals the ledger sum; the reason is recorded. ADR-0011.
- **Account deletion**: users with history are anonymised, not deleted (keeps generations and ledger for billing
  reconciliation). AC: deleting such an account no longer errors and the person can't sign in again. ADR-0011.
- **Caching unchanging Pixelforge responses**: pricing 1 h, finished days of usage 1 h, today's usage 5 min, with a
  pricing-drift alert. AC: repeated estimates don't call `/v1/pricing` more than once an hour. ADR-0004/0012.
- **Security headers + CSP** with a per-request nonce, report-only switch. ADR-0013.
- **Health endpoint token** (`HEALTH_CHECK_TOKEN`). ADR-0013/0015.
- **Demo accounts**: `php artisan db:seed` creates `demo@example.com` ($10) and the admin `admin@example.com` ($5).
  Idempotent; passwords from `DEMO_USER_PASSWORD` / `DEMO_ADMIN_PASSWORD` (`password` locally, random and printed
  elsewhere).
- **Drawbacks**: caps are global, not per plan; adjustments are CLI only; anonymised users stay in the table; CSP
  allows inline styles and needs updating for new hosts; the demo admin must be removed or re-passworded on a
  public deploy if `DEMO_ADMIN_PASSWORD` isn't set to something strong.
