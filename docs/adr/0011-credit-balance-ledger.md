# ADR-0011: Credit balances and ledger (stretch)

- Status: Accepted (implemented in phase 9)
- Date: 2026-11-19

## Context

Stretch goal: track each user's balance, offer a fake "Buy credits" button, and block generating when
the user can't afford it. The final price isn't known until completion, and several generations can be in flight at once.

## Decision

1. **Append-only ledger** `credit_transactions` (`purchase`, `charge`, `adjustment`), signed `amount`,
   unique `idempotency_key`, `balance_after`. The ledger is the source of truth.
2. **Cached balance** `users.balance` updated in the same transaction as each ledger insert, under
   `lockForUpdate()` on the user row. A test asserts `balance == SUM(ledger)`.
3. **Reserve the maximum**: Pixelforge publishes hard bounds, so the estimate has a true maximum
   (ADR-0004). Available = `balance − SUM(estimated_user_cost_max of the user's non-terminal generations)`.
   `CreateGenerationAction` locks the user row and rejects with a clear message if `max > available`.
4. Settlement charges the **actual** cost, which is ≤ the reserved max, so the **balance never goes
   negative** while Pixelforge's published rules hold. If it ever exceeds the max anyway, we still record the
   real cost (correct money beats a tidy balance) and log a warning.
5. **Buy credits**: fixed packs ($5/$10/$25) with a client-generated idempotency key per form render,
   so double-clicks don't double-credit. Rate limited.

**Implementation notes (phase 9):**

- `BalanceService` computes reserved = `SUM(estimated_user_cost_max)` of in-flight generations, summed by Postgres
  and read back as exact text (never a PHP float). `CreateGenerationAction` locks the user row
  (`lockForUpdate`), checks and creates the generation in one transaction. The estimate is computed before it,
  because it may call `/v1/pricing`.
- The Generate page gets `available` and `affordable` from the server (`QuoteGenerationAction`), so the browser
  never compares money. A refused submission returns a `credits` error with a plain message.
- Purchases: `POST /credits/purchases` with a pack id (never an amount) and a **server-issued** UUID per page render.
  The ledger key `purchase:{uuid}` makes repeats return the original purchase. Another user's key is refused.
- Every signed-in page receives `credits` (balance, reserved, available).

**Hardening (2026-11-20):**

- **Manual adjustments** (refunds, goodwill credit) go through the ledger too: `php artisan credits:adjust {email}
  {amount} --reason=… [--generation=…]` (`AdjustCreditsAction` → `CreditLedgerService::adjust()`), type
  `adjustment`, a unique key per adjustment, and the reason in `meta`. It asks for confirmation unless `--force`.
- **Fair use per user**: at most 3 generations in progress and 100 started per UTC day (`pixelforge.per_user`),
  checked by `GenerationQuotaService` under the same user-row lock as the credit check. Pixelforge's 3-concurrent
  and 500/day limits are shared by the whole account, so one user could otherwise block everyone. Refused
  submissions don't count. The Generate page shows the reason and disables the button.
- **Account deletion keeps the money trail**: a user with generations or ledger rows is anonymised instead of deleted
  (name, email, password, 2FA, sessions and passkeys cleared, `anonymized_at` set). Users with no history are deleted.

## Alternatives considered

- **Hold/release ledger entries**: explicit, but doubles ledger rows and adds release bookkeeping on every path.
- **Reserve an upper bound**: never goes negative, but blocks users who can actually afford it, and needs a known max (Q4).
- **Balance column only, no ledger**: simple, but no audit trail and no way to explain or verify the balance.

## Consequences

### Positive

- Concurrent submissions can't overspend the available balance (row lock).
- Every balance change is explainable and auditable.

### Drawbacks

- Generations charged before phase 9 left existing local users with negative balances. They must buy credits
  before generating again (there is no welcome credit).

- Reserving the max is conservative: a user with $0.30 can't start a generation whose max is $0.47,
  even though it would typically cost less. (The earlier "reserve the estimate, allow a small overdraft"
  design was replaced once the docs gave real bounds.)
- Available balance is a derived query on every submit (cheap with an index on `(user_id, status)`).
- The cached balance can drift from the ledger if someone writes outside `CreditLedgerService`.
  Mitigated by the invariant test and by a single write path.
- Adjustments are a CLI command only: no admin UI, and every use needs shell access to the server.
- Per-user caps are global config, not per plan. A heavy legitimate user hits 100/day.
- Anonymised accounts stay in `users` forever (needed for the spend report and Pixelforge reconciliation).
- A fake purchase has no payment verification. A real integration (e.g. Stripe) would credit only on a verified webhook.
