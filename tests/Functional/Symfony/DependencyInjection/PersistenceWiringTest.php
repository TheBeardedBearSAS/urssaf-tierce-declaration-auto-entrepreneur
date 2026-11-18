<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\PersistenceRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryWorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;

/**
 * The persistence drivers "in_memory" (default) and "custom"; the "doctrine" one is in DoctrineWiringTest.
 */
final class PersistenceWiringTest extends TestCase
{
    private const CUSTOM = [
        'driver' => 'custom',
        'account_repository' => 'test.accounts',
        'mandate_repository' => 'test.mandates',
        'declaration_record_repository' => 'test.declarations',
        'payment_record_repository' => 'test.payments',
        'transaction_manager' => null,
        'workflow_statistics' => null,
        'entity_manager' => 'default',
    ];

    public function testTheInMemoryDriverRegistersTheInMemoryImplementations(): void
    {
        $harness = Harness::boot();

        static::assertInstanceOf(InMemoryAeAccountRepository::class, $harness->privateService(AeAccountRepository::class, AeAccountRepository::class));
        static::assertInstanceOf(InMemoryMandateRepository::class, $harness->privateService(MandateRepository::class, MandateRepository::class));
        static::assertInstanceOf(InMemoryDeclarationRecordRepository::class, $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class));
        static::assertInstanceOf(InMemoryPaymentRecordRepository::class, $harness->privateService(PaymentRecordRepository::class, PaymentRecordRepository::class));
        static::assertInstanceOf(ImmediateTransactionManager::class, $harness->privateService(TransactionManager::class, TransactionManager::class));
        static::assertInstanceOf(InMemoryWorkflowStatistics::class, $harness->privateService(WorkflowStatistics::class, WorkflowStatistics::class));
    }

    public function testTheStatisticsReadTheRepositoriesOfTheApplication(): void
    {
        $harness = Harness::boot();

        Scenario::payment($harness);
        Scenario::declaration($harness);

        $statistics = $harness->privateService(WorkflowStatistics::class, WorkflowStatistics::class);
        static::assertNotSame([], $statistics->mandateStates());
        static::assertNotSame([], $statistics->declarationStates());
        static::assertNotSame([], $statistics->paymentStates());
    }

    public function testTheCustomDriverUsesTheRepositoriesOfTheApplication(): void
    {
        $harness = Harness::boot(['persistence' => self::CUSTOM]);

        static::assertSame($harness->privateService('test.accounts', AeAccountRepository::class), $harness->privateService(AeAccountRepository::class, AeAccountRepository::class));
        static::assertSame($harness->privateService('test.mandates', MandateRepository::class), $harness->privateService(MandateRepository::class, MandateRepository::class));
        static::assertSame($harness->privateService('test.declarations', DeclarationRecordRepository::class), $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class));
        static::assertSame($harness->privateService('test.payments', PaymentRecordRepository::class), $harness->privateService(PaymentRecordRepository::class, PaymentRecordRepository::class));
        static::assertInstanceOf(ImmediateTransactionManager::class, $harness->privateService(TransactionManager::class, TransactionManager::class));
        static::assertNotSame($harness->privateService('test.transactions', TransactionManager::class), $harness->privateService(TransactionManager::class, TransactionManager::class));
        static::assertFalse($harness->hasPrivate(WorkflowStatistics::class));
    }

    public function testTheCustomDriverUsesTheTransactionManagerAndTheStatisticsOfTheApplication(): void
    {
        $harness = Harness::boot(['persistence' => [...self::CUSTOM, 'transaction_manager' => 'test.transactions', 'workflow_statistics' => 'test.statistics']]);

        static::assertSame($harness->privateService('test.transactions', TransactionManager::class), $harness->privateService(TransactionManager::class, TransactionManager::class));
        static::assertSame($harness->privateService('test.statistics', WorkflowStatistics::class), $harness->privateService(WorkflowStatistics::class, WorkflowStatistics::class));
    }

    public function testTheStatisticsPredicate(): void
    {
        static::assertTrue(PersistenceRegistrar::hasStatistics('in_memory', null));
        static::assertTrue(PersistenceRegistrar::hasStatistics('custom', 'my.statistics'));
        static::assertFalse(PersistenceRegistrar::hasStatistics('custom', null));
    }
}
