<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Declaration record repository that saves another record after the erasure of the records of a SIRET, to simulate
 * another process creating a record while the SIRET is being erased. Without an intruder it only remembers the states of
 * the erasure request.
 *
 * One method per method of the port.
 *
 * @mago-expect lint:too-many-methods
 */
final class RacingRecords implements DeclarationRecordRepository
{
    /** @var list<DeclarationState> */
    private array $requestedStates = [];

    public function __construct(
        private readonly DeclarationRecordRepository $inner,
        private readonly ?DeclarationRecord $intruder,
    ) {}

    /**
     * @return list<DeclarationState> the states of the last erasure request
     */
    public function requestedStates(): array
    {
        return $this->requestedStates;
    }

    #[Override]
    public function save(DeclarationRecord $record): void
    {
        $this->inner->save($record);
    }

    #[Override]
    public function get(DeclarationRecordId $id): DeclarationRecord
    {
        return $this->inner->get($id);
    }

    #[Override]
    public function findCurrent(TenantId $tenantId, Siret $siret, DeclarationPeriod $period): ?DeclarationRecord
    {
        return $this->inner->findCurrent($tenantId, $siret, $period);
    }

    #[Override]
    public function findByState(TenantId $tenantId, DeclarationState $state, int $limit): array
    {
        return $this->inner->findByState($tenantId, $state, $limit);
    }

    #[Override]
    public function findStaleSubmissions(Instant $olderThan, int $limit): array
    {
        return $this->inner->findStaleSubmissions($olderThan, $limit);
    }

    #[Override]
    public function countByState(TenantId $tenantId): array
    {
        return $this->inner->countByState($tenantId);
    }

    #[Override]
    public function countAcceptedAfterSupersession(TenantId $tenantId): int
    {
        return $this->inner->countAcceptedAfterSupersession($tenantId);
    }

    #[Override]
    public function tenantsWithState(DeclarationState $state): array
    {
        return $this->inner->tenantsWithState($state);
    }

    #[Override]
    public function claimForSubmission(DeclarationRecordId $id, Instant $now): bool
    {
        return $this->inner->claimForSubmission($id, $now);
    }

    #[Override]
    public function countBySiret(TenantId $tenantId, Siret $siret): array
    {
        return $this->inner->countBySiret($tenantId, $siret);
    }

    #[Override]
    public function deleteBySiret(TenantId $tenantId, Siret $siret, array $states): array
    {
        $this->requestedStates = $states;
        $deleted = $this->inner->deleteBySiret($tenantId, $siret, $states);
        if ($this->intruder instanceof DeclarationRecord) {
            $this->inner->save($this->intruder);
        }

        return $deleted;
    }
}
