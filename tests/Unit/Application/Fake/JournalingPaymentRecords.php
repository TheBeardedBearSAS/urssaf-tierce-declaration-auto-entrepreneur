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

use Closure;
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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;

/**
 * Decorator over the production in-memory repository: journals every save ("save:<id>"), every won claim
 * ("claim:<id>"), every delete by SIRET ("deleteBySiret:<number of ids>"); a claim can be made to lose, like another
 * worker winning it.
 *
 * One method per method of the port.
 *
 * @mago-expect lint:too-many-methods
 */
final class JournalingPaymentRecords implements PaymentRecordRepository
{
    private readonly InMemoryPaymentRecordRepository $inner;

    /** @var list<string> */
    private array $refusedClaims = [];

    /** @var (Closure(InMemoryPaymentRecordRepository): void)|null */
    private ?Closure $intrusion = null;

    public function __construct(private readonly Journal $journal)
    {
        $this->inner = new InMemoryPaymentRecordRepository();
    }

    public function inner(): InMemoryPaymentRecordRepository
    {
        return $this->inner;
    }

    /**
     * Stores without journaling (test setup).
     */
    public function add(PaymentRecord ...$records): void
    {
        foreach ($records as $record) {
            $this->inner->save($record);
        }
    }

    /**
     * Simulates another worker winning the claim: claimForSubmission() returns false for this record.
     */
    public function refuseClaim(PaymentRecordId $id): void
    {
        $this->refusedClaims[] = $id->value();
    }

    /**
     * Runs $intrusion once, just before the next save reaches the store: another process writing in between.
     *
     * @param Closure(InMemoryPaymentRecordRepository): void $intrusion
     *
     * @param-immediately-invoked-callable $intrusion
     */
    public function beforeNextSave(Closure $intrusion): void
    {
        $this->intrusion = $intrusion;
    }

    #[Override]
    public function save(PaymentRecord $record): void
    {
        $this->journal->add('save:' . $record->id()->value());
        if ($this->intrusion instanceof Closure) {
            $intrusion = $this->intrusion;
            $this->intrusion = null;
            $intrusion($this->inner);
        }
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
        if (\in_array($id->value(), $this->refusedClaims, true)) {
            return false;
        }
        $claimed = $this->inner->claimForSubmission($id, $now);
        if ($claimed) {
            $this->journal->add('claim:' . $id->value());
        }

        return $claimed;
    }

    #[Override]
    public function countBySiret(TenantId $tenantId, Siret $siret): array
    {
        return $this->inner->countBySiret($tenantId, $siret);
    }

    #[Override]
    public function deleteBySiret(TenantId $tenantId, Siret $siret, array $states): array
    {
        $ids = $this->inner->deleteBySiret($tenantId, $siret, $states);
        $this->journal->add('deleteBySiret:' . \count($ids));

        return $ids;
    }
}
