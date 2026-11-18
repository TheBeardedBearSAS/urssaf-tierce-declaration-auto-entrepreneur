<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\HostNote;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;

/**
 * The transaction manager on the connection of an entity manager (the aggregates are persisted and flushed through the
 * entity manager itself, as the repositories of the bridge do): commit, rollback, and the detachment of the package
 * entities after a rollback.
 */
final class DoctrineTransactionManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    private DoctrineTransactionManager $transactions;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
        $this->transactions = new DoctrineTransactionManager($this->entityManager);
    }

    public function testItCommitsAndReturnsTheResultOfTheOperation(): void
    {
        $connection = $this->entityManager->getConnection();

        $result = $this->transactions->transactional(function () use ($connection): string {
            $this->save(ContractSamples::pendingAccount(1));

            return 'done:' . ($connection->isTransactionActive() ? 'in' : 'out');
        });

        static::assertSame('done:in', $result);
        static::assertFalse($connection->isTransactionActive());
        $this->entityManager->clear();
        static::assertInstanceOf(AeAccount::class, $this->entityManager->find(AeAccount::class, AccountId::fromString('account-1')));
    }

    public function testItRethrowsTheSameThrowable(): void
    {
        $failure = new LogicException('boom');

        $this->expectExceptionObject($failure);
        $this->transactions->transactional(static function () use ($failure): never {
            throw $failure;
        });
    }

    public function testItRollsBackAndDetachesThePackageEntitiesWhenTheOperationFails(): void
    {
        $account = ContractSamples::pendingAccount(1);

        try {
            $this->transactions->transactional(function () use ($account): never {
                $this->save($account);

                throw new LogicException('boom');
            });
        } catch (LogicException) {
            static::addToAssertionCount(1);
        }

        static::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        static::assertTrue($this->entityManager->isOpen());
        static::assertFalse($this->entityManager->contains($account));
        static::assertNull($this->entityManager->find(AeAccount::class, AccountId::fromString('account-1')));
    }

    public function testItKeepsTheEntitiesOfTheHostApplicationManagedWhenTheOperationFails(): void
    {
        $entityManager = TestDatabase::sharedEntityManager();
        $transactions = new DoctrineTransactionManager($entityManager);
        $note = new HostNote('flushed before');
        $entityManager->persist($note);
        $entityManager->flush();
        $account = ContractSamples::pendingAccount(1);
        $note->rewrite('changed, not flushed yet');

        try {
            $transactions->transactional(static function () use ($entityManager, $account): never {
                $entityManager->persist($account);
                $entityManager->flush();

                throw new LogicException('boom');
            });
        } catch (LogicException) {
            static::addToAssertionCount(1);
        }

        static::assertFalse($entityManager->contains($account));
        static::assertTrue($entityManager->contains($note));
        static::assertSame($note, $entityManager->find(HostNote::class, $note->id()));
        static::assertSame('changed, not flushed yet', $note->text());
    }

    public function testItDetachesTheFourAggregatesOfThePackage(): void
    {
        $account = ContractSamples::pendingAccount(1);
        $mandate = ContractSamples::pendingMandate(1, 1);
        $declaration = ContractSamples::pendingDeclaration(1, 1, ContractSamples::monthly());
        $payment = ContractSamples::paymentIn(PaymentState::Pending, 1, 1, 'INV-1', ContractSamples::monthly());

        try {
            $this->transactions->transactional(function () use ($account, $mandate, $declaration, $payment): never {
                $this->save($account);
                $this->save($mandate);
                $this->save($declaration);
                $this->save($payment);

                throw new LogicException('boom');
            });
        } catch (LogicException) {
            static::addToAssertionCount(1);
        }

        foreach ([$account, $mandate, $declaration, $payment] as $aggregate) {
            static::assertFalse($this->entityManager->contains($aggregate));
        }
        $managed = 0;
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap() as $entities) {
            $managed += \count($entities);
        }
        static::assertSame(0, $managed);
        static::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_accounts'));
        static::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_payment_records'));
    }

    public function testNestedOperationsJoinTheOutermostTransaction(): void
    {
        try {
            $this->transactions->transactional(function (): never {
                $this->transactions->transactional(function (): void {
                    $this->save(ContractSamples::pendingAccount(1));
                });

                throw new LogicException('outer failure');
            });
        } catch (LogicException) {
            static::addToAssertionCount(1);
        }

        static::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_accounts'));
    }

    public function testAnAggregateRolledBackIsSavedAgainAsANewOne(): void
    {
        $account = ContractSamples::pendingAccount(1);
        try {
            $this->transactions->transactional(function () use ($account): never {
                $this->save($account);

                throw new LogicException('boom');
            });
        } catch (LogicException) {
            static::addToAssertionCount(1);
        }

        $this->save($account);

        static::assertTrue($this->entityManager->contains($account));
        static::assertSame([1], $this->entityManager->getConnection()->fetchFirstColumn('SELECT version FROM urssaf_tdae_accounts'));
    }

    private function save(object $aggregate): void
    {
        $this->entityManager->persist($aggregate);
        $this->entityManager->flush();
    }
}
