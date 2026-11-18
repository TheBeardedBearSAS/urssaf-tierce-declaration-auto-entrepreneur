<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;

/**
 * Behaviour shared by every WorkflowStatistics adapter: counts by tenant and state, the pairs without record omitted.
 * Accounts have no state and are not counted.
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 */
trait WorkflowStatisticsContract
{
    abstract protected function createStatistics(): WorkflowStatistics;

    /**
     * Stores the aggregates (accounts, mandates, declaration records, payment records) where the statistics count them.
     */
    abstract protected function store(object ...$aggregates): void;

    public function testNothingIsCountedWithoutRecords(): void
    {
        $statistics = $this->createStatistics();

        static::assertSame([], $statistics->mandateStates());
        static::assertSame([], $statistics->declarationStates());
        static::assertSame([], $statistics->paymentStates());
    }

    public function testTheAccountsAreNotCounted(): void
    {
        $statistics = $this->createStatistics();
        $this->store(ContractSamples::account(1), ContractSamples::account(2, ContractSamples::OTHER_TENANT));

        static::assertSame([], $statistics->mandateStates());
        static::assertSame([], $statistics->declarationStates());
        static::assertSame([], $statistics->paymentStates());
    }

    public function testTheMandatesAreCountedByTenantAndState(): void
    {
        $statistics = $this->createStatistics();
        $this->store(
            ContractSamples::cancelledMandate(1, 1),
            ContractSamples::cancelledMandate(2, 1),
            ContractSamples::mandate(3, 1),
            ContractSamples::mandate(4, 1, ContractSamples::OTHER_TENANT),
        );

        static::assertSame(
            ['tenant-1/cancelled=2', 'tenant-1/notified=1', 'tenant-2/notified=1'],
            self::normalized($statistics->mandateStates()),
        );
        static::assertSame([], $statistics->declarationStates());
        static::assertSame([], $statistics->paymentStates());
    }

    public function testTheDeclarationsAreCountedByTenantAndState(): void
    {
        $statistics = $this->createStatistics();
        $this->store(
            ContractSamples::declaration(1, 1, ContractSamples::monthly()),
            ContractSamples::declarationIn(DeclarationState::Declared, 2, 2, ContractSamples::monthly()),
            ContractSamples::declarationIn(DeclarationState::Declared, 3, 3, ContractSamples::monthly()),
            ContractSamples::declarationIn(DeclarationState::OutcomeUnknown, 4, 4, ContractSamples::monthly(), ContractSamples::OTHER_TENANT),
        );

        static::assertSame(
            ['tenant-1/declared=2', 'tenant-1/pending=1', 'tenant-2/outcome_unknown=1'],
            self::normalized($statistics->declarationStates()),
        );
        static::assertSame([], $statistics->mandateStates());
        static::assertSame([], $statistics->paymentStates());
    }

    public function testThePaymentsAreCountedByTenantAndState(): void
    {
        $statistics = $this->createStatistics();
        $this->store(
            ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()),
            ContractSamples::paymentIn(PaymentState::Paid, 2, 2, 'payment-ref-2', ContractSamples::monthly()),
            ContractSamples::paymentIn(PaymentState::Paid, 3, 3, 'payment-ref-3', ContractSamples::monthly()),
            ContractSamples::paymentIn(PaymentState::Rejected, 4, 4, 'payment-ref-4', ContractSamples::monthly(), ContractSamples::OTHER_TENANT),
        );

        static::assertSame(
            ['tenant-1/paid=2', 'tenant-1/pending=1', 'tenant-2/rejected=1'],
            self::normalized($statistics->paymentStates()),
        );
        static::assertSame([], $statistics->mandateStates());
        static::assertSame([], $statistics->declarationStates());
    }

    /**
     * @param list<StateCount> $counts
     *
     * @return list<string> "tenant/state=count", sorted (the adapters do not guarantee an order)
     */
    private static function normalized(array $counts): array
    {
        $normalized = array_map(
            static fn(StateCount $count): string => \sprintf('%s/%s=%d', $count->tenantId->value(), $count->state, $count->count),
            $counts,
        );
        sort($normalized);

        return $normalized;
    }
}
