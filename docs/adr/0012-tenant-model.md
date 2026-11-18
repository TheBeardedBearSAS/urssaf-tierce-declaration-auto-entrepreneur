# ADR 0012 — Tenant model

Status: Accepted (2026-11-17). Relates to [ADR 0000](0000-reference-architecture.md).

## Context

The reference is multi-tenant (ADR 0000 item 10): `TenantId` on every aggregate and row, `CredentialsProvider::forTenant()`,
uniqueness, locks and rate limiting per tenant. The AE API authenticates a platform with OAuth2 client credentials
(scope `tiersdecl.ae`, api-reference §1); `mandatPresent` is reported "for the calling platform" (§2.1); an AE cannot be
mandated by two platforms at the same time (`SIRET_DEJA_MANDATE`, §1.2, §2.2); the quota applies per API client (§1.1,
ADR 0013). A host may run several platforms, or one platform with several credential sets (sandbox and production
separated by environment, not by tenant).

## Decision

1. **One tenant = one OAuth2 credential set** (client id / client secret) of a third-party declarant platform for one
   environment. `Domain\Tenant\TenantId` (non-empty string, the reference constraints) identifies it; the host chooses
   the value.
2. **`TenantId` everywhere.** `AeAccount`, `Mandate`, `DeclarationRecord`, `PaymentRecord` and every other aggregate
   of ADR 0009 carry it; every command, query and repository method takes it; every Doctrine table has a `tenant_id`
   column leading its unique constraints and indexes (ADR 0016); integration events carry it (ADR 0014).
3. **Credentials.** `Application\Port\CredentialsProvider::forTenant(TenantId): Credentials`; `Credentials` is not
   serialisable and masks the secret (ADR 0000 item 3). An unknown tenant is a `RecordNotFound` raised before any
   call. Token cache key: hash of environment, base URI and client id, never the secret (reference behaviour): two
   tenants sharing a client id share the token.
4. **Scope of uniqueness.** The same SIRET may be tracked under two tenants: the package does not enforce the single
   platform rule across tenants, URSSAF does (`SIRET_DEJA_MANDATE`). Within a tenant, one `AeAccount` per SIRET and one
   active mandate per SIRET (ADR 0016 key table).
5. **Locks and rate limiting** are scoped by tenant: lock names include the tenant (ADR 0015), the limiter is keyed by
   tenant (ADR 0013).
6. **Bridges.** The Symfony bundle declares tenants under `credentials` (`client_id`, `client_secret` per tenant, the
   secret through `%env()%`) or takes a `credentials_provider` service; every command that needs a tenant has
   `--tenant`, defaulting to the only configured tenant and refusing (exit `2`) when several are configured. The
   standalone builder takes a `CredentialsProvider` or a single credential set bound to one `TenantId`.

## Consequences

- A host serving several platforms isolates their data, tokens, quotas and locks without extra code.
- Moving an AE between tenants is a host operation: `ForgetAccount` (ADR 0017) under one tenant, import under the other.
- Tenant ids are not personal data and may appear in logs, lock names and events.
