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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\MandateRepositoryContract;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\BeforeNextFlush;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\FileDatabase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;
use Throwable;

/**
 * The shared contract and the Doctrine-specific scenarios of the mandate repository, on an in-memory SQLite database.
 * The other writer is a second entity manager over the same connection. One method per scenario.
 *
 * @mago-expect lint:too-many-methods
 */
final class DoctrineMandateRepositoryTest extends TestCase
{
    use MandateRepositoryContract {
        setUp as private contractSetUp;
    }

    private EntityManagerInterface $entityManager;

    private ?MandateRepository $other = null;

    private ?FileDatabase $file = null;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
        $this->contractSetUp();
    }

    protected function createRepository(): MandateRepository
    {
        return new DoctrineMandateRepository($this->entityManager);
    }

    protected function anotherWriter(): MandateRepository
    {
        return $this->other ??= new DoctrineMandateRepository(TestDatabase::anotherEntityManager($this->entityManager));
    }

    protected function tearDown(): void
    {
        $this->file?->delete();
    }

    /**
     * @phpstan-impure
     *
     * @return list<array<string, mixed>>
     */
    private static function keys(EntityManagerInterface $entityManager): array
    {
        return $entityManager->getConnection()->fetchAllAssociative(
            'SELECT tenant_id, siret, mandate_id FROM urssaf_tdae_active_mandates ORDER BY mandate_id',
        );
    }

    /**
     * @return array<string, string>
     */
    private static function key(int $siret, string $mandateId): array
    {
        return ['tenant_id' => 'tenant-1', 'siret' => ContractSamples::siret($siret)->toString(), 'mandate_id' => $mandateId];
    }

    public function testItReturnsTheManagedInstanceWhileItIsCurrent(): void
    {
        $mandate = ContractSamples::mandate(1, 1);
        $this->repository->save($mandate);
        $id = MandateId::fromString('mandate-1');

        static::assertSame($mandate, $this->repository->get($id));
        static::assertSame($mandate, $this->repository->get($id));

        $this->entityManager->clear();
        $reloaded = $this->repository->get($id);

        static::assertNotSame($mandate, $reloaded);
        static::assertSame([], $reloaded->releaseEvents());
        static::assertSame($reloaded, $this->repository->get($id));
    }

    public function testEveryStateButCancelledIsActive(): void
    {
        // The queries of the repository select the active mandates as "state <> cancelled".
        foreach (MandateState::cases() as $state) {
            static::assertSame($state !== MandateState::Cancelled, $state->isActive());
        }
    }

    public function testDeletingAMandateForgetsItsManagedCopy(): void
    {
        $mandate = ContractSamples::mandate(1, 1);
        $this->repository->save($mandate);

        $this->repository->delete($mandate->id());

        static::assertFalse($this->entityManager->contains($mandate));
    }

    public function testTheKeyRowFollowsEverySave(): void
    {
        $mandate = ContractSamples::mandate(1, 1);

        static::assertSame([], self::keys($this->entityManager));

        $this->repository->save($mandate);
        $notified = self::keys($this->entityManager);
        $this->repository->save($mandate);
        $again = self::keys($this->entityManager);
        $mandate->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($mandate);
        $cancelled = self::keys($this->entityManager);
        $this->repository->save(ContractSamples::mandate(2, 1));
        $renewed = self::keys($this->entityManager);

        static::assertSame([self::key(1, 'mandate-1')], $notified);
        static::assertSame($notified, $again);
        static::assertSame([], $cancelled);
        static::assertSame([self::key(1, 'mandate-2')], $renewed);
    }

    public function testAnImportedMandateHasAKeyRowToo(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1, ContractSamples::TENANT, MandateOrigin::Imported));

        static::assertSame([self::key(1, 'mandate-1')], self::keys($this->entityManager));
    }

    public function testDeletingAMandateRemovesItsKeyRowAndKeepsTheOthers(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $this->repository->save(ContractSamples::mandate(2, 2));

        $this->repository->delete(MandateId::fromString('mandate-1'));

        static::assertSame([self::key(2, 'mandate-2')], self::keys($this->entityManager));
    }

    public function testADeleteInsideATransactionThatThenFailsKeepsTheMandateAndItsKeyRow(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $keys = self::keys($this->entityManager);

        try {
            (new DoctrineTransactionManager($this->entityManager))->transactional(function (): never {
                $this->repository->delete(MandateId::fromString('mandate-1'));

                throw new LogicException('boom');
            });
        } catch (LogicException) {
            static::addToAssertionCount(1);
        }

        static::assertSame($keys, self::keys($this->entityManager));
        static::assertSame(['mandate-1'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_mandates'));
    }

    public function testAnInstanceThatIsNotManagedWhileItsIdentifierIsStoredIsRefusedAndNothingIsOverwritten(): void
    {
        $mandate = ContractSamples::mandate(1, 1);
        $this->repository->save($mandate);
        $this->entityManager->clear();
        $mandate->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));

        foreach ([$mandate, ContractSamples::mandate(1, 1)] as $unmanaged) {
            try {
                $this->repository->save($unmanaged);
                static::fail('Expected a violation.');
            } catch (InvariantViolation $exception) {
                static::assertSame('mandate.concurrent_modification', $exception->violationCode());
                static::assertSame('The mandate was changed by another writer since it was read.', $exception->getMessage());
            }
        }

        static::assertTrue($this->entityManager->isOpen());
        static::assertSame(MandateState::Notified, $this->repository->get(MandateId::fromString('mandate-1'))->state());
        static::assertCount(1, self::keys($this->entityManager));
    }

    public function testAStaleCopyIsRefusedBeforeAnyFlushAndForgotten(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $id = MandateId::fromString('mandate-1');
        $stale = $this->repository->get($id);
        $other = $this->anotherWriter()->get($id);
        $other->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->save($other);
        $stale->cancel(ContractSamples::at('2026-11-17T09:00:00Z'));
        $flushed = false;
        BeforeNextFlush::run($this->entityManager, static function () use (&$flushed): void {
            $flushed = true;
        });

        try {
            $this->repository->save($stale);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.concurrent_modification', $exception->violationCode());
            static::assertSame([], $exception->params());
        }

        static::assertFalse($flushed);
        static::assertTrue($this->entityManager->isOpen());
        static::assertFalse($this->entityManager->contains($stale));
        static::assertSame('2026-11-16T09:00:00Z', $this->repository->get($id)->cancelledAt()?->toIso8601());
    }

    public function testAReadReplacesAManagedCopyThatAnotherWriterChanged(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $id = MandateId::fromString('mandate-1');
        $managed = $this->repository->get($id);
        $other = $this->anotherWriter()->get($id);
        $other->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->save($other);

        $fresh = $this->repository->get($id);

        static::assertNotSame($managed, $fresh);
        static::assertSame(MandateState::Cancelled, $fresh->state());
        static::assertSame($fresh, $this->repository->get($id));
    }

    public function testTheMandatesOfASiretAreListedByNotificationThenIdentifierWhateverTheInsertionOrder(): void
    {
        foreach ([3, 1, 2] as $number) {
            $this->repository->save(ContractSamples::cancelledMandate($number, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-10T09:00:00Z'));
        }
        $this->repository->save(ContractSamples::cancelledMandate(9, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-01T09:00:00Z'));
        $this->entityManager->clear();

        static::assertSame(
            ['mandate-9', 'mandate-1', 'mandate-2', 'mandate-3'],
            self::mandateIds($this->repository->findBySiret(ContractSamples::tenant(), ContractSamples::siret(1))),
        );
    }

    public function testTheActiveMandateIsTheEarliestNotifiedThenTheLowestIdentifierWhenARepairedDatabaseHoldsSeveral(): void
    {
        $this->repository->save(ContractSamples::cancelledMandate(9, 1));
        $this->repository->save(ContractSamples::cancelledMandate(1, 1));
        $this->repository->save(ContractSamples::cancelledMandate(5, 1, ContractSamples::TENANT, MandateOrigin::Notified, '2026-11-20T09:00:00Z'));
        $this->entityManager->getConnection()->executeStatement("UPDATE urssaf_tdae_mandates SET state = 'notified'");
        $this->entityManager->clear();

        static::assertSame('mandate-1', $this->repository->findActive(ContractSamples::tenant(), ContractSamples::siret(1))?->id()->value());
    }

    public function testACancelledMandateIsSavedAgainWhileAnotherOneIsActive(): void
    {
        $cancelled = ContractSamples::mandate(1, 1);
        $this->repository->save($cancelled);
        $cancelled->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($cancelled);
        $this->repository->save(ContractSamples::mandate(2, 1));

        $this->repository->save($cancelled);

        static::assertSame(['mandate-2'], array_column(self::keys($this->entityManager), 'mandate_id'));
    }

    public function testARefusedSecondActiveMandateIsRefusedBeforeAnyFlush(): void
    {
        $this->repository->save(ContractSamples::mandate(1, 1));
        $flushed = false;
        BeforeNextFlush::run($this->entityManager, static function () use (&$flushed): void {
            $flushed = true;
        });

        try {
            $this->repository->save(ContractSamples::mandate(2, 1));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.duplicate_active', $exception->violationCode());
            static::assertSame('The SIRET already has an active mandate.', $exception->getMessage());
            static::assertSame([], $exception->params());
        }

        static::assertFalse($flushed);
    }

    public function testAConcurrentSecondActiveMandateIsReportedAsAViolationAndForgotten(): void
    {
        $this->file = new FileDatabase();
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineMandateRepository($entityManager);
        $rival = new DoctrineMandateRepository($this->file->entityManager());
        BeforeNextFlush::run($entityManager, static function () use ($rival): void {
            $rival->save(ContractSamples::mandate(2, 1));
        });
        $mandate = ContractSamples::mandate(1, 1);

        try {
            $repository->save($mandate);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.duplicate_active', $exception->violationCode());
        }

        static::assertTrue($entityManager->isOpen());
        static::assertFalse($entityManager->contains($mandate));
        $stored = $this->file->entityManager();
        static::assertSame(['mandate-2'], array_column(self::keys($stored), 'mandate_id'));
        static::assertSame(1, $stored->getConnection()->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_mandates'));
    }

    public function testTheSameIdentifierCreatedConcurrentlyIsReportedAsAConcurrentModification(): void
    {
        $this->file = new FileDatabase();
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineMandateRepository($entityManager);
        $rival = new DoctrineMandateRepository($this->file->entityManager());
        BeforeNextFlush::run($entityManager, static function () use ($rival): void {
            $rival->save(ContractSamples::mandate(1, 2));
        });

        try {
            $repository->save(ContractSamples::mandate(1, 1));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.concurrent_modification', $exception->violationCode());
        }

        static::assertSame(
            [ContractSamples::siret(2)->toString()],
            $this->file->entityManager()->getConnection()->fetchFirstColumn('SELECT siret FROM urssaf_tdae_mandates'),
        );
    }

    public function testAKeyRowThatNoMandateExplainsIsRethrownUnchanged(): void
    {
        $this->entityManager->getConnection()->insert('urssaf_tdae_active_mandates', ['tenant_id' => 'tenant-1', 'siret' => ContractSamples::siret(1)->toString(), 'mandate_id' => 'ghost']);
        $mandate = ContractSamples::mandate(1, 1);

        $raised = false;
        try {
            $this->repository->save($mandate);
        } catch (Throwable $failure) {
            $raised = true;
            static::assertInstanceOf(UniqueConstraintViolationException::class, $failure);
        }

        static::assertTrue($raised);
        static::assertTrue($this->entityManager->isOpen());
        static::assertFalse($this->entityManager->contains($mandate));
        static::assertSame([], $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_mandates'));
    }

    public function testACopyChangedBetweenTheCheckAndTheFlushIsRefusedByTheVersionColumn(): void
    {
        $this->file = new FileDatabase();
        $rivalRepository = new DoctrineMandateRepository($this->file->entityManager());
        $rivalRepository->save(ContractSamples::mandate(1, 1));
        $id = MandateId::fromString('mandate-1');
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineMandateRepository($entityManager);
        $managed = $repository->get($id);
        $managed->cancel(ContractSamples::at('2026-11-17T09:00:00Z'));
        BeforeNextFlush::run($entityManager, static function () use ($rivalRepository, $id): void {
            $other = $rivalRepository->get($id);
            $other->cancel(ContractSamples::at('2026-11-16T09:00:00Z'));
            $rivalRepository->save($other);
        });

        try {
            $repository->save($managed);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.concurrent_modification', $exception->violationCode());
        }

        static::assertSame('2026-11-16T09:00:00Z', (new DoctrineMandateRepository($this->file->entityManager()))->get($id)->cancelledAt()?->toIso8601());
    }
}
