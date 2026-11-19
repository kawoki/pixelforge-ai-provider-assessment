# ADR-0007: Webhook ingestion: verify, persist, process

- Status: Accepted (revised 2026-11-19 after reading PIXELFORGE_API.md)
- Date: 2026-11-19

## Context

We must verify Pixelforge webhook signatures, and handle events delivered more than once or out of
order. **An event delivered twice must never be recorded twice.** The Pixelforge dashboard shows what
our endpoint answered, so the responses must be correct and fast.

## Decision

Endpoint: `POST /webhooks/pixelforge`, defined in `routes/webhooks.php` and loaded via `withRouting(then:)` **outside the
`web` group** (no session, no cookies, no CSRF), with only `throttle:webhooks` (600/min per IP) and the signature middleware.

1. **Verify** in `VerifyPixelforgeSignature` middleware, using `WebhookSignatureVerifier` (service):
    - Parse `Pixelforge-Signature: t=<unix>,v1=<hex>`.
    - Expected = `hex(HMAC_SHA256(PIXELFORGE_WEBHOOK_SECRET, "<t>.<raw body>"))`, computed on the **raw
      body before JSON decoding**, compared with `hash_equals`.
    - Reject if `|now − t| > 300 s` (each delivery attempt is freshly signed, so retries still pass).
    - Invalid → `400` (as in the docs' example), logged as a warning, nothing stored. No secret configured → fail closed (500).
    - Several `v1` values are accepted, so the sender can rotate secrets.
2. **Persist first** (`StoreWebhookEventAction`): insert into `webhook_events` with a **unique**
   `provider_event_id` (`evt_…`, body `id`). On unique violation it's a duplicate: return `2xx` and do nothing.
   The raw payload (`jsonb`), type and received time are stored for audit and replay.
3. **Acknowledge fast** (Pixelforge needs a `2xx` within 10 s): return `204` right after persisting, then
   dispatch `ProcessWebhookEventJob`.
4. **Process** (`ProcessWebhookEventAction`): `data.object` is the **full generation object** (usage, rates,
   image, timestamps), mapped by `GenerationPayloadMapper` and passed to `ApplyProviderUpdateAction` (the
   same path polling uses). No extra API call is needed to settle.
    - The generation is found by `provider_generation_id`, or by `metadata.client_reference` when the webhook
      arrives **before the create call returned** (documented). In that case we also record the provider id.
    - `ping` and unknown event types: mark processed and ignore.
    - Unknown generation: the job records the error and retries after 5, 15, 30, 60 and 120 s (6 tries). A **redelivery of an
      event that is stored but unprocessed** (Pixelforge's automatic retry or a dashboard "resend") is processed
      again, so a resend can repair it. Already-processed duplicates stay no-ops.
5. Status comes from `data.object.status`, not from the event type, and moves forward only (ADR-0006).

Dedup happens in **two layers**: event-level (unique `provider_event_id`) and effect-level (state
machine ADR-0006 + unique ledger key ADR-0008). Even if two _different_ events report the same
completion, the charge is recorded once.

## Alternatives considered

- **Process synchronously in the request**: simpler, but slow responses cause provider retries and
  more duplicates. Rejected.
- **Dedup by payload hash**: breaks if the provider re-signs with a new timestamp. Prefer the event ID.
- **Always re-fetch the generation from the API instead of trusting the payload**: most robust against
  ordering, but costs API calls under rate limits. Used only when the payload lacks data.
- **spatie/laravel-webhook-client**: handles verify/store/queue. A good fit, but the custom flow is
  small and easier to explain in an interview. Could switch.

## Consequences

### Positive

- Duplicates are cheap no-ops. Raw payloads let us replay and debug.
- Shares the update path with polling, so there's one code path to test.

### Drawbacks

- Re-dispatching unprocessed duplicates means the same event can occasionally be processed by two jobs at once.
  That's harmless (row locks, forward-only status, one charge), but it costs extra work and log lines.

- `webhook_events` grows forever. Needs a pruning policy (e.g. `MassPrunable` after 30–90 days).
- Returning 200 before processing means a processing bug is invisible in the Pixelforge dashboard.
  We need our own monitoring (failed jobs, unprocessed events older than N minutes).
- Reading the raw body for the signature must happen before anything parses or changes it.
  Middleware ordering matters.
- Signing-secret rotation isn't handled yet (we'd need to accept two secrets during rotation).
