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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;

/**
 * Proves the contract itself: the in-memory repository satisfies the same scenarios as the Doctrine one.
 */
final class InMemoryAeAccountRepositoryContractTest extends TestCase
{
    use AeAccountRepositoryContract;

    protected function createRepository(): AeAccountRepository
    {
        return new InMemoryAeAccountRepository();
    }

    protected function anotherWriter(): AeAccountRepository
    {
        return $this->repository;
    }
}
