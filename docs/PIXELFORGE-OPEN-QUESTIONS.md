# Pixelforge API: questions resolved

All questions below were open while planning. They are now **answered by the official docs**,
copied verbatim to [`../PIXELFORGE_API.md`](../PIXELFORGE_API.md) (the source of truth; scraped 2026-11-19).
The live `GET /v1/pricing` response was checked against the docs on 2026-11-19 and matches.

| #   | Question                              | Answer (docs)                                                                                                                                    | Impact                                                        |
| --- | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------- |
| Q1  | Auth & base URL                       | `Authorization: Bearer …`; base `https://pixelforge.promptedit.dev/v1`; `/v1/pricing` needs no auth                                              | Client paths are relative to `/v1`                            |
| Q2  | Token types; which are known up front | `input_text` (after), `input_image` (exact, 765/image), `output` (exact table), **`reasoning`** (after, range by quality)                        | `TokenType` has 4 cases                                       |
| Q3  | Rates & precision                     | USD **per 1M tokens** as decimal strings (`"12.50"`), live from `/v1/pricing`, **fixed on each generation** (`rates`). Costs never rounded       | `RateCardData` prices per 1M; snapshot = generation's `rates` |
| Q4  | Bounds on unknown tokens              | Text: `ceil(chars/4)` ± 15%. Reasoning: min–max per quality. Docs recommend a min–max estimate                                                   | Estimate is a **range**; balances reserve the max             |
| Q5  | Idempotency key                       | Yes, `Idempotency-Key` (≤255 chars, never expires). Same key + same params → `202` with the existing generation (`Idempotent-Replayed: true`)    | Retries after timeouts are safe                               |
| Q6  | Metadata echoed?                      | Yes. `metadata` (string→string) echoed on the object and in every webhook                                                                        | We send `metadata.client_reference`                           |
| Q7  | Error shapes / codes / Retry-After    | `{"error": {type, code, message, param?, errors?}}`. **Branch on `code`**. `Retry-After` in seconds                                              | `ProviderErrorMapper` maps by code                            |
| Q8  | Status names; terminal final?         | `queued`, `processing`, `succeeded`, `failed`. Terminal states never change. No sequence number                                                  | ADR-0006 rank rule confirmed                                  |
| Q9  | Webhook signature                     | `Pixelforge-Signature: t=<unix>,v1=<hex>`; `hex(HMAC_SHA256(whsec, "<t>.<raw body>"))`; constant-time compare; 300 s tolerance                   | `WebhookSignatureVerifier` (phase 5)                          |
| Q10 | Event id                              | `evt_…` in body `id` and header `Pixelforge-Event-Id`; stable across retries and resends                                                         | `webhook_events.provider_event_id` unique                     |
| Q11 | Completion webhook contents           | `data.object` is the **full generation object** (usage, rates, image_url, completed_at)                                                          | Settle from the payload; no extra GET needed                  |
| Q12 | `/v1/usage` day boundary              | **UTC**, billed on the day the generation **completed**                                                                                          | Report groups by provider `completed_at` (UTC)                |
| Q13 | `/v1/usage` content                   | Per day: generations, token counts per type, `cost_usd` (exact, 9 dp). Max range 92 days                                                         | Report compares `cost_usd` per day                            |
| Q14 | Sizes, qualities, refs, prompt length | Sizes `1024x1024`, `1536x1024`, `1024x1536`; qualities `low/medium/high` (defaults 1024x1024/medium); ≤4 `reference_images`; prompt ≤4,000 chars | `config/pixelforge.php → options`                             |
| Q15 | Test keywords                         | `[blocked]`, `[overloaded]`, `[fail]`, `[succeed]`, `[slow]`, `[duplicate]`                                                                      | QA plan in `PLAN.md`                                          |
| Q16 | Image URL expiry                      | 24 h after completion (`image_url_expires_at`); re-fetching does not extend it                                                                   | UI shows "image expired"; no mirroring (ADR-0013)             |

## Facts that shaped other decisions

- **Limits are per account, across all keys**: 60 requests/min (retries count), **3 concurrent** generations,
  500/day (UTC). Local and deployed environments share them.
- **Unknown request params are rejected** (`422 validation_failed`), so the client sends only documented fields.
- **Webhooks** are at-least-once, unordered, not guaranteed, and can arrive **before** the create call returns.
  A `2xx` within 10 s counts as delivered. Otherwise Pixelforge retries after 10 s, 30 s, 2 m, 10 m and 15 m (6 attempts).
  Event types: `generation.processing`, `generation.succeeded`, `generation.failed`, `ping`.
- **Failed generations are never billed**. Rejected creates (`400`, `429`, `503`) create and bill nothing.

## Where the API shape lives in code

| Concern                                      | File                                                  |
| -------------------------------------------- | ----------------------------------------------------- |
| Endpoints, headers, request body             | `app/Services/Pixelforge/PixelforgeHttpClient.php`    |
| Generation object → `ProviderGenerationData` | `app/Services/Pixelforge/GenerationPayloadMapper.php` |
| `/v1/pricing` → `PricingCatalogData`         | `app/Services/Pixelforge/PricingPayloadMapper.php`    |
| Error codes → typed exceptions               | `app/Services/Pixelforge/ProviderErrorMapper.php`     |
| Docs payloads used as test fixtures          | `tests/Support/PixelforgeFixtures.php`                |
