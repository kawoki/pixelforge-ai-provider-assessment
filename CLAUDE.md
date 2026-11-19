# PixelForge Assessment: Project Guide for Claude

Take-home assessment (see `assessment.md`): a Laravel + Vue + Inertia app where signed-in users generate
images through the **Pixelforge** async API, see live status, and are charged **Pixelforge cost + 25%**.
Webhooks are verified and handled idempotently.

**Grading priorities, in order:** correct money → robustness → clear UX (what it costs, what's happening,
what went wrong) → readable/changeable code. Visual design is not a priority. Usability, extensibility,
maintainability and security are.

## Key documents (read before changing related code)

- `docs/PLAN.md`: phases, data model, directory layout, flows, routes, QA plan
- `docs/FEATURES.md`: feature list with acceptance criteria and drawbacks
- `docs/adr/`: architecture decisions (each one lists its drawbacks)
- **`PIXELFORGE_API.md`**: the Pixelforge API docs, verbatim. **The source of truth for every API detail.**
  Never invent API behaviour. If the docs don't say, make a documented decision. Don't reformat this file.
- `docs/PIXELFORGE-OPEN-QUESTIONS.md`: resolved questions, key API facts, and where the API shape lives in code.

## Pixelforge essentials (details in `PIXELFORGE_API.md`)

- Base `https://pixelforge.promptedit.dev/v1`, Bearer auth (`/v1/pricing` needs none). Only send documented
  params: unknown ones get `422`.
- Async: `POST /v1/generations` → `202 queued` → webhook or polling. Statuses `queued → processing →
  succeeded | failed`. Terminal states are final.
- Always send `Idempotency-Key` (our `client_reference`, persisted before the call) + `metadata.client_reference`.
- **Branch on `error.code`**, never on message text or HTTP status alone.
- Rates are USD **per 1M tokens**, fixed on each generation (`rates`). Cost = Σ `usage.<type>` × `rates.<type>` / 1e6,
  never rounded. Token types: `input_text`, `input_image`, `output`, `reasoning`. Failed generations are never billed.
- Account limits (shared by all keys): 60 req/min, 3 concurrent generations, 500/day UTC.
- Webhooks: `Pixelforge-Signature: t=…,v1=…` = HMAC-SHA256 of `"t.rawBody"`, 300 s tolerance; at-least-once,
  unordered, may arrive before the create call returns, not guaranteed (so keep the polling fallback).
- Images expire 24 h after completion. `/v1/usage` bills on the UTC day a generation **completed**.
- Test keywords in prompts: `[blocked]`, `[overloaded]`, `[fail]`, `[succeed]`, `[slow]`, `[duplicate]`.

## Stack

Laravel 13 (PHP 8.4 locally, ^8.3 required) · Vue 3 + TypeScript (`<script setup>`) · Inertia v3 · Wayfinder (typed routes) · Fortify (auth) · Laravel Vue
starter kit · PostgreSQL (everywhere, including tests) · database queue + scheduler · `brick/math` ·
Pest · Larastan (level 7) · Pint · oxlint + oxfmt (via `vite-plus`) · vue-tsc · Laravel Cloud.

## Commands

- Dev: `composer dev` (server + queue + scheduler + Reverb + vite + logs)
- Settle generations whose webhooks never arrived: `php artisan pixelforge:reconcile` (also runs every minute)
- Grant/revoke admin (spend report + alert emails): `php artisan users:admin {email}` / `--revoke`
- Demo accounts: `php artisan db:seed` (`demo@example.com` + admin `admin@example.com`; idempotent)
- Refund / goodwill credit: `php artisan credits:adjust {email} {amount} --reason="…" [--generation=…]`
- Env worth knowing: `ADMIN_ALERT_EMAILS`, `HEALTH_CHECK_TOKEN` (bearer for `/health/background`),
  `CSP_REPORT_ONLY` / `CSP_ENABLED`, `DEMO_USER_PASSWORD` / `DEMO_ADMIN_PASSWORD`
- Full CI suite locally: `composer ci:check` (frontend lint/format + types + Pint + PHPStan + Pest)
- Tests only: `php artisan test` (uses the `pixelforge_testing` Postgres DB)
- PHP format: `composer lint` (fix) / `composer lint:check` · Static analysis: `composer types:check`
- Frontend: `npm run check` (oxlint + oxfmt) / `npm run check:fix` · `npm run types:check` · `npm run build`
- Local Postgres: Homebrew `postgresql@17` (`brew services start postgresql@17`), DBs `pixelforge` + `pixelforge_testing`
- Local webhooks: run a tunnel (Expose/ngrok/cloudflared) and set its URL in the Pixelforge dashboard for the **local** API key

---

## Backend rules (mandatory)

### Layering: see ADR-0002

```
Controller → FormRequest → Action → Service(s) → Models / Contracts / Value objects
```

- **Controllers are thin**: take validated input, call **one Action**, return a response. No business
  logic and no queries beyond route-model binding.
- **Every use case is an Action** in `app/Actions/<Domain>/<Verb><Noun>Action.php` with one public
  method `execute(...)`. Actions orchestrate: transaction boundary, call services, dispatch jobs.
  Controllers, jobs, commands and webhook handlers enter the domain **only through Actions**.
- **Business logic lives in Services** in `app/Services/<Domain>/…Service.php`: stateless,
  constructor-injected, no `request()`/session/auth helpers inside.
- **Validation and authorization** live in FormRequests (`authorize()` + `rules()`) and Policies/Gates.
- **External APIs** go behind interfaces in `app/Contracts` (e.g. `PixelforgeClient`), with the
  implementation in `app/Services/Pixelforge`. Bind it in a service provider and use a fake in tests.
