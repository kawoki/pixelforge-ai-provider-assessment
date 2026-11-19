# ADR-0006: Generation lifecycle state machine

- Status: Accepted (status names confirmed by PIXELFORGE_API.md)
- Date: 2026-11-19

## Context

Webhooks arrive twice or **out of order**. A `processing` event can arrive after `succeeded`.
Polling and webhooks can report the same transition. The app must still "reach the right state".

## Decision

`App\Enums\GenerationStatus` with a **rank**, and only forward transitions allowed:

| Status       | Rank | Terminal | Shown to user as                    |
| ------------ | ---- | -------- | ----------------------------------- |
| `pending`    | 0    | no       | Queued (not yet sent to Pixelforge) |
| `queued`     | 1    | no       | Queued                              |
| `processing` | 2    | no       | Processing                          |
| `succeeded`  | 3    | **yes**  | Succeeded                           |
| `failed`     | 3    | **yes**  | Failed (+ reason)                   |

Rules (in `GenerationLifecycleService`):

1. A transition is applied only if `new.rank > current.rank`. Equal or lower rank → **ignored** (logged at debug).
2. Terminal states are **immutable**. The first terminal state wins. A conflicting later terminal
   event (e.g. `failed` after `succeeded`) is ignored and logged as a warning.
3. Skipping states is allowed (`queued → succeeded` when `processing` never arrives).
4. All transitions happen inside a transaction with `lockForUpdate()` on the generation row, so two
   workers can't both apply a terminal transition.
5. Entering `succeeded` triggers settlement (ADR-0008) **in the same transaction**. Entering `failed`
   records the failure reason and releases any reservation (ADR-0011). It never charges.
6. Timestamps: `submitted_at`, `started_at`, `completed_at`, `last_provider_event_at`.

## Alternatives considered

- **Last-write-wins on status**: out-of-order events would corrupt state. Rejected.
- **Order by provider event timestamp/sequence**: more precise if the docs provide a sequence (Q8).
  It can be added as an extra guard later, while the rank rule stays the main one.
- **spatie/laravel-model-states**: full-featured, but adds a dependency and magic for 5 states. An enum + service is enough.

## Consequences

### Positive

- Duplicate and out-of-order events can be applied any number of times, in any order, and end in the same state.
- Easy to unit-test with a dataset of (current, incoming) → expected.

### Drawbacks

- Relies on the documented guarantee that `succeeded`/`failed` are final (confirmed). A retry of a failed
  generation is a **new** generation at Pixelforge, never a revived one.
- Ignored events are silent to users. We rely on logs to debug unexpected sequences.
- Row locks serialize updates per generation. That's fine at this scale, but it's a contention point.
