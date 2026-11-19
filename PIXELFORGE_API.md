# Pixelforge API — Reference for Claude Code

> Source: https://pixelforge.promptedit.dev/docs (scraped 2026-11-19).
> Pixelforge is a **simulated** image-generation provider: images are abstract placeholders
> (captioned with the prompt) and no real money is charged, but pricing, limits, timing,
> failures and webhooks behave like a real provider. Build against it as if it were production.

## TL;DR for implementers

- Base URL: `https://pixelforge.promptedit.dev/v1` — JSON in, JSON out.
- Auth: `Authorization: Bearer $PIXELFORGE_API_KEY` (all endpoints except `GET /v1/pricing`).
- Generation is **asynchronous**: `POST /v1/generations` → `202` + `queued` object → learn the result via **webhook** or by **polling** `GET /v1/generations/{id}`.
- Always send an `Idempotency-Key` on `POST /v1/generations` so retries are safe.
- **Branch on `error.code`**, never on message text.
- Money is always an **exact decimal string** (e.g. `"0.046125000"`). Never use floats for cost math.
- The API **never returns a price**. Compute cost = `usage` tokens × `rates` stored on the generation.
- Webhooks are **at-least-once, unordered, not guaranteed**: verify signature on the raw body, dedupe by event `id`, never move a generation backwards out of `succeeded`/`failed`, and keep polling as a fallback.
- `image_url` expires **24h after completion**. Download and store the PNG if you need it longer.

## Conventions

| Thing | Rule |
| --- | --- |
| Body format | JSON for every request and response |
| Timestamps | ISO 8601 in UTC, e.g. `2026-11-04T14:03:11Z` |
| IDs | `gen_` prefix = generation, `evt_` prefix = webhook event |
| Money | Exact decimal string in USD, never a float, e.g. `"0.046125000"` |
| Unknown params | **Rejected** with `422 validation_failed` (not ignored) |

## Authentication

- API keys are created in the dashboard (sign up at `/register`). A key is shown **once** at creation. Revoked keys stop working immediately.
- Each key has **its own** webhook URL, webhook signing secret (`whsec_…`), and usage report.
- Recommended: one key per environment (e.g. local dev vs deployed app), so each environment only gets its own events and can reconcile its own costs.
- Missing / malformed / revoked key → `401 invalid_api_key`.

```bash
curl https://pixelforge.promptedit.dev/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY"
```

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/v1/generations` | Yes | Create a generation (async; returns `202`) |
| `GET` | `/v1/generations/{id}` | Yes | Retrieve one generation |
| `GET` | `/v1/generations` | Yes | List generations (newest first, cursor-paginated) |
| `GET` | `/v1/pricing` | **No** | Current token rates and token-count tables |
| `GET` | `/v1/usage` | Yes | Billed usage per UTC day for the calling key |

### `POST /v1/generations` — Create a generation

Headers: `Authorization`, `Content-Type: application/json`, `Idempotency-Key` (strongly recommended).

| Parameter | Type | Required | Default | Rules |
| --- | --- | --- | --- | --- |
| `prompt` | string | **yes** | — | Max 4,000 characters |
| `size` | string | no | `1024x1024` | One of `1024x1024`, `1536x1024`, `1024x1536` |
| `quality` | string | no | `medium` | One of `low`, `medium`, `high` |
| `reference_images` | array of URL strings | no | — | Max 4 http(s) URLs. Not downloaded, but **each billed as 765 input image tokens** |
| `metadata` | object (string → string) | no | `{}` | Max 20 keys, values ≤ 500 chars. Echoed on the generation and in every webhook — use it to link to your own records |

Any other parameter → `422 validation_failed`.

```bash
curl https://pixelforge.promptedit.dev/v1/generations \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10" \
  -d '{
    "prompt": "A lighthouse on a cliff at dawn, oil painting",
    "size": "1536x1024",
    "quality": "high",
    "reference_images": ["https://example.com/style.png"],
    "metadata": { "order_id": "1042" }
  }'
