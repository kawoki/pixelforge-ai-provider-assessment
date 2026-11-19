# ADR-0010: Reconciliation of stale generations

- Status: Accepted (implemented in phase 7, verified against real Pixelforge data)
- Date: 2026-11-19

## Context

Webhooks can be lost, delayed, or rejected (bad deploy, wrong secret, tunnel down). A generation
must not stay `queued`/`processing` forever, and its cost must still be recorded.

## Decision

The artisan command `pixelforge:reconcile` → `ReconcileStaleGenerationsAction`, scheduled **every minute**
(`withoutOverlapping`, `onOneServer`) and also runnable by hand:

1. **Poll silent generations.** `StaleGenerationFinder::awaitingProvider()` returns non-terminal generations with a
   `provider_generation_id` whose last contact (`COALESCE(last_provider_event_at, submitted_at, created_at)`)
   is more than 120 s ago, oldest first, at most 10 per run.
2. For each: `PixelforgeClient::getGeneration()` → `ApplyProviderUpdateAction`, the **same path as webhooks**,
   so it settles exactly once even if the webhook turns up later. Each poll counts as contact, so a still-running
   generation is re-polled at most every 2 min.
3. **Rate limits:** any retryable error (429/503/timeout) stops the run; the next run continues. A non-retryable one
   (e.g. `not_found`) is logged and counted as contact, so that generation can't starve the others.
4. **Lost submissions:** a `pending` generation with no provider id older than 40 min has outlived its submission
   job (30 min window), e.g. because a worker died. It is **requeued**, not failed. The same `Idempotency-Key`
   means Pixelforge either replays the existing generation or creates it now, so nothing is billed twice.
   `SubmitGenerationJob` is `ShouldBeUnique` per generation (35 min), so a requeue never runs next to a live job.
5. Locally, `composer dev` runs `schedule:work` (registered with `DevCommands`); Laravel Cloud runs the scheduler.

_Verified 2026-11-19: a generation submitted before the webhook URL was configured was stuck `queued`. The first
run settled it at Pixelforge's completion time, and the day's provider cost then matched `GET /v1/usage` exactly
($0.167082500)._

## Alternatives considered

- **Rely on webhooks only**: one lost webhook means a generation stuck forever and cost never recorded. Rejected.
- **Poll everything constantly instead of webhooks**: wastes rate-limit budget and ignores a requirement.

## Consequences

### Positive

- Self-heals from missed webhooks. Correct state is eventually reached either way.

### Drawbacks

- Up to 10 requests/min from the shared 60/min account budget while many generations are silent, plus the
  submission retries. A busy minute can make submissions wait slightly longer.
- A generation Pixelforge can never return (`not_found`) is retried every 2 min forever and logs a warning each
  time. It needs an operator to step in (rare: it means the key changed or the record is wrong).
- A requeued lost submission may run 40+ minutes after the user asked. It's still free unless it succeeds, but it
  could surprise them.

- Uses API quota and can compete with submissions under rate limits.
- Needs the scheduler running in every environment. If it's forgotten in production, the job silently does nothing.
- Threshold tuning: too short wastes calls on `[slow]` generations, too long delays the recovery the user sees.
