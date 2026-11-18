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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrinePaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\PaymentRecordRepositoryContract;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\FileDatabase;

/**
 * The shared contract on a SQLite file (own connection per entity manager, real file locking): the other writer is an
 * entity manager of its own on the same file.
 */
final class DoctrineFilePaymentRecordRepositoryContractTest extends TestCase
{
    use PaymentRecordRepositoryContract {
        setUp as private contractSetUp;
    }

    private FileDatabase $file;

    private EntityManagerInterface $entityManager;

    private ?PaymentRecordRepository $other = null;

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

    protected function createRepository(): PaymentRecordRepository
    {
        return new DoctrinePaymentRecordRepository($this->entityManager);
    }

    protected function transactions(): TransactionManager
    {
        return new DoctrineTransactionManager($this->entityManager);
    }

    protected function anotherWriter(): PaymentRecordRepository
    {
        return $this->other ??= new DoctrinePaymentRecordRepository($this->file->entityManager());
    }
}
