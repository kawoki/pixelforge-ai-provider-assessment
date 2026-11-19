# Implementation Plan

Source: `assessment.md`. Decisions: `docs/adr/`. Features: `docs/FEATURES.md`.
Pixelforge API: `PIXELFORGE_API.md` (source of truth); resolved questions in `docs/PIXELFORGE-OPEN-QUESTIONS.md`.

**Priorities, in order:** correct money → robustness → clear UX → code quality → polish (last).

---

## 1. Phases

| Phase | Goal                                                                                 | Features         | Done when                                 |
| ----- | ------------------------------------------------------------------------------------ | ---------------- | ----------------------------------------- |
| 0 ✅  | Read the Pixelforge docs, answer Q1–Q16, create a local API key + tunnel             | –                | Key + tunnel working                      |
| 1 ✅  | Scaffold & tooling                                                                   | F-00, F-01       | CI green on an empty app                  |
| 2 ✅  | Domain core: `Usd`, enums, migrations, models, factories, `PricingService`           | F-02             | Pricing unit tests green                  |
| 3 ✅  | Pixelforge client (contract + HTTP impl + fake), typed exceptions                    | F-04 (part)      | `Http::fake` contract tests green         |
| 4 ✅  | Generate page + estimate endpoint + `CreateGenerationAction` + `SubmitGenerationJob` | F-03, F-04       | Real generations queued on Pixelforge     |
| 5 ✅  | Webhooks + lifecycle + settlement                                                    | F-05, F-06       | Real signed webhooks received and settled |
| 6 ✅  | History page: Reverb push, polling fallback, background health + alerts              | F-07, F-15       | Status updates live, costs shown          |
| 7 ✅  | Reconciliation job                                                                   | F-08             | Works with webhooks disabled              |
| 8 ⏸   | Deploy + seed demo data + check against `/v1/usage` (postponed by product owner)     | F-09             | Public URL, numbers match                 |
| 9 ✅  | Stretch: balances & buy credits                                                      | F-10             | Invariant tests green                     |
| 10 ✅ | Stretch: admin report + `/v1/usage` reconciliation                                   | F-11             | Report matches Pixelforge                 |
| 11 ◐  | Hardening: F-12/F-13/F-16 ✅, README (setup, decisions, tradeoffs) still to do       | F-12, F-13, F-16 | README written                            |

**Drawback of this plan:** it's larger than the brief's 2–4h estimate. If time-boxed, phases 0–8
are the required minimum. Do not cut tests from phases 2, 5 and 6, because those tests are what proves
the money is correct.

---

## 2. Data model (Postgres)

### `users` (starter kit +)

| column   | type                     | notes                                     |
| -------- | ------------------------ | ----------------------------------------- |
| is_admin | boolean default false    | not fillable                              |
| balance  | numeric(24,12) default 0 | stretch, cached from ledger, not fillable |

### `generations`

| column                                                         | type                       | notes                                             |
| -------------------------------------------------------------- | -------------------------- | ------------------------------------------------- |
| id                                                             | ulid PK                    | non-enumerable                                    |
| user_id                                                        | FK users, index            |                                                   |
| client_reference                                               | uuid **unique**            | our idempotency key / metadata sent to Pixelforge |
| provider_generation_id                                         | string **unique nullable** | set after acceptance                              |
| prompt                                                         | text                       |                                                   |
| size, quality                                                  | string                     | validated against config                          |
| reference_image_urls                                           | jsonb default `[]`         |                                                   |
| status                                                         | string (enum)              | `pending/queued/processing/succeeded/failed`      |
| failure_code, failure_message                                  | string nullable            | user-readable message                             |
| image_url                                                      | text nullable              |                                                   |
| image_expires_at                                               | timestampTz nullable       | `image_url_expires_at` (completion + 24 h)        |
| rate_card                                                      | jsonb                      | snapshot at creation                              |
| estimated_user_cost_min, estimated_user_cost_max               | numeric(24,12)             | up-front range shown (ADR-0004)                   |
| usage                                                          | jsonb nullable             | token counts reported by provider                 |
| actual_provider_cost, actual_user_cost                         | numeric(24,12) nullable    | null until succeeded. Stay null when failed       |
| submission_attempts                                            | smallint default 0         |                                                   |
| submitted_at, started_at, completed_at, last_provider_event_at | timestampTz nullable       |                                                   |
| timestamps                                                     |                            |                                                   |

