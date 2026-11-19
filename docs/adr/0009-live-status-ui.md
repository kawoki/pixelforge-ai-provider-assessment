# ADR-0009: Live status in the UI

- Status: Accepted (revised 2026-11-19, phase 6: push first, polling as the fallback)
- Date: 2026-11-19

## Context

The History page must show live status (queued → processing → succeeded/failed), costs and the image.
The first version of this ADR chose Inertia polling only. During phase 6 the product owner asked for
polling to be the **fallback**, not the main mechanism, and for the page to keep working when
background processes fail (ADR-0015).

## Decision

**Push first.** Every committed status change (`GenerationLifecycleService`) publishes `GenerationUpdated`
to the owner's private channel `App.Models.User.{id}` via **Laravel Reverb**:

- `ShouldBroadcastNow`: pushed immediately, **not through the queue**, so live updates don't depend on the worker.
- Published with `DB::afterCommit`, so we never announce a change that could still roll back.
- The payload carries **ids only** (`{id, status}`). The page reloads the generation through the normal,
  authorised Inertia props, so there is one data shape and nothing sensitive goes over the socket.
- A broadcast failure (Reverb down) is caught and logged. The status change itself is never affected.
- If no `VITE_REVERB_APP_KEY` is configured, Echo is never started and the page just polls.

**Polling is the fallback.** `useLiveGenerations` picks a mode (`resolveLiveMode`):

| Mode       | When                                               | What the page does                                                 |
| ---------- | -------------------------------------------------- | ------------------------------------------------------------------ |
| `idle`     | nothing in flight                                  | nothing                                                            |
| `live`     | WebSocket connected                                | reload on each push; 30 s backstop poll in case a push is lost     |
| `polling`  | WebSocket not connected (Reverb down/unconfigured) | reload every 4 s                                                   |
| `fallback` | background processing is down (ADR-0015)           | every 5 s, ask the server to catch up with Pixelforge, then reload |

A notice tells users which mode they're in, in plain words.

## Alternatives considered

- **Polling only** (the original decision): simplest, but it was rejected by the product owner, and it's wasteful and laggy.
- **Pusher/Ably**: managed and reliable, but a third-party account and data processor. Reverb is first-party and
  runs locally. Switching later is a config change.
- **Server-Sent Events**: lighter than WebSockets, but awkward with PHP-FPM/Octane connection limits.
- **Send the full generation in the push payload**: saves a request, but duplicates the resource shape and pushes
  money data over the socket. Rejected.

## Consequences

### Positive

- Updates appear within a second while everything is healthy. Polling only happens when needed.
- Every failure mode degrades to something that still works: no Reverb → polling, no worker → fallback mode.

### Drawbacks

- Another long-running process (Reverb) to deploy and monitor. On Laravel Cloud it needs a WebSocket
  resource. Without one, set no Reverb key and the app polls.
- One extra HTTP request per push (the reload). Fine at 3 events per generation.
- Each open History tab holds a WebSocket connection.
- The browser can only see its own connection. If Reverb accepts connections but drops events, the 30 s
  backstop is the only safety net.
- No frontend unit tests for the mode logic (the kit ships no JS test runner). `resolveLiveMode` is a small pure
  function, kept that way so a test can be added cheaply.
