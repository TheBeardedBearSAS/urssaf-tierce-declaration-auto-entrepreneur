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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;

/**
 * Proves the contract itself: the in-memory repository satisfies the same scenarios as the Doctrine one.
 */
final class InMemoryDeclarationRecordRepositoryContractTest extends TestCase
{
    use DeclarationRecordRepositoryContract;

    protected function createRepository(): DeclarationRecordRepository
    {
        return new InMemoryDeclarationRecordRepository();
    }

    protected function transactions(): TransactionManager
    {
        return new ImmediateTransactionManager();
    }

    protected function anotherWriter(): DeclarationRecordRepository
    {
        return $this->repository;
    }
}
