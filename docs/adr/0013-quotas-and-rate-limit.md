# ADR 0013 — Quota of 300 requests per minute per API client

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

PDF §2.5 (api-reference §1.1) throttles at **300 requests per minute** on two levels: a maximum per API (all clients
together) and a quota per API client. Exceeding it gives HTTP 429. No per-operation limit, no `Retry-After` or rate
headers, and no 429 in the Swagger are published (§1.1, §5). api-reference §4.12 left the client-side throttle "TBD"
and proposed none; this ADR settles it. The reference limits per operation with published values; CESU
(published hourly quota) uses a sliding window per tenant, on by default in the factory and off by default in the
bundle, like the reference.

## Decision

1. **Port kept.** `Application\Port\RateLimiter::acquire(TenantId, Operation): void` is called by the gateway before
   each API request. `Operation` (one case per method, 020 to 075, with its number) carries no quota: the limit is
   shared by all operations.
2. **Default limiter.** `Infrastructure\RateLimiter\SlidingWindowRateLimiter` (reference namespace) counts the API requests per tenant (one
   tenant = one API client, ADR 0012) over a sliding window, default **300 per 60 s**, all operations together. Token
   requests (`/api/oauth/v1/token`, another API) are not counted. The replay after a 401 (ADR 0000 item 7) counts.
   `UrssafGatewayFactory` uses it by default; `GatewayOptions::withRateLimiter()` accepts a `NoopRateLimiter` or a
   host limiter shared across processes.
3. **Configuration** (deviation: the reference bundle only has `rate_limiter.enabled`, its quotas being fixed per
   operation; here the single published value may change, so it is configurable). `GatewayOptions::withRateLimit(int
   $limit, int $windowSeconds)`; bundle `rate_limiter.enabled` (default `false`, as in the reference and CESU),
   `rate_limiter.limit` (default `300`, int ≥ 1),
   `rate_limiter.window` (default `PT60S`), `rate_limiter.service` (a host `RateLimiter`, e.g. backed by
   symfony/rate-limiter and a shared store). A limit above 300 is accepted (the published value may change) and
   reported as a warning by `urssaf:tdae:check`.
4. **Local refusal.** A full window throws `RateLimitExceeded` (a `RetryableException`, `retryAfterSeconds()` = the
   time until the oldest request leaves the window) **before** anything is sent: `mayHaveReachedUrssaf()` is
   `false`, so it is safe on every operation, 060 included; the record of a write goes to `error` (proven not sent,
   ADR 0009).
5. **HTTP 429.** Classified as `UrssafUnavailable` with `isQuotaExceeded()` and `retryAfterSeconds()` from
   `Retry-After` when present (delta-seconds only, ADR 0000 item 7; not published, so usually `null`). A 429 is an
   answer sent before processing (§4.6): `mayHaveReachedUrssaf()` is `false`, and it stays retryable on every retry
   class, 060 included, because the call provably did not reach the service ([ADR 0003](0003-retry-and-unknown-outcome.md));
   the record goes to `error`, not `outcome_unknown` (ADR 0009). The gateway never loops on 429 by itself: the caller
   (or Messenger for 020) retries after the delay.
6. **Not enforced.** The per-API level (all clients of URSSAF together) cannot be known nor enforced locally; the
   limiter is per process (no cross-process enforcement unless the host supplies a shared limiter). Both are documented.

## Consequences

- A single process of one tenant stays under the published quota by default with the standalone factory; Symfony
  hosts opt in, or plug a shared limiter when several workers or crons use the same credentials.
- Messenger maps `RateLimitExceeded` and a 429 to a recoverable failure with the known delay (ADR 0015).
- When URSSAF publishes other values or headers, only the defaults and the 429 parsing change.
