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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\DeclarationRecordRepositoryContract;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\BeforeNextFlush;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\BeforeNextStatement;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\FileDatabase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;
use Throwable;

/**
 * The shared contract and the Doctrine-specific scenarios of the declaration record repository, on an in-memory SQLite
 * database. The other writer is a second entity manager over the same connection. One method per scenario.
 *
 * @mago-expect lint:too-many-methods
 */
final class DoctrineDeclarationRecordRepositoryTest extends TestCase
{
    use DeclarationRecordRepositoryContract {
        setUp as private contractSetUp;
    }

    private EntityManagerInterface $entityManager;

    private ?DeclarationRecordRepository $other = null;

    private ?FileDatabase $file = null;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
        $this->contractSetUp();
    }

    protected function createRepository(): DeclarationRecordRepository
    {
        return new DoctrineDeclarationRecordRepository($this->entityManager);
    }

    protected function transactions(): TransactionManager
    {
        return new DoctrineTransactionManager($this->entityManager);
    }

    protected function anotherWriter(): DeclarationRecordRepository
    {
        return $this->other ??= new DoctrineDeclarationRecordRepository(TestDatabase::anotherEntityManager($this->entityManager));
    }

    protected function tearDown(): void
    {
        $this->file?->delete();
    }

    /**
     * @phpstan-impure
     *
     * @return list<mixed>
     */
    private function versions(): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT version FROM urssaf_tdae_declaration_records');
    }

    /**
     * @phpstan-impure
     *
     * @return list<array<string, mixed>>
     */
    private static function keys(EntityManagerInterface $entityManager): array
    {
        return $entityManager->getConnection()->fetchAllAssociative(
            'SELECT tenant_id, siret, periodicity, period_year, period_number, declaration_record_id FROM urssaf_tdae_current_declarations ORDER BY declaration_record_id',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function key(int $siret, string $recordId, int $month = 10): array
    {
        return [
            'tenant_id' => 'tenant-1',
            'siret' => ContractSamples::siret($siret)->toString(),
            'periodicity' => 'M',
            'period_year' => 2026,
            'period_number' => $month,
            'declaration_record_id' => $recordId,
        ];
    }

    public function testItReturnsTheManagedInstanceWhileItIsCurrent(): void
    {
        $record = ContractSamples::declaration(1, 1, ContractSamples::monthly());
        $this->repository->save($record);
        $id = DeclarationRecordId::fromString('declaration-1');

        static::assertSame($record, $this->repository->get($id));

        $this->entityManager->clear();
        $reloaded = $this->repository->get($id);

        static::assertNotSame($record, $reloaded);
        static::assertSame([], $reloaded->releaseEvents());
        static::assertSame($reloaded, $this->repository->get($id));
    }

    public function testAnInstanceThatIsNotManagedWhileItsIdentifierIsStoredIsRefusedAndNothingIsOverwritten(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $this->entityManager->clear();

        try {
            $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly()));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.concurrent_modification', $exception->violationCode());
            static::assertSame('The declaration record was changed by another writer since it was read.', $exception->getMessage());
        }

        static::assertTrue($this->entityManager->isOpen());
        static::assertSame(DeclarationState::Pending, $this->repository->get(DeclarationRecordId::fromString('declaration-1'))->state());
    }

    public function testAStaleCopyIsRefusedBeforeAnyFlushAndForgotten(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $stale = $this->repository->get($id);
        static::assertTrue($this->anotherWriter()->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));
        $stale->startSubmission(ContractSamples::at('2026-11-16T10:00:01Z'));
        $flushed = false;
        BeforeNextFlush::run($this->entityManager, static function () use (&$flushed): void {
            $flushed = true;
        });

        try {
            $this->repository->save($stale);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.concurrent_modification', $exception->violationCode());
            static::assertSame([], $exception->params());
        }

        static::assertFalse($flushed);
        static::assertTrue($this->entityManager->isOpen());
        static::assertFalse($this->entityManager->contains($stale));
    }

    public function testAReadReplacesAManagedCopyThatAnotherWriterClaimed(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $managed = $this->repository->get($id);
        static::assertTrue($this->anotherWriter()->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));

        $fresh = $this->repository->get($id);

        static::assertNotSame($managed, $fresh);
        static::assertSame(DeclarationState::Submitting, $fresh->state());
    }

    public function testTheClaimBumpsTheVersionAndForgetsTheManagedCopy(): void
    {
        $record = ContractSamples::declaration(1, 1, ContractSamples::monthly());
        $this->repository->save($record);
        $id = DeclarationRecordId::fromString('declaration-1');
        static::assertSame([1], $this->versions());

        static::assertTrue($this->repository->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));
        static::assertFalse($this->repository->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));

        static::assertSame([2], $this->versions());
        static::assertFalse($this->entityManager->contains($record));
        static::assertNotSame($record, $this->repository->get($id));
    }

    public function testAFailedClaimLeavesTheManagedCopyAlone(): void
    {
        $record = ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly());
        $this->repository->save($record);

        static::assertFalse($this->repository->claimForSubmission($record->id(), ContractSamples::at('2026-11-16T10:00:00Z')));

        static::assertTrue($this->entityManager->contains($record));
        static::assertSame([1], $this->versions());
    }

    public function testTheClaimClearsTheReasonOfAnUnknownOutcomeAndKeepsTheKeyRow(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $this->entityManager->getConnection()->executeStatement("UPDATE urssaf_tdae_declaration_records SET outcome_unknown_reason = 'may_have_reached_urssaf'");
        $this->entityManager->clear();
        $id = DeclarationRecordId::fromString('declaration-1');
        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $this->repository->get($id)->outcomeUnknownReason());
        $this->entityManager->clear();

        static::assertTrue($this->repository->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));

        static::assertNull($this->repository->get($id)->outcomeUnknownReason());
        static::assertSame([self::key(1, 'declaration-1')], self::keys($this->entityManager));
    }

    public function testTheKeyRowFollowsEverySave(): void
    {
        $record = ContractSamples::declaration(1, 1, ContractSamples::monthly());

        static::assertSame([], self::keys($this->entityManager));

        $this->repository->save($record);
        $pending = self::keys($this->entityManager);
        $this->repository->save($record);
        $again = self::keys($this->entityManager);
        $this->repository->save(ContractSamples::declaration(2, 1, ContractSamples::monthly(11)));
        $second = self::keys($this->entityManager);

        static::assertSame([self::key(1, 'declaration-1')], $pending);
        static::assertSame($pending, $again);
        static::assertSame([self::key(1, 'declaration-1'), self::key(1, 'declaration-2', 11)], $second);
    }

    public function testAQuarterlyRecordKeepsItsQuarterInTheKeyRow(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::quarterly(3)));

        static::assertSame(
            [['tenant_id' => 'tenant-1', 'siret' => ContractSamples::siret(1)->toString(), 'periodicity' => 'T', 'period_year' => 2026, 'period_number' => 3, 'declaration_record_id' => 'declaration-1']],
            self::keys($this->entityManager),
        );
    }

    public function testASupersessionMovesTheKeyRowToTheNewRecord(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));
        $old = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $new = $old->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));

        $this->transactions()->transactional(function () use ($old, $new): void {
            $this->repository->save($old);
            $this->repository->save($new);
        });

        static::assertSame([self::key(1, 'declaration-2')], self::keys($this->entityManager));
    }

    public function testASupersessionThatIsRolledBackKeepsTheKeyRowOfTheOldRecord(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));
        $old = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $new = $old->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));

        try {
            $this->transactions()->transactional(function () use ($old, $new): never {
                $this->repository->save($old);
                $this->repository->save($new);

                throw new LogicException('boom');
            });
        } catch (LogicException) {
            static::addToAssertionCount(1);
        }

        static::assertSame([self::key(1, 'declaration-1')], self::keys($this->entityManager));
        static::assertSame(['declaration-1'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_declaration_records'));
    }

    public function testTheCurrentRecordIsTheEarliestCreatedThenTheLowestIdentifierWhenARepairedDatabaseHoldsSeveral(): void
    {
        $this->repository->save(ContractSamples::declaration(9, 1, ContractSamples::monthly(9)));
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly(8)));
        $this->repository->save(ContractSamples::declaration(5, 1, ContractSamples::monthly(7), ContractSamples::TENANT, '2026-11-20T09:00:00Z'));
        $this->entityManager->getConnection()->executeStatement('UPDATE urssaf_tdae_declaration_records SET period_number = 10');
        $this->entityManager->clear();

        static::assertSame(
            'declaration-1',
            $this->repository->findCurrent(ContractSamples::tenant(), ContractSamples::siret(1), ContractSamples::monthly())?->id()->value(),
        );
    }

    public function testTheRecordsOfAStateAreListedByUpdateThenIdentifierWhateverTheInsertionOrder(): void
    {
        foreach ([5, 3, 4] as $number) {
            $this->repository->save(ContractSamples::declaration($number, $number, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        }
        $this->repository->save(ContractSamples::declaration(9, 9, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-09T09:00:00Z'));
        $this->entityManager->clear();

        static::assertSame(
            ['declaration-9', 'declaration-3', 'declaration-4', 'declaration-5'],
            self::recordIds($this->repository->findByState(ContractSamples::tenant(), DeclarationState::Pending, 10)),
        );
    }

    public function testTheStaleSubmissionsAreListedByAttemptThenIdentifierWhateverTheInsertionOrder(): void
    {
        foreach ([3, 1, 2] as $number) {
            $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, $number, $number, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        }
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 9, 9, ContractSamples::monthly(), ContractSamples::OTHER_TENANT, '2026-11-09T09:00:00Z'));
        $this->entityManager->clear();

        static::assertSame(
            ['declaration-9', 'declaration-1', 'declaration-2', 'declaration-3'],
            self::recordIds($this->repository->findStaleSubmissions(ContractSamples::at('2026-12-01T00:00:00Z'), 10)),
        );
    }

    public function testTheRecordsDeletedBySiretAreForgottenByTheEntityManagerAndTheOthersKept(): void
    {
        $deleted = ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly());
        $kept = ContractSamples::declarationIn(DeclarationState::Error, 2, 1, ContractSamples::monthly(11));
        $this->repository->save($deleted);
        $this->repository->save($kept);

        $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Declared]);

        static::assertFalse($this->entityManager->contains($deleted));
        static::assertTrue($this->entityManager->contains($kept));
        static::assertSame([self::key(1, 'declaration-2', 11)], self::keys($this->entityManager));
    }

    public function testTheRecordsDeletedBySiretAreReturnedByCreationThenIdentifierWhateverTheInsertionOrder(): void
    {
        foreach ([3, 1, 2] as $number) {
            $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, $number, 1, ContractSamples::monthly($number), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        }
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 9, 1, ContractSamples::monthly(9), ContractSamples::TENANT, '2026-11-01T09:00:00Z'));

        $ids = $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Declared]);

        static::assertSame(['declaration-9', 'declaration-1', 'declaration-2', 'declaration-3'], self::idValues($ids));
        static::assertSame([], $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_declaration_records'));
        static::assertSame([], self::keys($this->entityManager));
    }

    public function testDeletingSeveralStatesAtOnceErasesAllOfThem(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 2, 1, ContractSamples::monthly(11)));
        $this->repository->save(ContractSamples::declaration(3, 1, ContractSamples::monthly(12)));

        $ids = $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Declared, DeclarationState::Error]);

        static::assertSame(['declaration-1', 'declaration-2'], self::idValues($ids));
        static::assertSame([self::key(1, 'declaration-3', 12)], self::keys($this->entityManager));
    }

    public function testARecordClaimedBetweenTheSelectionAndTheErasureSurvivesWithItsKeyRow(): void
    {
        $statement = new BeforeNextStatement('DELETE FROM urssaf_tdae_declaration_records');
        $entityManager = TestDatabase::entityManager([$statement->middleware()]);
        $claimer = new DoctrineDeclarationRecordRepository(TestDatabase::anotherEntityManager($entityManager));
        $statement->run(static function () use ($claimer): void {
            $claimer->claimForSubmission(DeclarationRecordId::fromString('declaration-1'), ContractSamples::at('2026-11-16T10:00:00Z'));
        });
        $repository = new DoctrineDeclarationRecordRepository($entityManager);
        $repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly(10)));
        $repository->save(ContractSamples::declaration(2, 1, ContractSamples::monthly(11)));

        $repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Pending]);

        static::assertSame(['declaration-1'], $entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_declaration_records'));
        static::assertSame([self::key(1, 'declaration-1', 10)], self::keys($entityManager));
        static::assertSame(1, $repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1))['submitting']);
    }

    public function testAnErasureThatSelectsNothingRunsNoDeleteStatement(): void
    {
        $statement = new BeforeNextStatement('DELETE FROM urssaf_tdae_declaration_records');
        $entityManager = TestDatabase::entityManager([$statement->middleware()]);
        $deleted = false;
        $statement->run(static function () use (&$deleted): void {
            $deleted = true;
        });
        $repository = new DoctrineDeclarationRecordRepository($entityManager);

        static::assertSame([], $repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Pending]));
        static::assertFalse($deleted);
    }

    public function testAConcurrentCurrentRecordOfTheSamePeriodIsReportedAsAViolationAndForgotten(): void
    {
        $this->file = new FileDatabase();
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineDeclarationRecordRepository($entityManager);
        $rival = new DoctrineDeclarationRecordRepository($this->file->entityManager());
        BeforeNextFlush::run($entityManager, static function () use ($rival): void {
            $rival->save(ContractSamples::declaration(2, 1, ContractSamples::monthly()));
        });
        $record = ContractSamples::declaration(1, 1, ContractSamples::monthly());

        try {
            $repository->save($record);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.duplicate_current', $exception->violationCode());
            static::assertSame([], $exception->params());
        }

        static::assertTrue($entityManager->isOpen());
        static::assertFalse($entityManager->contains($record));
        $stored = $this->file->entityManager();
        static::assertSame(['declaration-2'], $stored->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_tdae_declaration_records'));
        static::assertSame([self::key(1, 'declaration-2')], self::keys($stored));
    }

    public function testTheSameIdentifierCreatedConcurrentlyIsReportedAsAConcurrentModification(): void
    {
        $this->file = new FileDatabase();
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineDeclarationRecordRepository($entityManager);
        $rival = new DoctrineDeclarationRecordRepository($this->file->entityManager());
        BeforeNextFlush::run($entityManager, static function () use ($rival): void {
            $rival->save(ContractSamples::declaration(1, 2, ContractSamples::monthly()));
        });

        try {
            $repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.concurrent_modification', $exception->violationCode());
        }

        static::assertSame(
            [ContractSamples::siret(2)->toString()],
            $this->file->entityManager()->getConnection()->fetchFirstColumn('SELECT siret FROM urssaf_tdae_declaration_records'),
        );
    }

    public function testAKeyRowThatNoRecordExplainsIsRethrownUnchanged(): void
    {
        $this->entityManager->getConnection()->insert('urssaf_tdae_current_declarations', self::key(1, 'ghost'));
        $record = ContractSamples::declaration(1, 1, ContractSamples::monthly());

        $raised = false;
        try {
            $this->repository->save($record);
        } catch (Throwable $failure) {
            $raised = true;
            static::assertInstanceOf(UniqueConstraintViolationException::class, $failure);
        }

        static::assertTrue($raised);
        static::assertTrue($this->entityManager->isOpen());
        static::assertFalse($this->entityManager->contains($record));
        static::assertSame([], $this->versions());
    }

    public function testACopyChangedBetweenTheCheckAndTheFlushIsRefusedByTheVersionColumn(): void
    {
        $this->file = new FileDatabase();
        $rivalRepository = new DoctrineDeclarationRecordRepository($this->file->entityManager());
        $rivalRepository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $entityManager = $this->file->entityManager();
        $repository = new DoctrineDeclarationRecordRepository($entityManager);
        $managed = $repository->get($id);
        $managed->startSubmission(ContractSamples::at('2026-11-16T10:00:00Z'));
        BeforeNextFlush::run($entityManager, static function () use ($rivalRepository, $id): void {
            $rivalRepository->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:01Z'));
        });

        try {
            $repository->save($managed);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.concurrent_modification', $exception->violationCode());
        }

        static::assertSame(
            '2026-11-16T10:00:01Z',
            (new DoctrineDeclarationRecordRepository($this->file->entityManager()))->get($id)->lastAttemptAt()?->toIso8601(),
        );
    }
}
