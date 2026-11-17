<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Persistence port of the {@see Mandate} aggregate. Expected uniqueness: the id, and at most one non-cancelled mandate
 * per (tenant, SIRET) (ADR 0009, 0016).
 */
interface MandateRepository
{
    /** Violation code of a save refused because another writer changed the mandate since it was read. */
    public const CONCURRENT_MODIFICATION = 'mandate.concurrent_modification';

    /** Violation code of a save refused because the SIRET already has an active mandate. */
    public const DUPLICATE_ACTIVE = 'mandate.duplicate_active';

    /**
     * Inserts or updates. Domain events are not persisted: release and publish them separately.
     *
     * Optimistic concurrency (ADR 0009, ADR 0016 item 6): a copy that another writer changed (saved or erased) since it was read is
     * refused. The copy is then stale: read the mandate again.
     *
     * @throws InvariantViolation "mandate.concurrent_modification" (see {@see self::CONCURRENT_MODIFICATION}) or
     *                            "mandate.duplicate_active" (see {@see self::DUPLICATE_ACTIVE})
     */
    public function save(Mandate $mandate): void;

    /**
     * @throws RecordNotFound "mandate.not_found"
     */
    public function get(MandateId $id): Mandate;

    /**
     * The non-cancelled mandate of the SIRET, if any (mandate guard, ADR 0011 item 1; method 031).
     */
    public function findActive(TenantId $tenantId, Siret $siret): ?Mandate;

    /**
     * Every mandate of the SIRET, oldest notification first.
     *
     * @return list<Mandate>
     */
    public function findBySiret(TenantId $tenantId, Siret $siret): array;

    /**
     * GDPR erasure (ADR 0017): removes the mandate and, in the Doctrine bridge, its active-mandate key row, so that the
     * SIRET can hold a new active mandate afterwards. Nothing happens when the mandate does not exist. No event.
     */
    public function delete(MandateId $id): void;
}
