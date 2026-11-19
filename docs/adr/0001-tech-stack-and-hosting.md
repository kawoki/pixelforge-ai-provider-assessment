# ADR-0001: Tech stack and hosting

- Status: Accepted
- Date: 2026-11-19

## Context

The assessment requires Laravel, Vue and Inertia, a public deployment, and auth. We care most about
usability, extensibility, maintainability and security. Visual polish matters less.
Correct money handling needs a database with real transactions, row locks and exact decimals.

## Decision

| Concern         | Choice                                                                               |
| --------------- | ------------------------------------------------------------------------------------ |
| Backend         | Laravel 13 (from `laravel new`), PHP ^8.3 (8.4 locally/CI)                           |
| Frontend        | Vue 3 (Composition API, `<script setup>`, TypeScript) + Inertia v3                   |
| Scaffold        | Official Laravel **Vue starter kit** (auth, layout, Tailwind, shadcn-vue components) |
| Database        | **PostgreSQL** in every environment (local, CI, production); sessions forced to UTC  |
| Queue           | `database` queue driver, at least one worker                                         |
| Scheduler       | Laravel scheduler (reconciliation job, see ADR-0010)                                 |
| Cache / locks   | `database` cache store (Redis optional later)                                        |
| Decimal math    | `brick/math` (see ADR-0003)                                                          |
| Tests           | Pest (see ADR-0014)                                                                  |
| Static analysis | Larastan (level 7), Laravel Pint, oxlint + oxfmt (vite-plus), vue-tsc                |
| Hosting         | **Laravel Cloud**: app + Postgres + queue worker + scheduler                         |
| HTTP tunnel     | Expose / ngrok / Cloudflare Tunnel for local webhooks                                |

## Alternatives considered

- **SQLite locally, Postgres in prod**: easier setup, but `lockForUpdate`, numeric precision and
  unique-violation behaviour differ, so money bugs could hide in dev. Rejected.
- **MySQL**: works fine. We chose Postgres for strict `numeric`, partial unique indexes and `jsonb`,
  and because Laravel Cloud provides it with no extra setup.
- **Redis queue/cache**: better throughput and `Redis::funnel` concurrency limiting. It adds infra
  that this load doesn't need. Can be swapped in later with config changes only.
- **Hand-rolled auth / Breeze**: the starter kit is the current official path and ships tested auth.
- **Forge/VPS/Fly**: more control, more ops work. Laravel Cloud is quickest to a public URL.

## Consequences

### Positive

- One DB engine everywhere, so dev and test results match production for locking and decimals.
- The pgsql connection sets `timezone = UTC`. Phase 5 found that a local server in `Asia/Manila` shifted every
  stored timestamp by 8 h, because Laravel writes timestamps without an offset. A regression test guards this.
- The starter kit gives auth, email verification, password reset and the layout with no extra work.
- Every infra piece (queue, cache, DB) is replaceable through config.

### Drawbacks

- Local setup needs Postgres (we use Homebrew `postgresql@17`; Herd Pro / DBngin / Docker also work), which takes more effort than SQLite.
- The database queue is slower than Redis and polls the DB. It's fine at this scale, but not for high throughput.
- The starter kit brings UI components and conventions we didn't choose, and upgrades follow its pace.
- Laravel Cloud pricing and platform lock-in: workers, the scheduler and the DB are Cloud-managed.
- Tying to "latest stable" Laravel means the docs and kit can change between scaffold time and interview time.
