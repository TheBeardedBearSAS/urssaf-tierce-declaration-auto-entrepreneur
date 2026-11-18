<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Persistence port of the {@see AeAccount} aggregate. Expected uniqueness: the id and (tenant, SIRET) (ADR 0012, 0016).
 */
interface AeAccountRepository
{
    /** Violation code of a save refused because another writer changed the account since it was read. */
    public const CONCURRENT_MODIFICATION = 'ae_account.concurrent_modification';

    /** Violation code of a save refused because the tenant already tracks another account for the same SIRET. */
    public const DUPLICATE_SIRET = 'ae_account.duplicate_siret';

    /**
     * Inserts or updates. Domain events are not persisted: release and publish them separately.
     *
     * Optimistic concurrency (ADR 0009, ADR 0016 item 6): a copy that another writer changed (saved or erased) since it was read is
     * refused. The copy is then stale: read the account again.
     *
     * @throws InvariantViolation "ae_account.concurrent_modification" (see {@see self::CONCURRENT_MODIFICATION}) or
     *                            "ae_account.duplicate_siret" (see {@see self::DUPLICATE_SIRET})
     */
    public function save(AeAccount $account): void;

    /**
     * @throws RecordNotFound "ae_account.not_found" (the identifier is the account id)
     */
    public function get(AccountId $id): AeAccount;

    public function findBySiret(TenantId $tenantId, Siret $siret): ?AeAccount;

    /**
     * Accounts of the tenant for the status synchronisation (method 020, the only polling): least recently synced
     * first, ties by id.
     *
     * @param positive-int $limit
     *
     * @return list<AeAccount>
     */
    public function findLeastRecentlySynced(TenantId $tenantId, int $limit): array;

    /**
     * Distinct tenants owning at least one account.
     *
     * @return list<TenantId>
     */
    public function tenants(): array;

    /**
     * GDPR erasure (ADR 0017); nothing happens when the account does not exist.
     */
    public function delete(AccountId $id): void;
}
