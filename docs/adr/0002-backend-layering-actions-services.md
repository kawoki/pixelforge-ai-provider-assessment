# ADR-0002: Backend layering with Actions and Services

- Status: Accepted
- Date: 2026-11-19

## Context

The code must be easy to read, change and extend live in a technical interview. Business rules
(pricing, state transitions, settlement) must live in one place and be unit-testable without HTTP.

## Decision

Strict layering, top to bottom. A layer may only call the layers below it.

```
Route → Middleware → Controller → FormRequest (validate + authorize)
                         ↓
                      Action        (one use case, one public method `execute()`, transaction boundary)
                         ↓
                      Service(s)    (business logic, stateless, reusable, injected via constructor)
                         ↓
              Models / Contracts (e.g. PixelforgeClient) / Value Objects
```

**Controllers**: resolve input from FormRequest, call **one** Action, return an Inertia/redirect/JSON
response. No business logic and no queries beyond route-model binding.

**Actions** (`app/Actions/<Domain>/<Verb><Noun>Action.php`): a single use case
(`CreateGenerationAction`, `ApplyProviderUpdateAction`, `PurchaseCreditsAction`). They orchestrate:
open the DB transaction, call services, dispatch jobs/events. Jobs, commands and controllers all
enter the domain through Actions, so every path runs the same code.

**Services** (`app/Services/<Domain>/…Service.php`): hold most of the business logic
(`PricingService`, `GenerationLifecycleService`, `SettlementService`, `CreditLedgerService`).
Stateless, constructor-injected, no HTTP or session access.

**Contracts** (`app/Contracts`): interfaces for external systems (`PixelforgeClient`). The
implementation lives under `app/Services/Pixelforge`. A fake is bound in tests.

**Data objects** (`app/Data`): `final readonly` classes for inputs and outputs that cross layers
(`GenerationRequestData`, `TokenUsageData`, `CostBreakdownData`, `ProviderGenerationData`).

**Enums** (`app/Enums`): backed enums with behaviour (`GenerationStatus::canTransitionTo()`).

**Method size rule**: no method/function body exceeds **15 lines**. Split by concern and give each
piece a name. Applies to PHP and TypeScript.

## Alternatives considered

- **Fat models / Active Record logic**: quick to start, but couples persistence and rules and is hard to test.
- **Fat controllers**: fastest for 2–4h, worst for extension and reuse from jobs/webhooks.
- **Full DDD / hexagonal with repositories**: more indirection than this app needs. Eloquent is
  used directly inside services.
- **spatie/laravel-data for DTOs**: less boilerplate and TS generation, but more magic to explain.
  Plain readonly classes are chosen. Can revisit later.

## Consequences

### Positive

- Every entry point (HTTP, webhook job, reconciliation job, artisan) shares one Action, so behaviour is identical.
- Services can be unit-tested in isolation. Actions are tested with a faked `PixelforgeClient`.
- The 15-line limit keeps methods readable and makes each change small.

### Drawbacks

- More files and indirection. A simple feature touches controller + request + action + service + DTO.
- Action and Service can blur. Rule of thumb: _Action = "what happens for this use case"
  (orchestration + transaction)_, _Service = "how a rule works" (reusable logic)_.
- The 15-line limit can produce many tiny private methods, which makes the call flow harder to follow.
  PHP is enforced by a custom PHPStan rule. TypeScript is review-only, because oxlint counts nested functions.
- DTO mapping adds boilerplate, especially at the Pixelforge boundary.
