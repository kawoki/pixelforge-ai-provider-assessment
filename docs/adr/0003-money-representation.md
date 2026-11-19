# ADR-0003: Money representation

- Status: Accepted
- Date: 2026-11-19

## Context

"Credits are simply US dollars." Pixelforge charges per token, and costs come in **fractions of a cent**.
The admin report must match Pixelforge's `GET /v1/usage` exactly. Floats are not acceptable:
`0.1 + 0.2 != 0.3`. Rounding each generation to cents and then summing will drift away from the
provider's totals.

## Decision

1. **Never use float** for money anywhere (PHP, SQL, JS).
2. Store money as `NUMERIC(24, 12)` in Postgres. Pixelforge's costs have at most 9 decimals (`cost_usd`);
   with the 25% markup ours need at most 11. Twelve decimal places hold any per-token rate × token
   count exactly in practice.
3. In PHP, money is a `Usd` value object (`app/Support/Money/Usd.php`) wrapping `Brick\Math\BigDecimal`,
   with an Eloquent cast `UsdCast`. All arithmetic goes through `Usd` (`plus`, `minus`,
   `multipliedBy`, `isGreaterThan`, …).
4. **Store exact values, round only for display.** Provider cost is stored exactly as computed from
   usage × rates. User charge = provider cost × markup (1.25). Multiplying by 1.25 adds at most two
   decimal places, so it stays exact.
5. `Usd` never rounds: a result that needs more than 12 decimals throws
   `MoneyPrecisionExceededException`, in every environment. `RateCardData` makes this unreachable
   for valid config by rejecting rate cards where `decimals(rate per 1M) + 6 + decimals(1 + markup) > 12`, so
   a bad rate card fails at load time, never during settlement. _(Implemented in phase 2. This replaces
   the earlier "round HALF_UP in production" idea, which would have hidden precision loss.)_
6. Money crosses to the frontend as **strings** (`"0.042500000000"`) plus a server-formatted display
   string (`"$0.0425"`). The frontend never does money arithmetic.
7. Display: per-generation amounts with 4 decimals (so sub-cent prices are visible), balances and
   report totals with 2–4 decimals. Formatting lives in one place (`Usd::format()`).

## Alternatives considered

- **Integer cents**: standard for payments, but loses sub-cent provider costs, so report totals drift from `/v1/usage`.
- **Integer micro-dollars (1e-6)**: still not exact if rates are e.g. $2.50 per 1M tokens (2.5 µ$ per token).
- **Integer nano-dollars**: exact enough, but 64-bit overflow margins, unit confusion, and harder to read in the DB.
- **`brick/money`**: currency-aware, but its fixed-scale contexts fight the "exact, round on display" approach.
- **PHP 8.4 `BcMath\Number`**: native, no dependency. Rejected for now so we don't depend on the PHP version.
  Because everything goes through `Usd`, switching later is a one-file change.

## Consequences

### Positive

- Sums match the provider's records exactly. Profit = charge − cost is exact.
- One value object, so rounding rules live in one place and can be tested.

### Drawbacks

- Users are charged fractional cents, which a real payment system couldn't settle. That's acceptable
  here because credits are internal. A real product would round up per transaction or per invoice.
- `NUMERIC` is slower than integers and Eloquent returns strings, so the cast is required and forgetting it is a bug risk.
- An extra dependency (`brick/math`) plus value-object boilerplate.
- Showing 4 decimals can confuse users who expect cents.
- Rates with more than 4 decimals per 1M tokens would be refused (today's rates have 2).
