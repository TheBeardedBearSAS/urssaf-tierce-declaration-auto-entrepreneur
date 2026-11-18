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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Payment record repository that saves another record after the erasure of the records of a SIRET, to simulate another
 * process creating a payment while the SIRET is being erased. Without an intruder it only remembers the states of the
 * erasure request.
 *
 * One method per method of the port.
 *
 * @mago-expect lint:too-many-methods
 */
final class RacingPayments implements PaymentRecordRepository
{
    /** @var list<PaymentState> */
    private array $requestedStates = [];

    public function __construct(
        private readonly PaymentRecordRepository $inner,
        private readonly ?PaymentRecord $intruder,
    ) {}

    /**
     * @return list<PaymentState> the states of the last erasure request
     */
    public function requestedStates(): array
    {
        return $this->requestedStates;
    }

    #[Override]
    public function save(PaymentRecord $record): void
    {
        $this->inner->save($record);
    }

    #[Override]
    public function get(PaymentRecordId $id): PaymentRecord
    {
        return $this->inner->get($id);
    }

    #[Override]
    public function findByReference(TenantId $tenantId, PaymentReference $reference): ?PaymentRecord
    {
        return $this->inner->findByReference($tenantId, $reference);
    }

    #[Override]
    public function findCurrentForPeriod(TenantId $tenantId, Siret $siret, DeclarationPeriod $period, array $states): array
    {
        return $this->inner->findCurrentForPeriod($tenantId, $siret, $period, $states);
    }

    #[Override]
    public function findByState(TenantId $tenantId, PaymentState $state, int $limit): array
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
    public function tenantsWithState(PaymentState $state): array
    {
        return $this->inner->tenantsWithState($state);
    }

    #[Override]
    public function claimForSubmission(PaymentRecordId $id, Instant $now): bool
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
        if ($this->intruder instanceof PaymentRecord) {
            $this->inner->save($this->intruder);
        }

        return $deleted;
    }
}