```

Response: **`202 Accepted`** with a generation object in `queued` status:

```json
{
  "id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
  "object": "generation",
  "status": "queued",
  "prompt": "A lighthouse on a cliff at dawn, oil painting",
  "size": "1536x1024",
  "quality": "high",
  "reference_images": ["https://example.com/style.png"],
  "metadata": { "order_id": "1042" },
  "image_url": null,
  "image_url_expires_at": null,
  "usage": null,
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "error": null,
  "created_at": "2026-11-04T14:03:11Z",
  "started_at": null,
  "completed_at": null
}
```

Possible errors: `401 invalid_api_key`, `422 validation_failed`, `400 content_policy_violation`, `409 idempotency_key_reused`, `429 rate_limit_exceeded | concurrency_limit_exceeded | daily_limit_exceeded`, `503 overloaded`.

### `GET /v1/generations/{id}` — Retrieve a generation

Returns the generation object. A generation belonging to another account returns `404 not_found` (indistinguishable from nonexistent).

### `GET /v1/generations` — List generations

Newest first, cursor pagination.

| Query param | Rules |
| --- | --- |
| `limit` | 1–100, default 20 |
| `status` | Filter: `queued`, `processing`, `succeeded`, `failed` |
| `cursor` | The `next_cursor` from the previous page |

```bash
curl "https://pixelforge.promptedit.dev/v1/generations?limit=2&status=succeeded" \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY"
```

```json
{
  "object": "list",
  "data": [ { "id": "gen_…", "object": "generation", … }, … ],
  "has_more": true,
  "next_cursor": "eyJjcmVhdGVkX2F0Ijoi…"
}
```

Loop while `has_more` is `true`, passing `next_cursor` as `cursor`.

### `GET /v1/pricing` — Pricing (no auth)

Rates can change — read them from this endpoint rather than hardcoding.

```bash
curl https://pixelforge.promptedit.dev/v1/pricing
```

```json
{
  "object": "pricing",
  "currency": "usd",
  "unit": "per_1m_tokens",
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "tokens": {
    "characters_per_text_token": 4,
    "text_token_variance": 0.15,
    "per_reference_image": 765,
    "output": {
      "low":    { "1024x1024": 280,  "1536x1024": 420,  "1024x1536": 420 },
      "medium": { "1024x1024": 1100, "1536x1024": 1650, "1024x1536": 1650 },
      "high":   { "1024x1024": 4300, "1536x1024": 6450, "1024x1536": 6450 }
    },
    "reasoning": {
      "low":    { "min": 0,    "max": 500 },
      "medium": { "min": 200,  "max": 2000 },
      "high":   { "min": 1000, "max": 8000 }
    }
  }
}
```

### `GET /v1/usage` — Billed usage for the calling key

Source of truth for **that key's** provider costs; reconcile your own records against it.

| Query param | Rules |
| --- | --- |
| `start_date` | `YYYY-MM-DD`, UTC, inclusive |
| `end_date` | `YYYY-MM-DD`, UTC, inclusive |

- Default range: last 30 days ending today. Max span **92 days** → otherwise `422 date_range_too_long`.
- A generation is billed on the UTC day it **completed**, not the day it was created.
- `cost_usd` is an exact, unrounded decimal string with **9 decimal places**.
- Days with no usage are included with zeros.

```bash
curl "https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-02" \
  -H "Authorization: Bearer $PIXELFORGE_API_KEY"
```

```json
{
  "object": "usage",
  "start_date": "2026-11-01",
  "end_date": "2026-11-02",
  "data": [
    {
      "date": "2026-11-01",
      "generations": 3,
      "input_text_tokens": 41,
      "input_image_tokens": 0,
      "output_tokens": 3300,
      "reasoning_tokens": 2950,
      "cost_usd": "0.169080000"
    },
    {
      "date": "2026-11-02",
      "generations": 0,
      "input_text_tokens": 0,
      "input_image_tokens": 0,
      "output_tokens": 0,
      "reasoning_tokens": 0,
      "cost_usd": "0.000000000"
    }
  ],
  "total": {
    "generations": 3,
    "input_text_tokens": 41,
    "input_image_tokens": 0,
    "output_tokens": 3300,
    "reasoning_tokens": 2950,
    "cost_usd": "0.169080000"
  }
}
```

## The generation object

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string | `gen_` prefix |
| `object` | string | Always `"generation"` |
| `status` | enum | `queued` \| `processing` \| `succeeded` \| `failed` |
| `prompt`, `size`, `quality`, `reference_images`, `metadata` | — | As requested, defaults filled in. `metadata` is always an object (`{}` if none sent) |
| `image_url` | string \| null | Signed PNG URL once `succeeded`, else `null` |
| `image_url_expires_at` | timestamp \| null | When `image_url` stops working (completion + 24h) |
| `usage` | object \| null | `null` until `succeeded`; then `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`, `total_tokens` (integers) |
| `rates` | object | USD per 1M tokens (decimal strings), same keys as `/v1/pricing` `rates`. **Fixed at creation** — later price changes don't affect it |
| `error` | object \| null | `null` unless `failed`; then `{ code, message }` |
| `created_at` | timestamp | Accepted |
| `started_at` | timestamp \| null | Started processing |
| `completed_at` | timestamp \| null | Finished (succeeded or failed) |

### Lifecycle

```
queued ──► processing ──► succeeded   (final)
                      └─► failed      (final)
