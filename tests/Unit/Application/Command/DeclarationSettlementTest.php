<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarations;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarationsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolveDeclarationOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolveDeclarationOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\DeclarationRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclareAnswers;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingFailureReporter;
use Throwable;

/**
 * The settlement table of ADR 0009: the answer of 050 is settled against the record as it is stored when the answer
 * arrives, the claim being identified by its attempt number. One data set per cell of the table (row = the state the
 * record has when the answer arrives, column = the answer), each with a rolling-back transaction manager and with
 * ImmediateTransactionManager, ending with a follow-up DeclareTurnover of the same declaration and the exact list of the
 * gateway calls.
 *
 * Columns: A accepted (200), B 400, C unknown or unexpected, D retryable that may have reached URSSAF (resendable on 050),
 * E proven not sent (429, token endpoint, local rate limit; 401 and 403 give invalid credentials; the credentials not
 * found are a local refusal). Column D of method 060 is covered by PaymentSettlementTest.
 *
 * The "other writer" is a process that changes the stored record while the call is in flight (a reconciliation, an
 * operator, a concurrent requeue, supersession or erasure).
 *
 * Table-driven: one data set per cell, hence the method count and the complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class DeclarationSettlementTest extends TestCase
{
    // Column A: accepted (200 of 050)

    /**
     * Rows of the table whose accepted answer ends declared, by the state the other writer left: the claim's own state
     * (submitting, same attempt) comes first. Declared keeps the first result.
     *
     * @return iterable<string, array{string, bool, string, int, bool}>
     */
    public static function acceptedCells(): iterable
    {
        $cells = [
            'submitting, same attempt (own claim)' => ['own', '12345678', 1, false],
            'outcome_unknown (reconciled meanwhile)' => ['outcome_unknown', '12345678', 1, false],
            'pending (resolved as not received)' => ['pending', '12345678', 1, false],
            'error (requeued then failed)' => ['error', '12345678', 1, false],
            'invalid_credentials' => ['invalid_credentials', '12345678', 1, false],
            'submitting, later attempt (claimed again)' => ['submitting_later', '12345678', 2, false],
            'rejected (late acceptance)' => ['rejected', '12345678', 1, false],
            'superseded (late acceptance, flagged)' => ['superseded', '12345678', 1, true],
            'declared (first result kept)' => ['declared', '87654321', 1, false],
        ];
        foreach ($cells as $name => [$row, $certificat, $attempts, $flagged]) {
            yield $name . ', rolling back' => [$row, true, $certificat, $attempts, $flagged];
            yield $name . ', immediate' => [$row, false, $certificat, $attempts, $flagged];
        }
    }

    #[DataProvider('acceptedCells')]
    public function testAnAcceptedAnswerIsRecordedAsDeclaredOnEverySurvivingState(
        string $row,
        bool $transactional,
        string $certificat,
        int $attempts,
        bool $flagged,
    ): void {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('12345678'), DeclarationWorld::accepted('11112222'));
        $this->otherWriterDuringTheCall($world, $row);

        $submission = $this->declare($world);

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('declared', $submission->declaration->status->state);
        static::assertSame($certificat, $submission->declaration->status->result?->certificat);
        $record = $this->stored($world);
        static::assertSame(DeclarationState::Declared, $record->state());
        static::assertSame($certificat, $record->result()?->certificate()->value());
        static::assertSame($attempts, $record->attempts());
        static::assertSame([], $record->errors());
        static::assertNull($record->outcomeUnknownReason());
        static::assertSame($flagged, $record->isAcceptedAfterSupersession());
        static::assertSame($flagged, $submission->declaration->acceptedAfterSupersession);
        static::assertSame($row === 'declared' ? [] : ['DeclarationDeclared'], $world->publisher->names());
        static::assertSame(['050'], $world->gateway->calls());

        $again = $this->declare($world);

        if ($row === 'superseded') {
            // The current record of the period is the successor: its declaration is another one, sent now.
            static::assertSame(ItemOutcome::Declared, $again->outcome);
            static::assertSame('11112222', $again->declaration->status->result?->certificat);
            static::assertSame(['050', '050'], $world->gateway->calls());

            return;
        }
        static::assertSame(ItemOutcome::Replayed, $again->outcome);
        static::assertSame($certificat, $again->declaration->status->result?->certificat);
        static::assertSame(['050'], $world->gateway->calls());
    }

    // Row 4: erased (ForgetAccount)

    /**
     * @return iterable<string, array{bool}>
     */
    public static function managers(): iterable
    {
        yield 'rolling back' => [true];
        yield 'immediate' => [false];
    }

    #[DataProvider('managers')]
    public function testAnAcceptedAnswerOnAnErasedRecordIsARecordingFailureCarryingTheCertificat(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('12345678', 'W1dl9W2Oa4R6ab3UlQAqxF3'));
        $this->otherWriterDuringTheCall($world, 'erased');

        $failure = $this->recordingFailure($world);

        static::assertSame('12345678', $failure->certificate());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $failure->declarationId());
        static::assertSame('declaration_record.recording_failed', $failure->violationCode());
        static::assertSame(
            [
                'record_id' => 'record-1',
                'attempts' => 1,
                'last_code' => 'declaration_record.not_found',
                'certificat' => '12345678',
                'id_declaration' => 'W1dl9W2Oa4R6ab3UlQAqxF3',
            ],
            $failure->params(),
        );
        static::assertSame([], $world->records->inner()->all());
        static::assertSame([], $world->publisher->names());
        static::assertSame(['050'], $world->gateway->calls());

        // There is no local record left: a follow-up DeclareTurnover records the declaration anew and calls URSSAF again
        // (it is the host's decision to send it, ADR 0017 documents it).
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('11112222'));
        $again = $this->declare($world);

        static::assertSame(ItemOutcome::Declared, $again->outcome);
        static::assertSame('11112222', $again->declaration->status->result?->certificat);
        static::assertSame(['050', '050'], $world->gateway->calls());
    }

    // Recovery of an accepted answer that could not be recorded

    #[DataProvider('managers')]
    public function testAStorageFailureAfterAnAcceptedAnswerIsRecoveredByReconcileThenResolve(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('12345678'));
        $storage = new RuntimeException('storage down');
        $world->beforeNextSave(static function () use ($storage): never {
            throw $storage;
        });

        $failure = $this->recordingFailure($world);

        static::assertSame('12345678', $failure->certificate());
        static::assertSame(
            ['record_id' => 'record-1', 'attempts' => 1, 'last_code' => RuntimeException::class, 'certificat' => '12345678', 'id_declaration' => 'W1dl9W2Oa4R6ab3UlQAqxF3'],
            $failure->params(),
        );
        static::assertNull($failure->getPrevious());
        static::assertSame(DeclarationState::Submitting, $this->stored($world)->state());
        static::assertSame(['050'], $world->gateway->calls());

        $world->clock->moveTo('2026-11-17T09:16:00Z');
        $report = (new ReconcileStaleDeclarationsHandler($world->records, $world->clock, $world->unitOfWork, $world->policy))(new ReconcileStaleDeclarations(null));
        static::assertEquals([new BatchItem('record-1', ItemOutcome::ManualReview, ['stale_submission'])], $report->items());
        static::assertSame(DeclarationState::OutcomeUnknown, $this->stored($world)->state());
        $resolved = (new ResolveDeclarationOutcomeHandler($world->locator, $world->records, $world->clock, $world->unitOfWork))(
            ResolveDeclarationOutcome::asDeclared(
                DeclarationWorld::tenant(),
                DeclarationSelector::byRecordId(DeclarationRecordId::fromString('record-1')),
                '12345678',
            ),
        );

        static::assertSame('declared', $resolved->status->state);
        static::assertSame('12345678', $resolved->status->result?->certificat);
        $again = $this->declare($world);
        static::assertSame(ItemOutcome::Replayed, $again->outcome);
        static::assertSame(['050'], $world->gateway->calls());
    }

    /**
     * @mago-expect lint:no-boolean-flag-parameter The manager of the unit of work is a dimension of the data sets.
     */
    #[DataProvider('managers')]
    public function testAnOutboxFailureAfterAnAcceptedAnswerIsARecordingFailureAndNeverResends(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('12345678'), DeclarationWorld::accepted('11112222'));
        $storage = new RuntimeException('outbox down');
        $unitOfWork = new UnitOfWork(
            $transactional ? $world->transactions : new ImmediateTransactionManager(),
            $world->publisher,
            new RecordingFailureReporter(),
            new FailingOutbox($storage),
        );

        $failure = $this->recordingFailureWith($world, $unitOfWork);

        static::assertSame('12345678', $failure->certificate());
        static::assertSame(RuntimeException::class, $failure->params()['last_code']);
        static::assertSame(1, $failure->params()['attempts']);
        static::assertSame(['050'], $world->gateway->calls());
        // Rolled back: the claim is still submitting (reconcile then resolve, see above). Immediate: the record was saved
        // before the outbox failed, it is declared (only its integration events are lost).
        static::assertSame($transactional ? DeclarationState::Submitting : DeclarationState::Declared, $this->stored($world)->state());

        $again = $this->declare($world);

        static::assertSame($transactional ? ItemOutcome::NotClaimed : ItemOutcome::Replayed, $again->outcome);
        static::assertSame(['050'], $world->gateway->calls());
    }

    #[DataProvider('managers')]
    public function testAViolationThatIsNotAConflictIsNotRetried(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('12345678'));
        $world->beforeNextSave(static function (): never {
            throw InvariantViolation::because('storage.broken', 'The storage is broken.');
        });

        $failure = $this->recordingFailure($world);

        static::assertSame(1, $failure->params()['attempts']);
        static::assertSame('storage.broken', $failure->params()['last_code']);
        static::assertCount(1, array_keys($world->journal->entries(), 'save:record-1', true));
        static::assertSame(DeclarationState::Submitting, $this->stored($world)->state());
    }

    #[DataProvider('managers')]
    public function testAnAcceptedAnswerThatKeepsMeetingConflictsGivesUpAfterThreeAttempts(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('12345678'));
        $world->gateway->duringNextCall(fn() => $this->touchBeforeEachSave($world, 3));

        $failure = $this->recordingFailure($world);

        static::assertSame('12345678', $failure->certificate());
        static::assertSame(
            ['record_id' => 'record-1', 'attempts' => 3, 'last_code' => 'declaration_record.concurrent_modification', 'certificat' => '12345678', 'id_declaration' => 'W1dl9W2Oa4R6ab3UlQAqxF3'],
            $failure->params(),
        );
        static::assertSame(['050'], $world->gateway->calls());
        static::assertCount(3, array_keys($world->journal->entries(), 'save:record-1', true));
        static::assertSame(DeclarationState::OutcomeUnknown, $this->stored($world)->state());
        static::assertSame([], $world->publisher->names());
    }

    #[DataProvider('managers')]
    public function testAnAcceptedAnswerThatMeetsTwoConflictsIsRecordedOnTheThirdAttempt(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('12345678'));
        $world->gateway->duringNextCall(fn() => $this->touchBeforeEachSave($world, 2));

        $submission = $this->declare($world);

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame(DeclarationState::Declared, $this->stored($world)->state());
        static::assertCount(3, array_keys($world->journal->entries(), 'save:record-1', true));
        static::assertSame(['DeclarationDeclared'], $world->publisher->names());
    }

    // Columns B to E: the answers that are not an acceptance, first on the claim's own record

    /**
     * @return iterable<string, array{string, bool, array{outcome: ?ItemOutcome, state: string, thrown: ?class-string<Throwable>, follow: ItemOutcome, calls: list<string>}}>
     */
    public static function ownClaimCells(): iterable
    {
        $own = [
            'rejected' => ['outcome' => ItemOutcome::Rejected, 'state' => 'rejected', 'thrown' => null, 'follow' => ItemOutcome::Declared, 'calls' => ['050', '050']],
            'unknown' => ['outcome' => ItemOutcome::OutcomeUnknown, 'state' => 'outcome_unknown', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['050']],
            'transport' => ['outcome' => ItemOutcome::OutcomeUnknown, 'state' => 'outcome_unknown', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['050']],
            'unavailable' => ['outcome' => null, 'state' => 'pending', 'thrown' => RetryableUrssafFailure::class, 'follow' => ItemOutcome::Declared, 'calls' => ['050', '050']],
            'throttled' => ['outcome' => null, 'state' => 'pending', 'thrown' => RetryableUrssafFailure::class, 'follow' => ItemOutcome::Declared, 'calls' => ['050', '050']],
            'token' => ['outcome' => null, 'state' => 'pending', 'thrown' => RetryableUrssafFailure::class, 'follow' => ItemOutcome::Declared, 'calls' => ['050', '050']],
            'rate_limit' => ['outcome' => null, 'state' => 'pending', 'thrown' => RetryableUrssafFailure::class, 'follow' => ItemOutcome::Declared, 'calls' => ['050', '050']],
            'forbidden' => ['outcome' => ItemOutcome::InvalidCredentials, 'state' => 'invalid_credentials', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['050']],
            'credentials' => ['outcome' => ItemOutcome::InvalidCredentials, 'state' => 'invalid_credentials', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['050']],
            'not_found' => ['outcome' => null, 'state' => 'pending', 'thrown' => RecordNotFound::class, 'follow' => ItemOutcome::Declared, 'calls' => ['050', '050']],
            'domain' => ['outcome' => null, 'state' => 'outcome_unknown', 'thrown' => InvariantViolation::class, 'follow' => ItemOutcome::Replayed, 'calls' => ['050']],
            'foreign' => ['outcome' => null, 'state' => 'outcome_unknown', 'thrown' => RuntimeException::class, 'follow' => ItemOutcome::Replayed, 'calls' => ['050']],
        ];
        foreach (['rolling back' => true, 'immediate' => false] as $manager => $transactional) {
            foreach ($own as $answer => $expected) {
                yield "submitting, same attempt (own claim) x {$answer}, {$manager}" => [$answer, $transactional, $expected];
            }
        }
    }

    /**
     * @param array{outcome: ?ItemOutcome, state: string, thrown: ?class-string<Throwable>, follow: ItemOutcome, calls: list<string>} $expected
     */
    #[DataProvider('ownClaimCells')]
    public function testAnyOtherAnswerSettlesTheOwnClaim(string $answer, bool $transactional, array $expected): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure($answer), DeclarationWorld::accepted('11112222'));

        [$submission, $thrown] = $this->declareAndCatch($world);

        static::assertSame($expected['thrown'], $thrown instanceof Throwable ? $thrown::class : null);
        static::assertSame($expected['outcome'], $submission?->outcome);
        static::assertSame($expected['state'], $this->stored($world)->state()->value);
        static::assertSame(['050'], $world->gateway->calls());

        $again = $this->declare($world);

        static::assertSame($expected['follow'], $again->outcome);
        static::assertSame($expected['calls'], $world->gateway->calls());
    }

    // Columns B to E on a record the claim no longer owns: nothing changes

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function unownedCells(): iterable
    {
        $rows = ['outcome_unknown', 'pending', 'error', 'invalid_credentials', 'submitting_later', 'rejected', 'declared', 'superseded', 'erased'];
        foreach (['rolling back' => true, 'immediate' => false] as $manager => $transactional) {
            foreach ($rows as $row) {
                foreach (DeclareAnswers::names() as $answer) {
                    yield "{$row} x {$answer}, {$manager}" => [$row, $answer, $transactional];
                }
            }
        }
    }

    #[DataProvider('unownedCells')]
    public function testAnyOtherAnswerOnARecordItNoLongerOwnsChangesNothing(string $row, string $answer, bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure($answer), DeclarationWorld::accepted('11112222'));
        $this->otherWriterDuringTheCall($world, $row);
        // [outcome returned (the re-entry outcome of the state), state left, outcome of the follow-up DeclareTurnover, gateway calls at the end]
        [$outcome, $state, $follow, $calls] = match ($row) {
            'outcome_unknown' => [ItemOutcome::Replayed, 'outcome_unknown', ItemOutcome::Replayed, ['050']],
            'pending' => [ItemOutcome::NotClaimed, 'pending', ItemOutcome::Declared, ['050', '050']],
            'error' => [ItemOutcome::NotClaimed, 'error', ItemOutcome::Declared, ['050', '050']],
            'invalid_credentials' => [ItemOutcome::Replayed, 'invalid_credentials', ItemOutcome::Replayed, ['050']],
            'submitting_later' => [ItemOutcome::NotClaimed, 'submitting', ItemOutcome::NotClaimed, ['050']],
            'rejected' => [ItemOutcome::Replayed, 'rejected', ItemOutcome::Declared, ['050', '050']],
            'declared' => [ItemOutcome::Replayed, 'declared', ItemOutcome::Replayed, ['050']],
            'superseded' => [ItemOutcome::Replayed, 'outcome_unknown', ItemOutcome::Declared, ['050', '050']],
            default => [ItemOutcome::NotClaimed, 'submitting', ItemOutcome::Declared, ['050', '050']],
        };

        $submission = $this->declare($world);

        static::assertSame($outcome, $submission->outcome);
        static::assertSame($state, $submission->declaration->status->state);
        static::assertSame(['claim:record-1', 'call:050'], $world->journal->entries());
        static::assertSame([], $world->publisher->names());
        static::assertSame(['050'], $world->gateway->calls());
        $this->assertLeftAsTheOtherWriterLeftIt($world, $row);

        $again = $this->declare($world);

        static::assertSame($follow, $again->outcome);
        static::assertSame($calls, $world->gateway->calls());
    }

    private function assertLeftAsTheOtherWriterLeftIt(DeclarationWorld $world, string $row): void
    {
        if ($row === 'erased') {
            static::assertSame([], $world->records->inner()->all());

            return;
        }
        $record = $this->stored($world);
        static::assertSame($row === 'declared' ? '87654321' : null, $record->result()?->certificate()->value());
        static::assertSame($row === 'submitting_later' ? 2 : 1, $record->attempts());
    }

    private function worldWithPendingRecord(bool $transactional): DeclarationWorld
    {
        $world = new DeclarationWorld(new WorkflowPolicy(), $transactional);
        $world->recordIn('record-1', DeclarationState::Pending, 0);

        return $world;
    }

    /**
     * Another writer changes the stored record while the 050 call is in flight (after it was journaled, before it
     * answers). "own" leaves the claim alone.
     */
    private function otherWriterDuringTheCall(DeclarationWorld $world, string $row): void
    {
        if ($row === 'own') {
            return;
        }
        $world->gateway->duringNextCall(static function () use ($world, $row): void {
            $repository = $world->records->inner();
            $id = DeclarationRecordId::fromString('record-1');
            if ($row === 'erased') {
                $repository->deleteBySiret(DeclarationWorld::tenant(), DeclarationWorld::siret(), DeclarationState::cases());

                return;
            }
            $now = Instant::fromIso8601(DeclarationWorld::NOW);
            $other = $repository->get($id);
            match ($row) {
                'outcome_unknown' => $other->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $now),
                'pending' => self::resolvedAsNotReceived($other, $now),
                'error' => $other->markFailed(DeclarationWorld::errors(), $now),
                'invalid_credentials' => $other->markInvalidCredentials($now),
                'submitting_later' => self::claimedAgain($other, $now),
                'rejected' => $other->markRejected(DeclarationWorld::errors(), $now),
                'superseded' => self::superseded($repository, $other, $now),
                default => $other->markDeclared(DeclarationWorld::accepted('87654321'), $now),
            };
            $repository->save($other);
        });
    }

    private static function resolvedAsNotReceived(DeclarationRecord $record, Instant $now): void
    {
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $now);
        $record->resolveAsNotReceived($now);
    }

    private static function claimedAgain(DeclarationRecord $record, Instant $now): void
    {
        self::resolvedAsNotReceived($record, $now);
        $record->startSubmission($now);
    }

    /**
     * The record is reconciled, then another declaration of the period supersedes it (saved here: the caller saves the
     * superseded record).
     */
    private static function superseded(InMemoryDeclarationRecordRepository $repository, DeclarationRecord $record, Instant $now): void
    {
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $now);
        $successor = $record->supersede(DeclarationRecordId::fromString('record-2'), DeclarationWorld::fingerprint(10, 2000), $now);
        $repository->save($record);
        $repository->save($successor);
    }

    private function touchBeforeEachSave(DeclarationWorld $world, int $times): void
    {
        if ($times === 0) {
            return;
        }
        // Another process commits in the middle of the transaction: its write survives the rollback.
        $world->beforeNextSaveCommit(function () use ($world, $times): void {
            $other = $world->records->get(DeclarationRecordId::fromString('record-1'));
            if ($other->state() === DeclarationState::Submitting) {
                $other->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], Instant::fromIso8601(DeclarationWorld::NOW));
            }
            $world->records->inner()->save($other);
            $this->touchBeforeEachSave($world, $times - 1);
        });
    }

    private function stored(DeclarationWorld $world): DeclarationRecord
    {
        return $world->records->get(DeclarationRecordId::fromString('record-1'));
    }

    private function recordingFailure(DeclarationWorld $world): DeclarationRecordingFailed
    {
        return $this->recordingFailureWith($world, $world->unitOfWork);
    }

    private function recordingFailureWith(DeclarationWorld $world, UnitOfWork $unitOfWork): DeclarationRecordingFailed
    {
        try {
            $this->declareWith($world, $unitOfWork);
        } catch (DeclarationRecordingFailed $failure) {
            return $failure;
        }
        static::fail('Expected DeclarationRecordingFailed.');
    }

    /**
     * @return array{?DeclarationSubmission, ?Throwable}
     */
    private function declareAndCatch(DeclarationWorld $world): array
    {
        try {
            return [$this->declare($world), null];
        } catch (Throwable $failure) {
            return [null, $failure];
        }
    }

    private function declare(DeclarationWorld $world): DeclarationSubmission
    {
        return $this->declareWith($world, $world->unitOfWork);
    }

    private function declareWith(DeclarationWorld $world, UnitOfWork $unitOfWork): DeclarationSubmission
    {
        return $world->handlerWith($unitOfWork)(new DeclareTurnover(
            DeclarationWorld::tenant(),
            DeclarationWorld::siret(),
            DeclarationWorld::declaration(),
            true,
        ));
    }
}
