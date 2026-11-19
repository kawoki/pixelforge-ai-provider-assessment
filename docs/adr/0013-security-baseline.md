# ADR-0013: Security baseline

- Status: Accepted
- Date: 2026-11-19

## Context

The app handles user accounts, (fake) money, a third-party API key, a public webhook endpoint, and
user-supplied URLs.

## Decision

**Authentication & authorization**

- Starter-kit auth (hashed passwords, email verification, throttled login, session regeneration).
- A Policy for every model: `GenerationPolicy@view` only lets owners see their generations. History queries are always scoped `->whereBelongsTo($user)`.
- Admin access via Gate + `is_admin`. `is_admin`, `balance` are **never** mass-assignable (`$fillable` allowlists).
- IDs exposed in URLs are ULIDs, so they can't be enumerated.

**Input**

- FormRequests for every write. Prompt: required, max length. Size/quality: `Rule::in()` from config.
  Reference URLs: max 4, each `url:http,https` (Pixelforge accepts both), max 2,048 chars, deduplicated.
- We never fetch reference URLs server-side (no SSRF surface). They are passed through to Pixelforge only.
- Money amounts for purchases come from a server-side pack list (`pack_id`), never a free amount.

**Secrets**

- `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET` only in env. A separate key per environment.
  Never logged. Never sent to the frontend (`config()` isn't shared to Inertia).
- HTTP client logs strip `Authorization` headers.

**Webhook**

- HMAC signature verification with `hash_equals`, timestamp tolerance (if provided), dedicated
  rate limiter, excluded from CSRF only for that path.

**Output**

- Vue template escaping only. **No `v-html`** with user or provider data.
- Provider image URLs (`https://pixelforge.promptedit.dev/images/…`, signed, 24 h) rendered only in `<img src>` with `referrerpolicy="no-referrer"`. Validate the
  scheme is `https`.
- Security headers middleware `AddSecurityHeaders` (implemented 2026-11-20): `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy` that denies
  camera/microphone/geolocation/payment, HSTS over HTTPS, and a CSP built by `ContentSecurityPolicy`:
  `default-src 'self'`; scripts only from `'self'` plus a **per-request nonce** (`Vite::useCspNonce()`, also on
  the inline appearance script); `img-src 'self' data: blob:` plus the Pixelforge image host; `connect-src 'self'`
  plus the Reverb `ws(s)://` origin; `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`,
  `frame-ancestors 'none'`. Vite dev-server origins are added only locally while Vite is hot.
  `CSP_REPORT_ONLY=true` switches to `Content-Security-Policy-Report-Only` for safe rollout; `CSP_ENABLED=false`
  turns it off. Image hosts live in `config/security.php`; extra WebSocket/API origins go in `CSP_CONNECT_EXTRA`.
- `GET /health/background` requires `Authorization: Bearer {HEALTH_CHECK_TOKEN}` and answers 404 without it (or
  when no token is set), so it doesn't reveal queue state to the public.
- Fair-use caps per user (ADR-0011) stop one account from using up Pixelforge's shared account limits.

**Abuse**

- Rate limiters: generate (per user), estimate (per user), buy credits (per user), webhook (per IP).
- Production: `APP_DEBUG=false`, HTTPS-only, secure cookies.

## Alternatives considered

- **Integer IDs + policies only**: policies alone are enough, but ULIDs add defence in depth for little cost.
- **Proxy/mirror images through our server**: hides provider URLs and survives expiry, but adds
  storage, bandwidth and an SSRF-style fetch surface. Out of scope.

## Consequences

### Positive

- Defaults are secure. Most of the protection comes from framework features that are well understood.

### Drawbacks

- CSP needs maintenance (the Pixelforge image host, Vite dev server in local). Too strict a CSP breaks images.
  A new third-party script, font or WebSocket host must be added to the policy or it is silently blocked.
- `style-src` allows `'unsafe-inline'`, because Vue `:style` bindings and the UI kit write inline styles. Script
  injection is still blocked; style injection is not.
- No CSP violation reporting endpoint: report-only mode shows violations only in the browser console.
- The health endpoint is unusable until `HEALTH_CHECK_TOKEN` is set, and the uptime monitor must support a bearer header.
- ULIDs make URLs longer and slightly increase index size.
- Not mirroring images means they disappear when Pixelforge URLs expire (allowed by the brief, but it's a UX gap).
- Email verification adds friction for reviewers trying the deployed app (could be disabled for the demo, which is a tradeoff).