```

Typically a few seconds in `queued`, then 5–30 s in `processing` (60–90 s with the `[slow]` test keyword). `succeeded` and `failed` are terminal — never transition out of them.

Succeeded example:

```json
{
  "id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
  "object": "generation",
  "status": "succeeded",
  "prompt": "A lighthouse on a cliff at dawn, oil painting",
  "size": "1536x1024",
  "quality": "high",
  "reference_images": ["https://example.com/style.png"],
  "metadata": { "order_id": "1042" },
  "image_url": "https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=…",
  "image_url_expires_at": "2026-11-05T14:03:29Z",
  "usage": {
    "input_text_tokens": 12,
    "input_image_tokens": 765,
    "output_tokens": 6450,
    "reasoning_tokens": 3184,
    "total_tokens": 10411
  },
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "error": null,
  "created_at": "2026-11-04T14:03:11Z",
  "started_at": "2026-11-04T14:03:13Z",
  "completed_at": "2026-11-04T14:03:29Z"
}
```

Failed example (`error` field only; failed generations are **never billed**):

```json
"error": {
  "code": "internal_error",
  "message": "Something went wrong on our side while generating. You were not charged; please retry."
}
```

| Failure `error.code` | Meaning | Retry? |
| --- | --- | --- |
| `generation_failed` | The model couldn't produce an image for this prompt | Not likely to help with the same prompt |
| `internal_error` | Server-side problem | Yes, retrying usually works (new create) |

## Errors

Every error has the same shape. Validation errors add `param` (first invalid parameter) and `errors` (every problem, keyed by parameter). **Branch on `code`**; `type` just groups related codes.

```json
{
  "error": {
    "type": "invalid_request_error",
    "code": "validation_failed",
    "message": "The selected size is invalid.",
    "param": "size",
    "errors": {
      "size": ["The size must be one of: 1024x1024, 1536x1024, 1024x1536."]
    }
  }
}
```

| `code` | `type` | HTTP | Meaning / what to do |
| --- | --- | --- | --- |
| `invalid_api_key` | `authentication_error` | 401 | Key missing, malformed or revoked. Don't retry |
| `validation_failed` | `invalid_request_error` | 422 | Missing/invalid param, or an unknown param. See `param` + `errors`. Don't retry unchanged |
| `content_policy_violation` | `invalid_request_error` | 400 | Prompt rejected. Nothing created or billed. Don't retry unchanged |
| `not_found` | `invalid_request_error` | 404 | Doesn't exist **or** belongs to another account (can't tell which) |
| `idempotency_key_reused` | `idempotency_error` | 409 | Same `Idempotency-Key` used with different parameters. Bug in caller |
| `rate_limit_exceeded` | `rate_limit_error` | 429 | Over 60 req/min. Wait `Retry-After` seconds |
| `concurrency_limit_exceeded` | `rate_limit_error` | 429 | 3 generations already queued/processing. `Retry-After: 5`; retry once one finishes |
| `daily_limit_exceeded` | `rate_limit_error` | 429 | 500/day UTC quota used. Don't retry until the next UTC day |
| `overloaded` | `api_error` | 503 | Temporarily overloaded. `Retry-After: 2`. Nothing created — retry, ideally with the same `Idempotency-Key` |
| `date_range_too_long` | `invalid_request_error` | 422 | `/v1/usage` range exceeds 92 days |

## Rate limits and quotas

Limits are **per account, across all its keys**.

| Limit | Value | When exceeded |
| --- | --- | --- |
| Requests | 60 / minute | `429 rate_limit_exceeded` + `Retry-After` |
| Concurrent generations | 3 queued or processing at once | `429 concurrency_limit_exceeded` + `Retry-After` |
| Generations per day | 500 / UTC day | `429 daily_limit_exceeded` |

- Rejected requests create nothing and cost nothing — but **every retry counts toward the per-minute limit**.
- Honor `Retry-After` (seconds), back off further each time, and **cap retries** (give up after a few attempts).
- Every authenticated response includes `X-RateLimit-Limit` (allowed per minute) and `X-RateLimit-Remaining` (left this minute).

## Idempotency

Send `Idempotency-Key: <unique string, ≤255 chars>` (UUID or your own order id) on `POST /v1/generations`.

| Case | Result |
| --- | --- |
| Same key + same params | `202` with the **existing** generation as it is *now* (status may have advanced), header `Idempotent-Replayed: true`. No new generation, no extra billing, doesn't count toward concurrency or daily limits |
| Same key + different params | `409 idempotency_key_reused` |

Keys are scoped to the account and **never expire** — so never reuse a key for a genuinely new request.

## Pricing and cost calculation

Only `succeeded` generations are billed. Each generation is billed at the `rates` captured **when it was created** (stored on the generation object).

| Token type | Price per 1M tokens |
| --- | --- |
| Input text | $5.00 |
| Input image | $10.00 |
| Output | $40.00 |
| Reasoning | $12.50 |

### How tokens are counted

| Token type | Rule | Known when |
| --- | --- | --- |
| Input text | ≈ `ceil(unicode_chars / 4)`; actual is within ±15% of that estimate | After completion |
| Input image | Exactly 765 per reference image | At creation |
| Output | Exact, from the table below | At creation |
| Reasoning | Variable, within a range by quality (table below) | After completion |

Output tokens (exact):

| Quality | 1024x1024 | 1536x1024 | 1024x1536 |
| --- | --- | --- | --- |
| low | 280 | 420 | 420 |
| medium | 1,100 | 1,650 | 1,650 |
| high | 4,300 | 6,450 | 6,450 |

Reasoning tokens (range):

| Quality | Min | Max |
| --- | --- | --- |
| low | 0 | 500 |
| medium | 200 | 2,000 |
| high | 1,000 | 8,000 |

### Formula

```
cost_usd = Σ over token types ( usage.<type> × rates.<type> / 1_000_000 )
```

Costs are exact and **never rounded** (1 token at $1.00/1M = $0.000001). Floating-point drifts — use integers or arbitrary-precision decimals.

Recommended integer approach (nanodollars):

```
rate_nanodollars_per_token = decimal(rate_string) × 1000      # "12.50" → 12500
cost_nanodollars           = Σ tokens × rate_nanodollars_per_token
cost_usd_string            = format(cost_nanodollars / 10^9, 9 decimal places)
```

In PHP use `bcmath` (`bcmul`, `bcadd`, `bcdiv` with scale 9) or `brick/math`; never `float`.

### Worked example — maximum possible cost

High quality, 1536x1024, 400-character prompt, 2 reference images, max text and reasoning tokens:

| Tokens | Count | Rate / 1M | Cost (USD) |
| --- | --- | --- | --- |
| Input text: 400/4 = 100 est., +15% | 115 | $5.00 | 0.000575000 |
| Input image: 2 × 765 | 1,530 | $10.00 | 0.015300000 |
| Output: high, 1536x1024 | 6,450 | $40.00 | 0.258000000 |
| Reasoning: high max | 8,000 | $12.50 | 0.100000000 |
| **Maximum total** | | | **0.373875000** |

Pre-creation cost estimate: use `ceil(chars/4) × 1.15` (max) for text, exact image and output counts, and the reasoning min/max for the quality to give a min–max range.

## Webhooks

Each API key has its own webhook URL and signing secret (set/copied in the dashboard). When a generation changes status, Pixelforge POSTs to the URL of the **key that created it**, signed with **that key's** secret. Other keys never see the event.

| Event `type` | Sent when |
| --- | --- |
| `generation.processing` | Generation left the queue |
| `generation.succeeded` | Finished; includes `usage` and `image_url` |
| `generation.failed` | Failed; includes `error` |
| `ping` | "Send test event" pressed in the dashboard |

Payload — `data.object` is the full generation object at the moment of the event:

```json
{
  "id": "evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A",
  "object": "event",
  "type": "generation.succeeded",
  "created_at": "2026-11-04T14:03:29Z",
  "data": {
    "object": {
      "id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K",
      "object": "generation",
      "status": "succeeded",
      …the full generation object…
    }
  }
}
```

Request headers:

```
POST /webhooks/pixelforge HTTP/1.1
Content-Type: application/json
User-Agent: Pixelforge-Webhooks/1.0
Pixelforge-Event-Id: evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A
Pixelforge-Event-Type: generation.succeeded
Pixelforge-Delivery-Attempt: 1
Pixelforge-Signature: t=1762265009,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd
```

`Pixelforge-Delivery-Attempt` counts every attempt, including automatic retries and manual dashboard resends.

### Signature verification

- Header format: `Pixelforge-Signature: t=<unix timestamp>,v1=<signature>`
- `signature = hex(HMAC_SHA256(key = whsec_secret, message = "<timestamp>.<raw request body>"))`
- Use the secret of the **API key that created the generation**.
- Verify against the **raw body before JSON decoding**.
- Compare in **constant time**.
- Reject stale timestamps (docs use a 300-second tolerance) to stop replays. Each attempt is signed with a fresh timestamp.

Laravel example from the docs:

```php
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController
{
    public function __invoke(Request $request): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();
        $signature = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $signature[$key] = $value;
        }

        $timestamp = (int) ($signature['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", config('services.pixelforge.webhook_secret'));

        if (! hash_equals($expected, $signature['v1'] ?? '') || abs(time() - $timestamp) > 300) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);

        // Deduplicate by $event['id'] before acting on the event.

        return response()->noContent();
    }
}
```

Register the route **outside the `web` middleware group** (or exclude it from CSRF protection) — Pixelforge sends no CSRF token.

### Responding and retries

- Any `2xx` within **10 seconds** = delivered.
- Anything else (non-2xx, timeout, connection error) is retried after **10s, 30s, 2m, 10m, 15m** — up to **6 attempts total**.
- Redirects are **not** followed.
- Respond fast; do slow work (downloading the image, etc.) in a **queued job**.

### Delivery guarantees (design for these)

1. **At least once** — the same event can arrive more than once. Dedupe by event `id` (e.g. unique DB column).
2. **Out of order** — a retried `generation.processing` can arrive after `generation.succeeded`. Go by the generation's `status`; never move a generation back from `succeeded` or `failed`.
3. **Can arrive before** your `POST /v1/generations` call has returned — the local record may not exist yet; handle that (e.g. look up by `metadata`, upsert by `gen_` id, or create-then-call with a pre-generated idempotency key).
4. **Not guaranteed** — if every attempt fails, poll `GET /v1/generations/{id}` as a fallback (e.g. a scheduled reconciler for generations stuck in non-terminal states).

### Local development

Webhook URLs must be public `http`/`https`. Private/loopback addresses (`localhost`, `127.0.0.1`, `10.x`, …) are rejected. Use a tunnel: Expose, ngrok, or Cloudflare Tunnel. The dashboard lists every delivery attempt with your endpoint's response and lets you resend any event.

## Images

- `image_url` is a signed PNG at the requested size.
- Expires **24 hours after the generation completed** (`image_url_expires_at`).
- `GET /v1/generations/{id}` returns a fresh URL, but it **expires at the same time** — it does not extend the window.
- Download and store the file yourself if you need it longer.

## Testing keywords

Put these anywhere in `prompt` (case-insensitive; they still count as text tokens):

| Keyword | Behaviour |
| --- | --- |
| `[blocked]` | Rejected at submission: `400 content_policy_violation`. Not created or billed |
| `[overloaded]` | Rejected at submission: `503 overloaded`. Not created or billed |
| `[fail]` | Accepted, then fails while processing (`generation.failed`). Not billed |
| `[succeed]` | Accepted and never fails at random |
| `[slow]` | Takes 60–90 s to process instead of 5–30 s |
| `[duplicate]` | Every webhook event for the generation is delivered twice |

Without keywords, the simulator still misbehaves like a real provider: a small share of generations fail at random, some creates are rejected with `503 overloaded`, and some webhook events are deliberately delivered twice. **Your integration must tolerate all of these.**

## Implementation checklist

- [ ] API key in env (`PIXELFORGE_API_KEY`), webhook secret in env (e.g. `services.pixelforge.webhook_secret`); one key per environment.
- [ ] Create requests send a stable `Idempotency-Key`, persisted before the call so a retry reuses it.
- [ ] Only send known params (`prompt`, `size`, `quality`, `reference_images`, `metadata`); validate locally first.
- [ ] Use `metadata` to link generations back to local records.
- [ ] Error handling switches on `error.code`; 429/503 retries honor `Retry-After` with capped exponential backoff; `daily_limit_exceeded` is not retried.
- [ ] Respect 3 concurrent generations / 60 req/min / 500 per day (per account) — queue work locally.
- [ ] Webhook endpoint: raw-body HMAC check, constant-time compare, 300 s timestamp tolerance, CSRF-exempt, returns `2xx` fast, heavy work queued.
- [ ] Event dedupe by `evt_` id; status transitions are monotonic (terminal states never regress).
- [ ] Handle webhook arriving before the create response returns.
- [ ] Polling fallback for generations stuck in `queued`/`processing`.
- [ ] Download and store images before `image_url_expires_at`.
- [ ] Cost math uses stored `rates` × `usage`, in integers/decimals (no floats), 9-decimal strings; reconcile against `GET /v1/usage` (billed on completion day, UTC).
- [ ] Tests cover `[blocked]`, `[overloaded]`, `[fail]`, `[slow]`, `[duplicate]`, and `[succeed]`.
