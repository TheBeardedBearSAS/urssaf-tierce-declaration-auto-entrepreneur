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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryWorkflowStatistics;

/**
 * Proves the contract itself: the in-memory statistics satisfy the same scenarios as the Doctrine ones.
 */
final class InMemoryWorkflowStatisticsContractTest extends TestCase
{
    use WorkflowStatisticsContract;

    private InMemoryAeAccountRepository $accounts;

    private InMemoryMandateRepository $mandates;

    private InMemoryDeclarationRecordRepository $declarations;

    private InMemoryPaymentRecordRepository $payments;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAeAccountRepository();
        $this->mandates = new InMemoryMandateRepository();
        $this->declarations = new InMemoryDeclarationRecordRepository();
        $this->payments = new InMemoryPaymentRecordRepository();
    }

    protected function createStatistics(): WorkflowStatistics
    {
        return new InMemoryWorkflowStatistics($this->mandates, $this->declarations, $this->payments);
    }

    protected function store(object ...$aggregates): void
    {
        foreach ($aggregates as $aggregate) {
            match (true) {
                $aggregate instanceof AeAccount => $this->accounts->save($aggregate),
                $aggregate instanceof Mandate => $this->mandates->save($aggregate),
                $aggregate instanceof DeclarationRecord => $this->declarations->save($aggregate),
                $aggregate instanceof PaymentRecord => $this->payments->save($aggregate),
                default => self::fail('Unsupported aggregate ' . $aggregate::class . '.'),
            };
        }
    }
}
