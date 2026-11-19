# Architecture Decision Records

Each ADR records one decision: the context, the choice, the alternatives, and its **drawbacks**.
Every ADR must have a Drawbacks section. If a decision has no downside, nobody actually weighed it.

Status values: `Proposed` → `Accepted` → (`Superseded by ADR-XXXX` | `Deprecated`).
Pixelforge API facts come from [`../../PIXELFORGE_API.md`](../../PIXELFORGE_API.md). ADRs 0004, 0005, 0007,
0011 and 0012 were revised on 2026-11-19 once the docs were available; see
[`../PIXELFORGE-OPEN-QUESTIONS.md`](../PIXELFORGE-OPEN-QUESTIONS.md) for what changed.

| #    | Title                                                                             | Status             |
| ---- | --------------------------------------------------------------------------------- | ------------------ |
| 0001 | [Tech stack and hosting](0001-tech-stack-and-hosting.md)                          | Accepted           |
| 0002 | [Backend layering: Actions & Services](0002-backend-layering-actions-services.md) | Accepted           |
| 0003 | [Money representation](0003-money-representation.md)                              | Accepted           |
| 0004 | [Pricing: estimate vs actual cost](0004-pricing-estimate-vs-actual.md)            | Accepted (revised) |
| 0005 | [Async submission to Pixelforge](0005-async-submission-and-retries.md)            | Accepted (revised) |
| 0006 | [Generation lifecycle state machine](0006-generation-state-machine.md)            | Accepted           |
| 0007 | [Webhook ingestion](0007-webhook-ingestion.md)                                    | Accepted (revised) |
| 0008 | [Exactly-once cost recording](0008-exactly-once-cost-recording.md)                | Accepted           |
| 0009 | [Live status in the UI](0009-live-status-ui.md)                                   | Accepted (revised) |
| 0010 | [Reconciliation of stale generations](0010-reconciliation-polling.md)             | Accepted           |
| 0011 | [Credit balances & ledger (stretch)](0011-credit-balance-ledger.md)               | Accepted (revised) |
| 0012 | [Admin spend report (stretch)](0012-admin-spend-report.md)                        | Accepted (revised) |
| 0013 | [Security baseline](0013-security-baseline.md)                                    | Accepted           |
| 0014 | [Testing strategy](0014-testing-strategy.md)                                      | Accepted           |
| 0015 | [Background health, degraded mode & alerts](0015-background-health-and-alerts.md) | Accepted           |

## Template

```markdown
# ADR-XXXX: Title

- Status: Proposed
- Date: YYYY-MM-DD

## Context

What forces are at play? What problem are we solving?

## Decision

What we will do.

## Alternatives considered

- Option: why not.

## Consequences

### Positive

### Drawbacks
```