Indexes: `(user_id, created_at)`, `(user_id, status)`, `(status, last_provider_event_at)`, `(status, completed_at)`.

`rate_card` holds `{version, markup, rates}`, so the markup is part of the snapshot (there's no separate `markup_rate` column).
CHECK constraints: valid `status`; `actual_*` set **iff** `succeeded`; costs non-negative. `user_id` uses `RESTRICT` on delete.

### `webhook_events`

| column                 | type                   | notes                 |
| ---------------------- | ---------------------- | --------------------- |
| id                     | bigint PK              |                       |
| provider_event_id      | string **unique**      | event-level dedup     |
| type                   | string                 |                       |
| provider_generation_id | string nullable, index |                       |
| payload                | jsonb                  | raw, for audit/replay |
| received_at            | timestampTz            |                       |
| processed_at           | timestampTz nullable   |                       |
| processing_error       | text nullable          |                       |
| attempts               | smallint default 0     |                       |

### `credit_transactions` (ledger, append-only)

| column          | type              | notes                                       |
| --------------- | ----------------- | ------------------------------------------- |
| id              | bigint PK         |                                             |
| user_id         | FK, index         |                                             |
| generation_id   | FK nullable       |                                             |
| type            | string (enum)     | `purchase`, `charge`, `adjustment`          |
| amount          | numeric(24,12)    | signed: purchases +, charges −              |
| balance_after   | numeric(24,12)    |                                             |
| idempotency_key | string **unique** | `charge:{generation_id}`, `purchase:{uuid}` |
| meta            | jsonb nullable    | e.g. pack id                                |
| created_at      | timestampTz       | no `updated_at`, rows are never updated     |

---

## 3. Directory structure (backend)

```
app/
├── Actions/
│   ├── Generations/
│   │   ├── EstimateGenerationCostAction.php
│   │   ├── CreateGenerationAction.php
│   │   ├── SubmitGenerationToProviderAction.php
│   │   ├── ApplyProviderUpdateAction.php        ← shared by webhooks + reconciliation
│   │   └── ReconcileStaleGenerationsAction.php
│   ├── Webhooks/
│   │   ├── StoreWebhookEventAction.php
│   │   └── ProcessWebhookEventAction.php
│   ├── Credits/PurchaseCreditsAction.php
│   └── Reports/BuildSpendReportAction.php
├── Services/
│   ├── Pricing/{PricingService, TokenEstimator, PricingCatalogRepository}.php
│   ├── Generations/{GenerationLifecycleService, SettlementService, GenerationQueryService}.php
│   ├── Credits/{CreditLedgerService, BalanceService}.php
│   ├── Pixelforge/{PixelforgeHttpClient, PayloadReader, GenerationPayloadMapper, PricingPayloadMapper, ProviderErrorMapper, WebhookSignatureVerifier}.php
│   └── Reports/{SpendReportService, ProviderUsageService}.php
├── Contracts/PixelforgeClient.php
├── Data/{GenerationRequestData, CostBreakdownData, CostEstimateData, CostLineData, TokenUsageData, TokenUsageRangeData,
│         TokenRulesData, PricingCatalogData, ProviderGenerationData, RateCardData}.php
├── Enums/{GenerationStatus, CreditTransactionType, TokenType}.php
├── Exceptions/Pixelforge/{PixelforgeException, RateLimitedException, ConcurrencyLimitException, DailyLimitExceededException,
│         ProviderUnavailableException, ProviderTimeoutException, InvalidRequestException, IdempotencyKeyReusedException,
│         ProviderAuthException, GenerationNotFoundException, UnexpectedProviderResponseException}.php
├── Http/
│   ├── Controllers/{GenerationController, GenerationEstimateController, PixelforgeWebhookController, CreditController, Admin/SpendReportController}.php
│   ├── Requests/{StoreGenerationRequest, EstimateGenerationRequest, PurchaseCreditsRequest, SpendReportRequest}.php
│   ├── Middleware/{VerifyPixelforgeSignature, SecurityHeaders}.php
│   └── Resources/GenerationResource.php          ← shape sent to Inertia (money as strings + formatted)
├── Jobs/{SubmitGenerationJob, ProcessWebhookEventJob, ReconcileStaleGenerationsJob}.php
├── Models/{User, Generation, WebhookEvent, CreditTransaction}.php
├── Policies/GenerationPolicy.php
├── Support/Money/{Usd, UsdCast}.php
└── Providers/AppServiceProvider.php              ← binds PixelforgeClient, defines rate limiters + gates
config/pixelforge.php                             ← base_url, key, webhook_secret, tolerance, rates, markup, options, retry policy
```

## 4. Directory structure (frontend)

```
resources/js/
├── pages/
│   ├── generations/{Create.vue, Index.vue}
│   ├── credits/Index.vue
│   └── admin/reports/Spend.vue
├── components/generations/{PromptForm.vue, ReferenceUrlList.vue, CostEstimate.vue, GenerationCard.vue, StatusBadge.vue, GenerationImage.vue}
├── composables/{useCostEstimate.ts, useGenerationPolling.ts}
└── types/{generation.ts, money.ts}
```

Frontend rules: no money math (display server-formatted strings), no `v-html`, functions ≤ 15 lines,
logic in composables, pages only compose components.

---

## 5. Key flows

### Generate

```
POST /generations → StoreGenerationRequest → GenerationController@store
  → CreateGenerationAction::execute(user, GenerationRequestData)
      DB::transaction:
        PricingService::estimate(request, PricingCatalogRepository::current()) → min–max range
        [stretch] BalanceService::assertCanAfford(user locked, estimate max)
        snapshot rate_card (current published rates + markup), estimated_user_cost_min/max
        Generation::create(status=pending, client_reference=uuid)
      afterCommit: SubmitGenerationJob::dispatch(generation)
  → redirect /generations with flash
```

### Submit (job)

```
SubmitGenerationJob → SubmitGenerationToProviderAction
  → PixelforgeClient::createGeneration(data, idempotencyKey=client_reference)
     ok            → LifecycleService::transition(→queued, provider_generation_id), rate_card ← generation's own rates
     Retryable     → job->release(retryAfter ?? backoff)
     NonRetryable  → LifecycleService::transition(→failed, reason)
  failed() hook (retries exhausted) → →failed "Pixelforge is busy"
```

### Webhook

```
POST /webhooks/pixelforge → VerifyPixelforgeSignature (t=…,v1=… HMAC of "t.rawBody", 300 s; bad → 4xx)
  → PixelforgeWebhookController → StoreWebhookEventAction (unique evt_ id; dup → 2xx no-op)
  → 204 (< 10 s) + ProcessWebhookEventJob
      → ProcessWebhookEventAction → GenerationPayloadMapper(data.object) → ApplyProviderUpdateAction
         (ping / unknown types → mark processed, ignore)
```

### Apply provider update (webhook + reconciliation)

```
ApplyProviderUpdateAction::execute(ProviderGenerationData)
  find generation by provider id, else by metadata.client_reference (webhook can beat the create response)
  not found → retry later (reconciliation catches the rest)
  DB::transaction:
     lock generation; LifecycleService::canTransition? else ignore
     succeeded → SettlementService::settle(usage × the generation's own rates) (ledger charge:{id}, balance)
                 completed_at ← Pixelforge's completed_at (billing day for /v1/usage)
     failed    → record reason (no charge)
     other     → status + timestamps
```

---

## 6. Routes

| Method | URI                     | Name                 | Middleware                                        |
| ------ | ----------------------- | -------------------- | ------------------------------------------------- |
| GET    | `/generations`          | generations.index    | auth, verified                                    |
| GET    | `/generations/create`   | generations.create   | auth, verified                                    |
| POST   | `/generations`          | generations.store    | auth, verified, throttle:generate                 |
| POST   | `/generations/estimate` | generations.estimate | auth, throttle:estimate                           |
| GET    | `/credits`              | credits.index        | auth (stretch)                                    |
| POST   | `/credits/purchases`    | credits.purchase     | auth, throttle:purchase (stretch)                 |
| GET    | `/admin/reports/spend`  | admin.reports.spend  | auth, can:viewAdminReports (stretch)              |
| POST   | `/webhooks/pixelforge`  | webhooks.pixelforge  | pixelforge.signature, throttle:webhooks (no CSRF) |

---

## 7. Manual QA against real Pixelforge

| Scenario                 | How                                         | Expected                                                                    |
| ------------------------ | ------------------------------------------- | --------------------------------------------------------------------------- |
| Happy path               | prompt with `[succeed]`                     | queued → processing → succeeded, actual cost within estimate                |
| Rejected at submission   | `[blocked]`                                 | failed immediately, Pixelforge's policy message shown, $0                   |
| Overloaded at submission | `[overloaded]`                              | retried with backoff (≥ Retry-After), gives up after 5 refusals, failed, $0 |
| Failure after acceptance | `[fail]`                                    | → failed, reason shown, $0 charged, no ledger row                           |
| Slow                     | `[slow]`                                    | stays processing 60–90 s, live updates keep coming, then succeeds           |
| Duplicate webhook        | `[duplicate]` + dashboard "resend"          | one ledger row; duplicate delivery answered 2xx                             |
| Out-of-order             | resend `generation.processing` after finish | status stays terminal                                                       |
| Concurrency limit        | submit 5 at once                            | 3 run, others wait on 429 + Retry-After 5 s, all resolve                    |
| Missed webhook           | stop tunnel, generate, restart              | reconciliation settles it                                                   |
| Bad signature            | curl with wrong signature                   | 4xx, nothing stored                                                         |
| Usage check              | compare report vs `GET /v1/usage`           | exact match per UTC completion day                                          |

---

## 8. Risks & drawbacks (whole plan)

- **Docs read (2026-11-19).** All API facts now come from `PIXELFORGE_API.md`. The remaining risk is
  the simulator's deliberate misbehaviour (random failures, overloads, duplicate events), which the design
  handles and the QA table exercises.
- **Shared account limits.** 3 concurrent / 60 per minute / 500 per day are shared by local and deployed keys.
  Heavy local testing can slow the deployed demo.
- **Scope vs time.** The layering + ADR approach costs more upfront than a minimal solution.
- **Operational dependencies.** Queue worker + scheduler must run everywhere. Missing either is the most likely production failure.
- **Fractional-cent charges.** Defensible for internal credits, but a real payment system would need a rounding policy.
- **Polling UI.** Simple, but not real-time and not efficient at scale.
- **No image mirroring.** Images disappear when provider URLs expire (allowed by the brief).

---

## 9. Implementation notes (decisions made while building)

- **Phase 4:** live estimates use Inertia v3's `useHttp` (CSRF and JSON built in, no axios). After login, users
  land on the Generate page (`fortify.home`); the placeholder dashboard route stays for the kit's tests. Backend
  tests call `withoutVite()` so they don't depend on a frontend build. `composer dev` runs
  `queue:listen --tries=1`, and the job's `retryUntil` overrides `--tries`, so retries work locally. The scheduler
  is not part of `composer dev` (relevant for phase 7).
- **Phase 5:** webhook routes live in `routes/webhooks.php`, loaded outside the `web` group. Settlement runs as an
  `$effects` closure of the lifecycle transition, so status, costs and ledger entry commit together. The pgsql
  connection is forced to UTC (a local `Asia/Manila` server had shifted timestamps 8 h). Inertia SSR is
  configurable (`INERTIA_SSR_ENABLED`) and off in tests, because a running `composer dev` made tests call the Vite SSR endpoint.
- **Phase 7:** done before phase 6 (correct money first). A real run found a generation submitted before the
  webhook URL existed; reconciliation settled it and the day matched `/v1/usage` exactly. Reconciliation is an
  artisan command (`pixelforge:reconcile`) so operators can run it by hand.
- **Phase 6:** at the product owner's request, live updates are **pushed** (Reverb, `ShouldBroadcastNow`, after
  commit) with polling as the fallback (ADR-0009, revised). Background health monitoring, degraded-mode catch-up and
  synchronous admin alerts were added (ADR-0015, new F-15). `routes/webhooks.php` became `routes/machine.php`
  (webhook + `/health/background`). `composer dev` also starts `reverb:start`.
- **Phases 9–10:** done before phase 8 (deployment), at the product owner's request. Balances reserve the estimate
  maximum under a user-row lock; purchases are idempotent with a server-issued key. The spend report reconciles
  every day with `/v1/usage` and matched live data exactly. Admins are granted with `php artisan users:admin {email}`.
- **Hardening (2026-11-20):** per-user fair-use caps, lost-generation handling, `credits:adjust`, account
  anonymisation, `CachedPixelforgeClient` (decorator bound to the `PixelforgeClient` contract, so services stay
  cache-free), pricing-drift alert, webhook/alert-log pruning, a separate `admin_alert_logs` table with an admin
  System status page, health-endpoint token, security headers + CSP nonce, and a `DemoSeeder` (F-16).