- **DTOs**: `final readonly` classes in `app/Data` for anything that crosses layers. No raw arrays
  passed between Actions and Services.
- **Enums** in `app/Enums` carry behaviour (`GenerationStatus::canTransitionTo()`, `isTerminal()`).
- **Jobs** are thin: they call an Action and handle retry/release only.

### Method size and separation of concerns

- **No method or function body longer than 15 lines** (PHP and TypeScript). Split by concern into
  well-named private methods or a separate service.
    - PHP: enforced by the custom PHPStan rule `tests/PHPStan/MethodLengthRule.php` (identifier
      `pixelforge.methodLength`, first-to-last statement). Migrations are exempt. Don't suppress it, split the method.
    - TypeScript: a review rule, not linted (oxlint counts nested functions, so every composable
      would fail). Each inner function in a composable or `<script setup>` must stay ≤ 15 lines.
- One reason to change per class. If a service needs "and" to describe it, split it.
- Prefer early returns over nested conditionals.
- `declare(strict_types=1);`, typed properties/params/returns everywhere, `final` classes by default.

### Money: see ADR-0003, ADR-0004, ADR-0008

- **Never use floats for money.** Use the `Usd` value object (`app/Support/Money/Usd.php`, backed by
  `brick/math`) and `UsdCast`. DB columns are `numeric(24,12)`.
- Store exact values. Round **only for display** via `Usd::format()`.
- Money reaches the frontend as strings plus server-formatted display strings. **The frontend never does money math.**
- All price calculations go through `PricingService`. Estimates use the live published pricing
  (`PricingCatalogRepository`). Settlement uses the rate card **snapshotted on the generation** (Pixelforge's
  rates for that generation + our markup at creation), never the current pricing or config.
- Charges are written **only** by `SettlementService` inside the `→ succeeded` transition, with a ledger
  row keyed `charge:{generation_id}` (unique). Failed generations never get a charge.
- Balance changes go only through `CreditLedgerService` (ledger insert + cached balance in one transaction).

### Concurrency & idempotency: see ADR-0005..0008, ADR-0010

- Status changes go only through `GenerationLifecycleService`. Transitions are forward-only (by rank).
  Terminal states (`succeeded`, `failed`) are immutable.
- Lock rows with `lockForUpdate()` inside `DB::transaction`. Lock order is **generation → user**.
- Never call the Pixelforge API inside an open DB transaction.
- Webhooks: verify signature on the raw body → insert into `webhook_events` (unique `provider_event_id`)
  → return 2xx fast (< 10 s) → process in a job. A duplicate is a no-op 2xx.
- Webhooks and reconciliation polling share `ApplyProviderUpdateAction`. Don't create a second update path.
- Submissions send `client_reference` as the idempotency key, and every retry reuses it. Retry only when
  `exception->isRetryable()`, honouring `Retry-After`. `daily_limit_exceeded` is not retryable.

### Reliability: see ADR-0009, ADR-0015

- Status changes are pushed to browsers only via `GenerationLifecycleService` (after commit, never queued).
  Never let a broadcast failure affect the change itself.
- Anything that can silently lose revenue or strand users must raise an `AdminAlertService` alert (`AlertType`).
  Alerts are recorded in `admin_alert_logs` (query builder, no model). The table isn't shown in the UI (yet).
- Pixelforge responses that don't change are cached only in `CachedPixelforgeClient` (arrays only). Never cache
  generation lookups.
  Admin alert emails are sent synchronously, **never queued**.
- The app must keep working with the queue worker, scheduler or Reverb down: fallback paths must stay idempotent
  (same `Idempotency-Key`, forward-only status, unique charge key).

### Security: see ADR-0013

- Every model has a Policy. Always scope user queries with `whereBelongsTo($user)`.
- `$fillable` allowlists. `is_admin` and `balance` are never fillable.
- Secrets only in env, never logged, never shared to Inertia props.
- Reference image URLs: validated `https` URLs, never fetched server-side.
- No `v-html`. Rate-limit the generate, estimate, purchase and webhook routes.

### Testing: see ADR-0014

- Pest, Postgres, `RefreshDatabase`, `Http::preventStrayRequests()`.
- Every money/idempotency rule gets a test: duplicate webhook → one charge; out-of-order → correct
  final state; failed → $0; bad signature → 401; other user → 403.
- Write or update tests in the same change as the code.

---

## Frontend rules

- Pages in `resources/js/pages`, reusable UI in `resources/js/components`, logic in `resources/js/composables`.
- Composition API + TypeScript, typed props/emits, shared types in `resources/js/types`.
- Functions ≤ 15 lines. Pages compose components and contain no business logic.
- Use Inertia `useForm` for forms (built-in errors and `processing` to stop double-submits).
- Live status uses Inertia polling with partial reloads (ADR-0009). Stop polling when all visible items are terminal.
- UX must always make clear: **what it will cost (estimate), what's happening (status), what it
  actually cost, and what went wrong (failure reason)**. Plain styling is fine.

## Working agreements

- Every new architectural decision gets an ADR in `docs/adr/` with a **Drawbacks** section.
  Update `docs/adr/README.md`.
- New features go into `docs/FEATURES.md` with acceptance criteria and drawbacks.
- If `PIXELFORGE_API.md` is updated, re-check the mappers, `tests/Support/PixelforgeFixtures.php` and the affected ADRs.
- When something is unclear, make a reasonable, documented decision (the brief expects this).
- All timestamps are stored in UTC. The pgsql connection forces `timezone = UTC`; don't remove it (billing days are UTC).
