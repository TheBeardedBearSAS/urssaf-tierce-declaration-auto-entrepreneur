<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrinePaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineWorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\WorkflowStatisticsContract;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;

/**
 * The shared statistics contract on an in-memory SQLite database, the aggregates stored by the Doctrine repositories.
 */
final class DoctrineWorkflowStatisticsContractTest extends TestCase
{
    use WorkflowStatisticsContract;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
    }

    protected function createStatistics(): WorkflowStatistics
    {
        return new DoctrineWorkflowStatistics($this->entityManager);
    }

    protected function store(object ...$aggregates): void
    {
        foreach ($aggregates as $aggregate) {
            match (true) {
                $aggregate instanceof AeAccount => (new DoctrineAeAccountRepository($this->entityManager))->save($aggregate),
                $aggregate instanceof Mandate => (new DoctrineMandateRepository($this->entityManager))->save($aggregate),
                $aggregate instanceof DeclarationRecord => (new DoctrineDeclarationRecordRepository($this->entityManager))->save($aggregate),
                $aggregate instanceof PaymentRecord => (new DoctrinePaymentRecordRepository($this->entityManager))->save($aggregate),
                default => self::fail('Unsupported aggregate ' . $aggregate::class . '.'),
            };
        }
    }

    public function testTheCountsAreOrderedByTenantThenStateWhateverTheInsertionOrder(): void
    {
        $this->store(
            ContractSamples::cancelledMandate(1, 1, ContractSamples::OTHER_TENANT),
            ContractSamples::mandate(2, 2),
            ContractSamples::cancelledMandate(3, 3),
            ContractSamples::declaration(1, 1, ContractSamples::monthly(), ContractSamples::OTHER_TENANT),
            ContractSamples::declarationIn(DeclarationState::Declared, 2, 2, ContractSamples::monthly()),
            ContractSamples::declarationIn(DeclarationState::Declared, 3, 3, ContractSamples::monthly()),
            ContractSamples::declaration(4, 4, ContractSamples::monthly()),
            ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly(), ContractSamples::OTHER_TENANT),
            ContractSamples::paymentIn(PaymentState::Paid, 2, 2, 'payment-ref-2', ContractSamples::monthly()),
            ContractSamples::paymentIn(PaymentState::Paid, 3, 3, 'payment-ref-3', ContractSamples::monthly()),
            ContractSamples::payment(4, 4, 'payment-ref-4', ContractSamples::monthly()),
        );
        $statistics = $this->createStatistics();

        static::assertSame(['tenant-1/cancelled/1', 'tenant-1/notified/1', 'tenant-2/cancelled/1'], self::listed($statistics->mandateStates()));
        static::assertSame(['tenant-1/declared/2', 'tenant-1/pending/1', 'tenant-2/pending/1'], self::listed($statistics->declarationStates()));
        static::assertSame(['tenant-1/paid/2', 'tenant-1/pending/1', 'tenant-2/pending/1'], self::listed($statistics->paymentStates()));
    }

    /**
     * @param list<StateCount> $counts
     *
     * @return list<string>
     */
    private static function listed(array $counts): array
    {
        return array_map(static fn(StateCount $count): string => \sprintf('%s/%s/%d', $count->tenantId->value(), $count->state, $count->count), $counts);
    }
}
