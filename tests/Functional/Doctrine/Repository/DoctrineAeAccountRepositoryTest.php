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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\AeAccountRepositoryContract;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\BeforeNextFlush;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\FileDatabase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;

/**
 * The shared contract and the Doctrine-specific scenarios of the account repository, on an in-memory SQLite database.
 * The other writer is a second entity manager over the same connection. One method per scenario.
 *
 * @mago-expect lint:too-many-methods
 */
final class DoctrineAeAccountRepositoryTest extends TestCase
{
    use AeAccountRepositoryContract {
        setUp as private contractSetUp;
    }

    private EntityManagerInterface $entityManager;

    private ?AeAccountRepository $other = null;

    private ?FileDatabase $file = null;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
        $this->contractSetUp();
    }

    protected function createRepository(): AeAccountRepository
    {
        return new DoctrineAeAccountRepository($this->entityManager);
    }

    protected function anotherWriter(): AeAccountRepository
    {
        return $this->other ??= new DoctrineAeAccountRepository(TestDatabase::anotherEntityManager($this->entityManager));
    }

    protected function tearDown(): void
    {
        $this->file?->delete();
    }

    /**
     * @param list<AeAccount> $accounts
     *
     * @return list<string>
     */
    private static function ids(array $accounts): array
    {
        return array_map(static fn(AeAccount $account): string => $account->id()->value(), $accounts);
    }

    private static function sameSiret(int $number, int $siretOf): AeAccount
    {
        return AeAccount::track(
            AccountId::fromString('account-' . $number),
            ContractSamples::tenant(),
            ContractSamples::siret($siretOf),
            ContractSamples::status(),
            null,
            ContractSamples::at(),
        );
    }

    public function testItReturnsTheManagedInstanceWhileItIsCurrent(): void
    {
        $account = ContractSamples::account(1);
        $this->repository->save($account);
        $id = AccountId::fromString('account-1');

        static::assertSame($account, $this->repository->get($id));
        static::assertSame($account, $this->repository->get($id));

        $this->entityManager->clear();
        $reloaded = $this->repository->get($id);

        static::assertNotSame($account, $reloaded);
        static::assertSame([], $reloaded->releaseEvents());
        static::assertSame($reloaded, $this->repository->get($id));
    }

    public function testDeletingAnAccountForgetsItsManagedCopy(): void
    {
        $account = ContractSamples::account(1);
        $this->repository->save($account);

        $this->repository->delete($account->id());

        static::assertFalse($this->entityManager->contains($account));
        static::assertSame([], $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_accounts'));
    }

    public function testAnInstanceThatIsNotManagedWhileItsIdentifierIsStoredIsRefusedAndNothingIsOverwritten(): void
    {
        $this->repository->save(ContractSamples::account(1, ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->entityManager->clear();

        try {
            $this->repository->save(ContractSamples::account(1, ContractSamples::TENANT, '2026-11-12T09:00:00Z'));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.concurrent_modification', $exception->violationCode());
            static::assertSame('The account was changed by another writer since it was read.', $exception->getMessage());
        }

        static::assertTrue($this->entityManager->isOpen());
        static::assertSame(
            '2026-11-10T09:00:00Z',
            $this->repository->get(AccountId::fromString('account-1'))->lastSyncedAt()->toIso8601(),
        );
    }

    public function testAStaleCopyIsRefusedBeforeAnyFlushAndForgotten(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $id = AccountId::fromString('account-1');
        $stale = $this->repository->get($id);
        $other = $this->anotherWriter()->get($id);
        $other->sync(ContractSamples::status(true), ContractSamples::at('2026-11-12T09:00:00Z'));
        $this->anotherWriter()->save($other);
        $stale->sync(ContractSamples::status(), ContractSamples::at('2026-11-13T09:00:00Z'));
        $flushed = false;
        BeforeNextFlush::run($this->entityManager, static function () use (&$flushed): void {
            $flushed = true;
        });

        try {
            $this->repository->save($stale);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.concurrent_modification', $exception->violationCode());
            static::assertSame([], $exception->params());
        }

        static::assertFalse($flushed);
        static::assertTrue($this->entityManager->isOpen());
        static::assertFalse($this->entityManager->contains($stale));
        static::assertTrue($this->repository->get($id)->status()->mandatePresent());
    }

    public function testAReadReplacesAManagedCopyThatAnotherWriterChanged(): void
    {
        $this->repository->save(ContractSamples::account(1));
        $id = AccountId::fromString('account-1');
        $managed = $this->repository->get($id);
        $other = $this->anotherWriter()->get($id);
        $other->sync(ContractSamples::status(true), ContractSamples::at('2026-11-12T09:00:00Z'));
        $this->anotherWriter()->save($other);

        $fresh = $this->repository->get($id);

        static::assertNotSame($managed, $fresh);
        static::assertTrue($fresh->status()->mandatePresent());
        static::assertSame($fresh, $this->repository->get($id));
    }

    public function testTheLeastRecentlySyncedAreListedBySynchronisationThenIdentifierWhateverTheInsertionOrder(): void
    {
        foreach ([3, 1, 2] as $number) {
            $this->repository->save(ContractSamples::account($number, ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        }
        $this->repository->save(ContractSamples::account(9, ContractSamples::TENANT, '2026-11-01T09:00:00Z'));
        $this->entityManager->clear();

        static::assertSame(
            ['account-9', 'account-1', 'account-2', 'account-3'],
            self::ids($this->repository->findLeastRecentlySynced(ContractSamples::tenant(), 10)),
        );
        static::assertSame(
            ['account-9', 'account-1'],
            self::ids($this->repository->findLeastRecentlySynced(ContractSamples::tenant(), 2)),
        );
    }

    public function testTheAccountOfASiretIsTheLowestIdentifierWhenARepairedDatabaseHoldsSeveral(): void
    {
        $this->repository->save(ContractSamples::account(2));
        $this->repository->save(self::sameSiret(1, 3));
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP INDEX uniq_urssaf_tdae_account_siret');
        $connection->executeStatement("UPDATE urssaf_tdae_accounts SET siret = (SELECT siret FROM urssaf_tdae_accounts WHERE id = 'account-2')");
        $this->entityManager->clear();

        static::assertSame(
            'account-1',
            $this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(2))?->id()->value(),
        );
    }

    public function testAConcurrentAccountOfTheSameSiretIsReportedAsAViolationAndForgotten(): void
    {
        $this->file = new FileDatabase();
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineAeAccountRepository($entityManager);
        $rival = new DoctrineAeAccountRepository($this->file->entityManager());
        BeforeNextFlush::run($entityManager, static function () use ($rival): void {
            $rival->save(self::sameSiret(2, 1));
        });
        $account = ContractSamples::account(1);

        try {
            $repository->save($account);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.duplicate_siret', $exception->violationCode());
        }

        // The unique constraint of the account table fails the flush itself: the ORM closes the entity manager.
        static::assertFalse($entityManager->contains($account));
        static::assertSame(
            ['account-2'],
            $this->file->entityManager()->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_accounts'),
        );
    }

    public function testTheSameIdentifierCreatedConcurrentlyIsReportedAsAConcurrentModification(): void
    {
        $this->file = new FileDatabase();
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineAeAccountRepository($entityManager);
        $rival = new DoctrineAeAccountRepository($this->file->entityManager());
        BeforeNextFlush::run($entityManager, static function () use ($rival): void {
            $rival->save(self::sameSiret(1, 2));
        });

        try {
            $repository->save(ContractSamples::account(1));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.concurrent_modification', $exception->violationCode());
        }

        static::assertSame(
            [ContractSamples::siret(2)->toString()],
            $this->file->entityManager()->getConnection()->fetchFirstColumn('SELECT siret FROM urssaf_tdae_accounts'),
        );
    }

    public function testACopyChangedBetweenTheCheckAndTheFlushIsRefusedByTheVersionColumn(): void
    {
        $this->file = new FileDatabase();
        $rivalRepository = new DoctrineAeAccountRepository($this->file->entityManager());
        $rivalRepository->save(ContractSamples::account(1));
        $id = AccountId::fromString('account-1');
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineAeAccountRepository($entityManager);
        $managed = $repository->get($id);
        $managed->sync(ContractSamples::status(), ContractSamples::at('2026-11-13T09:00:00Z'));
        BeforeNextFlush::run($entityManager, static function () use ($rivalRepository, $id): void {
            $other = $rivalRepository->get($id);
            $other->sync(ContractSamples::status(true), ContractSamples::at('2026-11-12T09:00:00Z'));
            $rivalRepository->save($other);
        });

        try {
            $repository->save($managed);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.concurrent_modification', $exception->violationCode());
        }

        static::assertTrue((new DoctrineAeAccountRepository($this->file->entityManager()))->get($id)->status()->mandatePresent());
    }
}
