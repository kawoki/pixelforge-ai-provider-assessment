# ADR-0015: Background health, degraded mode and admin alerts

- Status: Accepted
- Date: 2026-11-19

## Context

Everything that moves a generation forward runs in the background: the queue worker submits generations and
processes webhooks, and the scheduler reconciles lost webhooks (ADR-0010) and records heartbeats. If either
stops, users stare at "Queued" forever (they leave), and images Pixelforge already billed us for are never
charged (we lose revenue). Neither is visible from inside a dead process. The product owner asked us to detect
this, keep users served, email the administrators, and cover other revenue-losing edge cases.

## Decision

**1. Detect.** `BackgroundHealthService` combines two signals:

- **Heartbeats:** every minute the scheduler records its own heartbeat and dispatches `RecordQueueHeartbeatJob`;
  a worker running it records the queue heartbeat. Older than 3 min means that process is down.
- **Backlog:** a job waiting more than 3 min (`creationTimeOfOldestPendingJob`) means no worker is taking jobs.
  This is direct evidence, even if the scheduler is also down.
- A 3-minute grace period after first start avoids false alarms right after a deploy. The verdict is cached for 10 s.

The check runs on every signed-in page (shared `background` prop), on every fallback poll, and on
`GET /health/background` (200/503) for an **external uptime monitor**. That endpoint is the only way to notice a
dead scheduler while nobody is using the app.

**2. Keep users served (degraded mode).** While degraded, the History page polls `POST /generations/catch-up`.
`CatchUpUserGenerationsAction` moves **that user's** in-flight generations forward itself:

- not accepted yet → submit now (same `Idempotency-Key`, so never twice);
- accepted → fetch from Pixelforge → `ApplyProviderUpdateAction` (same path as webhooks, so charged once).

It is a no-op while healthy, and limited to 3 generations per request, once per generation per 15 s and 20 Pixelforge
requests/min globally (the account allows 60). When the worker comes back, its stale jobs are no-ops
(idempotent submit, forward-only status, unique charge key).

**3. Tell operators.** `AdminAlertService` emails admin users plus `ADMIN_ALERT_EMAILS`, **synchronously** (the
queue may be what's broken). Each problem is emailed once and then repeated hourly while it lasts, with a
"Resolved" email when recovery is detectable. Mail failures never break the request. Alerts:

| Alert                       | Trigger                                                                    | Revenue / user risk it covers                      |
| --------------------------- | -------------------------------------------------------------------------- | -------------------------------------------------- |
| Background processing       | scheduler or worker down (above)                                           | stuck generations, unbilled images                 |
| Webhook signatures rejected | ≥5 invalid signatures within 60 min                                        | wrong secret, so every webhook is lost             |
| Webhooks not arriving       | ≥3 generations finished by polling with **no** webhook event ever stored   | missing/wrong webhook URL (happened on 2026-11-19) |
| Provider key rejected       | `invalid_api_key` on submit                                                | every new generation fails                         |
| Daily limit reached         | `daily_limit_exceeded` on submit                                           | every new generation fails until 00:00 UTC         |
| Job failed                  | any job fails permanently (`Queue::failing`)                               | a submission or webhook never applied              |
| Abandoned generation ran    | Pixelforge reports success for a generation we had given up on             | Pixelforge billed us; user was told "not charged"  |
| Generation lost at provider | Pixelforge answers `not_found` for one of our generations 5 polls in a row | a generation that would never finish (failed, $0)  |
| Pricing changed             | live `/v1/pricing` differs from the config fallback copy (hourly check)    | stale estimates if Pixelforge becomes unreachable  |

**3a. Alert log (2026-11-20).** Every alert event is stored in its own table, `admin_alert_logs` (type, event
`opened` / `reminder` / `resolved`, title, details, context, email status `sent` / `failed` / `no_recipients`,
recipients, mail error). It is written with the query builder (no model) and kept out of `laravel.log`. If the insert
itself fails (database down), the alert falls back to `Log::critical`. Rows older than 180 days are pruned daily
(`prune:admin-alert-logs`). The admin **System status** page (`/admin/system`) shows the live health verdict, waiting/failed
jobs and unprocessed webhooks. Open problems and the alert log were shown there too, but are hidden for now at the
product owner's request: the data is still recorded and emailed, just not displayed. An open problem is tracked in the
cache (`alert-open:{type}`), so the page and the "Resolved" email know what is still open.

**3b. Health endpoint access.** `/health/background` requires `Authorization: Bearer {HEALTH_CHECK_TOKEN}` and
answers 404 otherwise.

**4. Tell users.** A plain-language notice: "Updates are running slower than usual… Nothing is lost, and you're
only charged for images that succeed."

## Alternatives considered

- **Horizon / Supervisor status APIs**: Horizon needs Redis (ADR-0001 chose the database queue), and Supervisor
  isn't visible on Laravel Cloud. Heartbeats plus backlog work on any host and any queue driver.
- **Run everything synchronously when the queue is down**: blocks requests on Pixelforge calls and retries for
  every user. Catch-up is per user, bounded, and only while someone is watching.
- **External monitoring only** (Laravel Cloud alerts, Nightwatch, Pulse): useful in addition. The external
  monitor should hit `/health/background`. But in-app detection is what lets the UI switch to fallback mode.
- **Queued alert emails**: rejected. An alert about the queue can't depend on the queue.

## Consequences

### Positive

- No single background process can strand users or lose revenue silently. Every outage either degrades to a
  working fallback or emails a human.
- The alerts cover misconfigurations actually hit during development (secret without URL).

### Drawbacks

- **A dead scheduler with no traffic is only noticed by an external monitor** of `/health/background`. It still
  has to be set up on the host (e.g. Laravel Cloud or UptimeRobot), outside this codebase.
- Degraded mode only helps users who have the History page open. Generations of users who left are caught up by
  reconciliation once the scheduler is back. Until then they wait, though no money is lost.
- Heartbeats live in the cache (database store). If the cache is wiped, a 3-minute grace applies again.
- Up to 20 extra Pixelforge requests/min while degraded, from the shared 60/min budget.
- Hourly reminders can be noisy during a long outage, and recovery emails depend on someone (or the monitor)
  triggering a check after recovery.
- Open-problem state lives in the cache. A cache flush forgets open problems, so no "Resolved" email follows (the
  log still has the `opened` row).
- The alert log grows with every reminder during a long outage (one row per hour per problem). Pruning keeps
  180 days.
- The `background` check runs on every signed-in page view. It's cheap (cached 10 s), but it's on the hot path.
