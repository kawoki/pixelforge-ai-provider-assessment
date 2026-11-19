# ADR-0005: Async submission to Pixelforge with retries

- Status: Accepted (revised 2026-11-19 after reading PIXELFORGE_API.md)
- Date: 2026-11-19

## Context

Pixelforge returns rate-limit errors, concurrency-limit errors and overloads. It can also accept a
generation that fails later. A user's HTTP request should not block on, or fail because of, a
temporary provider error. A **retried submission must never create a second billable generation**.

## Decision

1. `CreateGenerationAction` (HTTP) runs one DB transaction: validate affordability (stretch), create a
   `Generation` row with status `pending` and our own `client_reference` (UUID, **persisted before the call**),
   then commit and dispatch `SubmitGenerationJob`. The user is redirected to History right away.
2. `SubmitGenerationJob` → `SubmitGenerationToProviderAction` → `PixelforgeClient::createGeneration()`:
    - Sends `Idempotency-Key: {client_reference}` and `metadata.client_reference`. A replay with the
      same key returns the existing generation (no new generation, no extra billing), so **every retry
      reuses the key**.
    - On success: store `provider_generation_id` and the generation's `rates` (rate-card snapshot),
      and move to `queued` via the state machine (ADR-0006).
    - **Retryable** (`rate_limit_exceeded`, `concurrency_limit_exceeded`, `overloaded`, other 5xx,
      timeouts): `release()` with delay = `Retry-After` (docs: 5 s for concurrency, 2 s for overload) or
      backoff `2, 5, 15, 30, 60 s` + jitter, within a `retryUntil` of 30 min.
    - **Non-retryable**: `validation_failed`, `content_policy_violation` (show Pixelforge's message),
      `invalid_api_key`, `daily_limit_exceeded` ("daily limit reached, try after 00:00 UTC"),
      `idempotency_key_reused` (our bug, so log as error). Mark `failed` with a readable reason and no charge.
    - Retry window exhausted → `failed` ("Pixelforge is busy, please try again"). No charge.
3. **Account limits are shared across all keys**: 60 req/min (retries count), **3 concurrent
   generations**, 500/day UTC. A burst of submissions will mostly wait on `concurrency_limit_exceeded`.
   That's acceptable at our volume, and the 30-min window covers `[slow]` (60–90 s) generations ahead in line.
4. The client **never retries by itself**. It makes exactly one attempt per call; retry policy lives in the
   job. It maps `error.code` (not HTTP status) to typed exceptions that expose `isRetryable()` and
   `retryAfterSeconds()`.
5. Protect our side too: route rate limit on `POST /generations` (e.g. 10/min per user).

**Revision (2026-11-20, after manual testing with `[overloaded]`):** one 30-minute window for every retryable error was
wrong. A permanently overloaded Pixelforge kept users on "Queued" for 30 minutes, and honouring `Retry-After: 2`
literally meant ~25 requests/min against the shared budget. Retries are now **per error type**
(`SubmissionRetryPolicy`):

| Error                               | Delay                                      | Gives up                                                                                                   |
| ----------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| `concurrency_limit_exceeded`        | `Retry-After` (5 s)                        | when the 30 min window closes (a slot frees soon)                                                          |
| `overloaded`, other 5xx, rate limit | max(`Retry-After`, 2/5/15/30/60 s backoff) | after **5 refusals** (~1 min): "overloaded… try again"                                                     |
| timeouts                            | backoff                                    | window only. **Never early**: Pixelforge may have accepted it, and only a retry with the same key can tell |

If Pixelforge ever reports success for a generation we had given up on (e.g. every attempt timed out but it was
accepted), the status stays failed (terminal), and an `AbandonedGenerationRan` admin alert is raised (ADR-0015).

## Alternatives considered

- **Synchronous submission in the controller**: simpler, but the user sees provider hiccups as errors
  and requests hang on retries.
- **Laravel `Http::retry()` inside the request**: still blocks the user, and retries can't wait for long backoffs.
- **Redis `funnel` concurrency limiter**: precise concurrency control, but needs Redis (ADR-0001).
  Reacting to 429 + `Retry-After` is enough at our volume.

## Consequences

### Positive

- Temporary provider errors are hidden from the user. They see "queued", then the result.
- One place for retry policy. Typed exceptions make the error handling readable and testable.

### Drawbacks

- The give-up counter lives in the cache (1 h), and the backoff step follows the job's total attempts, so a job that
  first waited for a slot backs off at the longest step if it then hits overloads (worst case ~6 min to give up).

- Requires a running queue worker. If the worker is down, generations sit in `pending`
  (so we monitor it and reconciliation surfaces it).
- More moving parts and a race: a webhook can arrive **before** we have saved `provider_generation_id`
  (handled in ADR-0007).
- Concurrency waits are implemented as job releases: up to ~12 polls/min per waiting job against the
  shared 60 req/min budget. A local concurrency gate would be cheaper, but it couldn't see the other environment's
  usage of the same account.
- Users wait slightly longer (queue latency) even when Pixelforge is healthy.
