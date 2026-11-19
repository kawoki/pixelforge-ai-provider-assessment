# ADR-0004: Pricing: estimate before, actual after

- Status: Accepted (revised 2026-11-19 after reading PIXELFORGE_API.md)
- Date: 2026-11-19

## Context

- Users pay Pixelforge cost + **25% markup**.
- Pixelforge never returns a price. We compute it from **token usage × published rates**.
- Some token counts are known only **after** completion, so the up-front price is an estimate.
- Failed generations cost **nothing**.
- Rates can change. Historical records must not be recomputed with new rates.

## Decision

1. **Where rates come from.**
    - _Estimates_ use Pixelforge's live `GET /v1/pricing` (rates + token-counting rules), read by
      `PricingCatalogRepository`, cached 1 h by `CachedPixelforgeClient`, falling back to a verbatim copy in
      `config/pixelforge.php` if Pixelforge is unreachable (and not asking again for 60 s). When the hourly refresh
      finds live rates or token rules that differ from the config copy, admins get a `PricingChanged` alert so the
      fallback can be updated.
    - _Actual cost_ uses the `rates` **on the generation object**. Pixelforge fixes them at creation and bills
      with them, so they are what we snapshot (`generations.rate_card`), together with our markup at that
      moment (`pixelforge.markup = "0.25"`).
2. **`PricingService` is the only place that calculates prices.** Cost per type = `tokens × rate / 1,000,000`
   (`RateCardData::costOf`), summed, then × (1 + markup). Results are exact.
    - `estimate(GenerationRequestData, PricingCatalogData): CostEstimateData` returns a **min–max range**.
    - `actual(TokenUsageData, RateCardData): CostBreakdownData` uses reported usage and the snapshot.
    - `CostBreakdownData` holds per-token-type lines, so the UI can show _why_ something costs what it costs.
3. **The estimate is a range from Pixelforge's published rules** (`TokenEstimator`), as the docs recommend:
   text `ceil(chars/4)` ±15% (floor/ceil), input image exactly 765 per reference image, output exact per
   quality × size, reasoning min–max per quality. The actual cost always lands in the range while those rules hold.
4. **Stored on the generation**: `estimated_user_cost_min` / `_max` (what the user saw) and `rate_card`
   (snapshot). After completion: `usage`, `actual_provider_cost`, `actual_user_cost`.
5. **Estimate via server endpoint**: `POST /generations/estimate` (debounced in the UI). The frontend never
   recalculates prices. On submit, the server **recomputes** the estimate and ignores client-sent values.
6. UI copy: "Estimated $0.0581–$0.0863. The final price depends on how much reasoning the image needs.
   Failed generations are free." After completion: "Charged $0.0707 (estimated $0.0581–$0.0863)".

## Alternatives considered

- **Compute the estimate in Vue from a rate-card prop**: instant feedback, but the pricing logic
  is duplicated in two languages and can drift. Rejected.
- **Charge the estimate and ignore the actual cost**: simpler, but it breaks the "record what it
  actually cost" requirement.
- **Show a range (min–max)**: more honest if the docs give bounds. We can add it later, since
  `CostBreakdownData` could carry `min`/`max`.
- **Rates in DB with an admin UI**: more flexible, but overkill for now. The snapshot design allows it later.

## Consequences

### Positive

- One source of truth for price math. Historical costs stay stable when rates change.
- The breakdown makes cost transparent to the user (product-sense criterion).

### Drawbacks

- _Revision note:_ the original decision (rates in config, a single "expected" estimate) was written before
  the docs were available. The docs showed rates are published live and fixed per generation, and that token
  bounds are known, so this revision replaces it.
- Network round-trip per estimate (our endpoint), plus a cached call to `/v1/pricing` once an hour. Needs
  debouncing and a loading state.
- The range is wide for high quality (reasoning 1,000–8,000 tokens ≈ $0.0125–$0.10 before markup). It's
  honest, but users may find a range less satisfying than one number.
- If `/v1/pricing` is down, estimates use the config copy, which may be stale. Actual charges are unaffected
  (they use the generation's own rates), but a stale estimate could under-reserve a balance (ADR-0011).
- The range is only as good as Pixelforge's published rules (e.g. "text within ±15%"). If Pixelforge breaks
  them, the actual cost can fall outside the range. We still charge the real cost.
- A price change on Pixelforge reaches our estimates up to 1 h late. Charges are unaffected (they use the rates on
  each generation), so the only effect is a slightly stale estimate range.
