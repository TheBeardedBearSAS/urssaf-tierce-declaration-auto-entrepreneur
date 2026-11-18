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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\AeAccountRepositoryContract;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\FileDatabase;

/**
 * The shared contract on a SQLite file (own connection per entity manager, real file locking): the other writer is an
 * entity manager of its own on the same file.
 */
final class DoctrineFileAeAccountRepositoryContractTest extends TestCase
{
    use AeAccountRepositoryContract {
        setUp as private contractSetUp;
    }

    private FileDatabase $file;

    private EntityManagerInterface $entityManager;

    private ?AeAccountRepository $other = null;

    protected function setUp(): void
    {
        $this->file = new FileDatabase();
        $this->entityManager = $this->file->entityManager();
        $this->contractSetUp();
    }

    protected function tearDown(): void
    {
        $this->file->delete();
    }

    protected function createRepository(): AeAccountRepository
    {
        return new DoctrineAeAccountRepository($this->entityManager);
    }

    protected function anotherWriter(): AeAccountRepository
    {
        return $this->other ??= new DoctrineAeAccountRepository($this->file->entityManager());
    }
}
