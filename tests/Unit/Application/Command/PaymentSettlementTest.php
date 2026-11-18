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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStalePayments;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStalePaymentsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingFailureReporter;
use Throwable;

/**
 * The settlement table of ADR 0009 for payments: the answer of 060 is settled against the record as it is stored when the
 * answer arrives, the claim being identified by its attempt number. One data set per cell of the table (row = the state
 * the record has when the answer arrives, column = the answer), each with a rolling-back transaction manager and with
 * ImmediateTransactionManager, ending with a follow-up PayContributions of the same reference and the exact list of the
 * gateway calls: 060 is never resent, URSSAF never gets two orders unless the host decides it.
 *
 * The "other writer" is a process that changes the stored record while the call is in flight (a reconciliation, an
 * operator, a concurrent requeue or call).
 *
 * Table-driven: one data set per cell, hence the method count and the complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class PaymentSettlementTest extends TestCase
{
    private const RECORD = 'record-1';

    // Column A: accepted (200 of 060)

    /**
     * Rows of the table whose accepted answer ends paid, by the state the other writer left: the claim's own state
     * (submitting, same attempt) comes first. Paid keeps the first result.
     *
     * @return iterable<string, array{string, bool, string, int}>
     */
    public static function acceptedCells(): iterable
    {
        $cells = [
            'submitting, same attempt (own claim)' => ['own', '11111111', 1],
            'outcome_unknown (reconciled meanwhile)' => ['outcome_unknown', '11111111', 1],
            'pending (resolved as not received)' => ['pending', '11111111', 1],
            'error (requeued then failed)' => ['error', '11111111', 1],
            'invalid_credentials' => ['invalid_credentials', '11111111', 1],
            'submitting, later attempt (claimed again)' => ['submitting_later', '11111111', 2],
            'rejected (late acceptance)' => ['rejected', '11111111', 1],
            'paid (first result kept)' => ['paid', '22222222', 1],
        ];
        foreach ($cells as $name => [$row, $certificat, $attempts]) {
            yield $name . ', rolling back' => [$row, true, $certificat, $attempts];
            yield $name . ', immediate' => [$row, false, $certificat, $attempts];
        }
    }

    #[DataProvider('acceptedCells')]
    public function testAnAcceptedAnswerIsRecordedAsPaidOnEverySurvivingState(string $row, bool $transactional, string $certificat, int $attempts): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('11111111'));
        $this->otherWriterDuringTheCall($world, $row);

        $submission = $this->pay($world);

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame('paid', $submission->payment->status->state);
        static::assertSame($certificat, $submission->payment->status->result?->certificat);
        $record = $this->stored($world);
        static::assertSame(PaymentState::Paid, $record->state());
        static::assertSame($certificat, $record->result()?->certificate()->value());
        static::assertSame($attempts, $record->attempts());
        static::assertSame([], $record->errors());
        static::assertNull($record->outcomeUnknownReason());
        static::assertSame($row === 'paid' ? [] : ['PaymentOrdered'], $world->publisher->names());
        static::assertSame(['060'], $world->gateway->calls());

        $again = $this->pay($world);

        static::assertSame(ItemOutcome::Replayed, $again->outcome);
        static::assertSame($certificat, $again->payment->status->result?->certificat);
        static::assertSame(['060'], $world->gateway->calls());
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
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('11111111'));
        $this->otherWriterDuringTheCall($world, 'erased');

        $failure = $this->recordingFailure($world);

        static::assertSame('11111111', $failure->certificate());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $failure->paymentId());
        static::assertSame('payment_record.recording_failed', $failure->violationCode());
        static::assertSame(
            ['record_id' => self::RECORD, 'attempts' => 1, 'last_code' => 'payment_record.not_found', 'certificat' => '11111111', 'id_telepaiement' => 'W1dl9W2Oa4R6ab3UlQAqxF2'],
            $failure->params(),
        );
        static::assertSame([], $world->payments->inner()->all());
        static::assertSame([], $world->publisher->names());
        static::assertSame(['060'], $world->gateway->calls());

        // No local record is left: the host alone decides to order again (a follow-up PayContributions records anew).
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('33333333'));
        $again = $this->pay($world);

        static::assertSame(ItemOutcome::Paid, $again->outcome);
        static::assertSame('33333333', $again->payment->status->result?->certificat);
        static::assertSame(['060', '060'], $world->gateway->calls());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function managerNames(): iterable
    {
        yield 'rolling back' => ['rolling back'];
        yield 'immediate' => ['immediate'];
    }

    // Recovery of an accepted answer that could not be recorded

    #[DataProvider('managers')]
    public function testAStorageFailureAfterAnAcceptedAnswerIsRecoveredByReconcileThenResolve(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('11111111', null));
        $world->payments->beforeNextSave(static function (): never {
            throw new RuntimeException('storage down');
        });

        $failure = $this->recordingFailure($world);

        static::assertSame('11111111', $failure->certificate());
        static::assertNull($failure->paymentId());
        static::assertSame(['record_id' => self::RECORD, 'attempts' => 1, 'last_code' => RuntimeException::class, 'certificat' => '11111111'], $failure->params());
        static::assertSame(PaymentState::Submitting, $this->stored($world)->state());
        static::assertSame(['060'], $world->gateway->calls());

        $world->clock->moveTo('2026-11-17T09:16:00Z');
        $report = (new ReconcileStalePaymentsHandler($world->payments, $world->clock, $world->unitOfWork, $world->policy))(new ReconcileStalePayments(null));
        static::assertEquals([new BatchItem(self::RECORD, ItemOutcome::ManualReview, ['stale_submission'])], $report->items());
        static::assertSame(PaymentState::OutcomeUnknown, $this->stored($world)->state());
        $resolved = (new ResolvePaymentOutcomeHandler($world->locator, $world->payments, $world->clock, $world->unitOfWork))(
            ResolvePaymentOutcome::asPaid(PaymentWorld::tenant(), PaymentSelector::byRecordId(PaymentRecordId::fromString(self::RECORD)), '11111111'),
        );

        static::assertSame('paid', $resolved->status->state);
        static::assertSame('11111111', $resolved->status->result?->certificat);
        static::assertSame(ItemOutcome::Replayed, $this->pay($world)->outcome);
        static::assertSame(['060'], $world->gateway->calls());
    }

    #[DataProvider('managerNames')]
    public function testAnOutboxFailureAfterAnAcceptedAnswerIsARecordingFailureAndNeverResends(string $manager): void
    {
        $transactional = $manager === 'rolling back';
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('11111111'), PaymentWorld::paid('33333333'));
        $storage = new RuntimeException('outbox down');
        $unitOfWork = new UnitOfWork(
            $transactional ? $world->transactions : new ImmediateTransactionManager(),
            $world->publisher,
            new RecordingFailureReporter(),
            new FailingOutbox($storage),
        );

        try {
            $this->payWith($world, $unitOfWork);
            static::fail('Expected PaymentRecordingFailed.');
        } catch (PaymentRecordingFailed $failure) {
            static::assertSame('11111111', $failure->certificate());
            static::assertSame(['record_id' => self::RECORD, 'attempts' => 1, 'last_code' => RuntimeException::class, 'certificat' => '11111111', 'id_telepaiement' => 'W1dl9W2Oa4R6ab3UlQAqxF2'], $failure->params());
        }
        static::assertSame(['060'], $world->gateway->calls());
        // Rolled back: the claim is still submitting (reconcile then resolve). Immediate: the record was saved before the
        // outbox failed, it is paid (only its integration events are lost).
        static::assertSame($transactional ? PaymentState::Submitting : PaymentState::Paid, $this->stored($world)->state());

        $again = $this->pay($world);

        static::assertSame($transactional ? ItemOutcome::NotClaimed : ItemOutcome::Replayed, $again->outcome);
        static::assertSame(['060'], $world->gateway->calls());
    }

    #[DataProvider('managers')]
    public function testAnAcceptedAnswerThatKeepsMeetingConflictsGivesUpAfterThreeAttempts(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('11111111'));
        $world->gateway->duringNextCall(fn() => $this->bumpBeforeEachSave($world, 3));

        $failure = $this->recordingFailure($world);

        static::assertSame('11111111', $failure->certificate());
        static::assertSame(
            ['record_id' => self::RECORD, 'attempts' => 3, 'last_code' => 'payment_record.concurrent_modification', 'certificat' => '11111111', 'id_telepaiement' => 'W1dl9W2Oa4R6ab3UlQAqxF2'],
            $failure->params(),
        );
        static::assertSame(['060'], $world->gateway->calls());
        static::assertCount(3, array_keys($world->journal->entries(), 'save:' . self::RECORD, true));
        static::assertSame(PaymentState::Submitting, $this->stored($world)->state());
        static::assertSame([], $world->publisher->names());
    }

    #[DataProvider('managers')]
    public function testAnAcceptedAnswerThatMeetsTwoConflictsIsRecordedAtTheThirdAttempt(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('11111111'));
        $world->gateway->duringNextCall(fn() => $this->bumpBeforeEachSave($world, 2));

        $submission = $this->pay($world);

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertCount(3, array_keys($world->journal->entries(), 'save:' . self::RECORD, true));
        static::assertSame(PaymentState::Paid, $this->stored($world)->state());
        static::assertSame(['PaymentOrdered'], $world->publisher->names());
    }

    #[DataProvider('managers')]
    public function testAConflictThenAStorageFailureIsARecordingFailureAtTheSecondAttempt(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid('11111111'));
        $world->gateway->duringNextCall(static function () use ($world): void {
            $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner) use ($world): void {
                $inner->save($inner->get(PaymentRecordId::fromString(self::RECORD)));
                $world->payments->beforeNextSave(static function (): never {
                    throw new RuntimeException('storage down');
                });
            });
        });

        $failure = $this->recordingFailure($world);

        static::assertSame(
            ['record_id' => self::RECORD, 'attempts' => 2, 'last_code' => RuntimeException::class, 'certificat' => '11111111', 'id_telepaiement' => 'W1dl9W2Oa4R6ab3UlQAqxF2'],
            $failure->params(),
        );
        static::assertCount(2, array_keys($world->journal->entries(), 'save:' . self::RECORD, true));
    }

    #[DataProvider('managers')]
    public function testAnotherAnswerThatKeepsMeetingConflictsRethrowsTheLastConflict(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, self::answer('rejected'));
        $world->gateway->duringNextCall(fn() => $this->bumpBeforeEachSave($world, 3));

        try {
            $this->pay($world);
            static::fail('Expected the conflict.');
        } catch (InvariantViolation $conflict) {
            static::assertSame('payment_record.concurrent_modification', $conflict->violationCode());
        }
        static::assertCount(3, array_keys($world->journal->entries(), 'save:' . self::RECORD, true));
        static::assertSame(PaymentState::Submitting, $this->stored($world)->state());
    }

    #[DataProvider('managers')]
    public function testAnotherAnswerThatMeetsTwoConflictsIsSettledAtTheThirdAttempt(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, self::answer('rejected'));
        $world->gateway->duringNextCall(fn() => $this->bumpBeforeEachSave($world, 2));

        $submission = $this->pay($world);

        static::assertSame(ItemOutcome::Rejected, $submission->outcome);
        static::assertCount(3, array_keys($world->journal->entries(), 'save:' . self::RECORD, true));
    }

    #[DataProvider('managers')]
    public function testAnotherAnswerWhoseFirstSaveConflictsThenFindsTheRecordNoLongerItsOwn(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, self::answer('rejected'));
        $world->gateway->duringNextCall(static function () use ($world): void {
            $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
                $other = $inner->get(PaymentRecordId::fromString(self::RECORD));
                $other->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], Instant::fromIso8601(PaymentWorld::NOW));
                $inner->save($other);
            });
        });

        $submission = $this->pay($world);

        static::assertSame(ItemOutcome::Replayed, $submission->outcome);
        static::assertSame('outcome_unknown', $submission->payment->status->state);
        static::assertCount(1, array_keys($world->journal->entries(), 'save:' . self::RECORD, true));
        static::assertSame([], $world->publisher->names());
    }

    #[DataProvider('managers')]
    public function testAStorageFailureAfterAnotherAnswerIsRethrownUnchanged(bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, self::answer('rejected'));
        $storage = new RuntimeException('storage down');
        $world->payments->beforeNextSave(static function () use ($storage): never {
            throw $storage;
        });

        try {
            $this->pay($world);
            static::fail('Expected the storage failure.');
        } catch (RuntimeException $failure) {
            static::assertSame($storage, $failure);
        }
        static::assertSame(PaymentState::Submitting, $this->stored($world)->state());
    }

    // Columns B to E: the answers that are not an acceptance, first on the claim's own record

    /**
     * [outcome returned, state left, class thrown, outcome of the follow-up PayContributions, gateway calls at the end]
     *
     * @return iterable<string, array{string, bool, array{outcome: ?ItemOutcome, state: string, thrown: ?class-string<Throwable>, follow: ItemOutcome, calls: list<string>}}>
     */
    public static function ownClaimCells(): iterable
    {
        $own = [
            'rejected' => ['outcome' => ItemOutcome::Rejected, 'state' => 'rejected', 'thrown' => null, 'follow' => ItemOutcome::Paid, 'calls' => ['060', '060']],
            'unknown' => ['outcome' => ItemOutcome::OutcomeUnknown, 'state' => 'outcome_unknown', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['060']],
            'reached' => ['outcome' => ItemOutcome::OutcomeUnknown, 'state' => 'outcome_unknown', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['060']],
            'throttled' => ['outcome' => null, 'state' => 'pending', 'thrown' => RetryableUrssafFailure::class, 'follow' => ItemOutcome::Paid, 'calls' => ['060', '060']],
            'token' => ['outcome' => null, 'state' => 'pending', 'thrown' => RetryableUrssafFailure::class, 'follow' => ItemOutcome::Paid, 'calls' => ['060', '060']],
            'rate_limit' => ['outcome' => null, 'state' => 'pending', 'thrown' => RetryableUrssafFailure::class, 'follow' => ItemOutcome::Paid, 'calls' => ['060', '060']],
            'forbidden' => ['outcome' => ItemOutcome::InvalidCredentials, 'state' => 'invalid_credentials', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['060']],
            'credentials' => ['outcome' => ItemOutcome::InvalidCredentials, 'state' => 'invalid_credentials', 'thrown' => null, 'follow' => ItemOutcome::Replayed, 'calls' => ['060']],
            'foreign' => ['outcome' => null, 'state' => 'outcome_unknown', 'thrown' => RuntimeException::class, 'follow' => ItemOutcome::Replayed, 'calls' => ['060']],
            'domain' => ['outcome' => null, 'state' => 'outcome_unknown', 'thrown' => InvariantViolation::class, 'follow' => ItemOutcome::Replayed, 'calls' => ['060']],
            'no_credentials' => ['outcome' => null, 'state' => 'pending', 'thrown' => RecordNotFound::class, 'follow' => ItemOutcome::Paid, 'calls' => ['060', '060']],
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
        $world->gateway->queue(Operation::Pay, self::answer($answer), PaymentWorld::paid('33333333'));

        [$submission, $thrown] = $this->payAndCatch($world);

        static::assertSame($expected['thrown'], $thrown instanceof Throwable ? $thrown::class : null);
        static::assertSame($expected['outcome'], $submission?->outcome);
        static::assertSame($expected['state'], $this->stored($world)->state()->value);
        static::assertSame(['060'], $world->gateway->calls());

        $again = $this->pay($world);

        static::assertSame($expected['follow'], $again->outcome);
        static::assertSame($expected['calls'], $world->gateway->calls());
    }

    public function testAMayHaveReachedFailureOf060IsNeverResendable(): void
    {
        $failure = self::answer('reached');

        static::assertInstanceOf(UrssafUnavailable::class, $failure);
        static::assertTrue($failure->mayHaveReachedUrssaf());
    }

    public function testTheStoredErrorsOfEveryFailureAreExact(): void
    {
        $expected = [
            'rejected' => [['ER_API_060_0070', 'invalid_bank_details', 'Message', 'Description']],
            'unknown' => [['urssaf.outcome_unknown', 'technical', 'server_error', null]],
            'reached' => [['urssaf.unavailable', 'technical', UrssafUnavailable::class, null]],
            'foreign' => [['urssaf.unexpected_failure', 'unknown', RuntimeException::class, null]],
            'domain' => [['payment_record.test', 'unknown', InvariantViolation::class, null]],
        ];
        foreach ($expected as $answer => $errors) {
            $world = $this->worldWithPendingRecord(true);
            $world->gateway->queue(Operation::Pay, self::answer($answer));

            $this->payAndCatch($world);

            $stored = array_map(
                static fn(SubmissionError $error): array => [$error->code(), $error->kind(), $error->message(), $error->description()],
                $this->stored($world)->errors(),
            );
            static::assertSame($errors, $stored, $answer);
        }
    }

    public function testNotSentFailuresExhaustTheAttemptsThenReturnExhausted(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(2));
        $world->activeMandate();
        $world->paymentIn(self::RECORD, PaymentState::Pending, 0);
        $world->gateway->queue(Operation::Pay, self::answer('throttled'), self::answer('rate_limit'));

        try {
            $this->pay($world);
            static::fail('Expected RetryableUrssafFailure.');
        } catch (RetryableUrssafFailure) {
            static::assertSame(PaymentState::Pending, $this->stored($world)->state());
        }
        $second = $this->pay($world);

        static::assertSame(ItemOutcome::Exhausted, $second->outcome);
        static::assertSame('error', $second->payment->status->state);
        static::assertSame(2, $second->payment->status->attempts);
        static::assertSame('urssaf.unavailable', $second->payment->status->errors[0]->code);
        static::assertSame(['060', '060'], $world->gateway->calls());
    }

    public function testALocalRefusalIsRethrownEvenWhenTheAttemptsAreExhausted(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(1));
        $world->activeMandate();
        $world->paymentIn(self::RECORD, PaymentState::Pending, 0);
        $world->gateway->queue(Operation::Pay, self::answer('no_credentials'));

        $this->expectException(RecordNotFound::class);

        try {
            $this->pay($world);
        } finally {
            static::assertSame(PaymentState::Error, $this->stored($world)->state());
        }
    }

    // Columns B to E on a record the claim no longer owns: nothing changes

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function unownedCells(): iterable
    {
        $rows = ['outcome_unknown', 'pending', 'error', 'invalid_credentials', 'submitting_later', 'rejected', 'paid', 'erased'];
        $answers = ['rejected', 'unknown', 'reached', 'throttled', 'token', 'rate_limit', 'forbidden', 'credentials', 'foreign', 'domain', 'no_credentials'];
        foreach (['rolling back' => true, 'immediate' => false] as $manager => $transactional) {
            foreach ($rows as $row) {
                foreach ($answers as $answer) {
                    yield "{$row} x {$answer}, {$manager}" => [$row, $answer, $transactional];
                }
            }
        }
    }

    #[DataProvider('unownedCells')]
    public function testAnyOtherAnswerOnARecordItNoLongerOwnsChangesNothing(string $row, string $answer, bool $transactional): void
    {
        $world = $this->worldWithPendingRecord($transactional);
        $world->gateway->queue(Operation::Pay, self::answer($answer), PaymentWorld::paid('33333333'));
        $this->otherWriterDuringTheCall($world, $row);
        // [outcome returned (the re-entry outcome of the state), state left, outcome of the follow-up PayContributions, gateway calls at the end]
        [$outcome, $state, $follow, $calls] = match ($row) {
            'outcome_unknown' => [ItemOutcome::Replayed, 'outcome_unknown', ItemOutcome::Replayed, ['060']],
            'pending' => [ItemOutcome::NotClaimed, 'pending', ItemOutcome::Paid, ['060', '060']],
            'error' => [ItemOutcome::NotClaimed, 'error', ItemOutcome::Paid, ['060', '060']],
            'invalid_credentials' => [ItemOutcome::Replayed, 'invalid_credentials', ItemOutcome::Replayed, ['060']],
            'submitting_later' => [ItemOutcome::NotClaimed, 'submitting', ItemOutcome::NotClaimed, ['060']],
            'rejected' => [ItemOutcome::Replayed, 'rejected', ItemOutcome::Paid, ['060', '060']],
            'paid' => [ItemOutcome::Replayed, 'paid', ItemOutcome::Replayed, ['060']],
            default => [ItemOutcome::NotClaimed, 'submitting', ItemOutcome::Paid, ['060', '060']],
        };

        $submission = $this->pay($world);

        static::assertSame($outcome, $submission->outcome);
        static::assertSame($state, $submission->payment->status->state);
        static::assertSame(['claim:' . self::RECORD, 'call:060'], $world->journal->entries());
        static::assertSame([], $world->publisher->names());
        static::assertSame([], $world->outbox->appended());
        static::assertSame(['060'], $world->gateway->calls());
        $this->assertLeftAsTheOtherWriterLeftIt($world, $row);

        $again = $this->pay($world);

        static::assertSame($follow, $again->outcome);
        static::assertSame($calls, $world->gateway->calls());
    }

    private function assertLeftAsTheOtherWriterLeftIt(PaymentWorld $world, string $row): void
    {
        if ($row === 'erased') {
            static::assertSame([], $world->payments->inner()->all());

            return;
        }
        $record = $this->stored($world);
        static::assertSame($row === 'paid' ? '22222222' : null, $record->result()?->certificate()->value());
        static::assertSame($row === 'submitting_later' ? 2 : 1, $record->attempts());
    }

    private static function answer(string $answer): Throwable
    {
        return match ($answer) {
            'rejected' => UrssafRequestRejected::withErrors(
                Operation::Pay,
                400,
                ApiErrors::of(ApiError::of('ER_API_060_0070', 'Message', 'Description')),
                RejectionKind::InvalidBankDetails,
            ),
            'unknown' => OutcomeUnknown::fromUnavailable(UrssafUnavailable::withStatus(Operation::Pay, 500, null, ApiErrors::none())),
            'reached' => UrssafUnavailable::withStatus(Operation::Pay, 503, null, ApiErrors::none()),
            'throttled' => UrssafUnavailable::withStatus(Operation::Pay, 429, null, ApiErrors::none()),
            'token' => UrssafUnavailable::duringAuthentication(Operation::Pay, 503, null, null),
            'rate_limit' => RateLimitExceeded::forOperation(Operation::Pay, 5),
            'forbidden' => UrssafAuthenticationFailed::withStatus(403),
            'domain' => InvariantViolation::because('payment_record.test', 'Not a URSSAF answer.'),
            'no_credentials' => RecordNotFound::withIdentifier('credentials', 'tenant-1'),
            'foreign' => new RuntimeException('foreign gateway failure'),
            default => UrssafAuthenticationFailed::withStatus(401),
        };
    }

    private function worldWithPendingRecord(bool $transactional): PaymentWorld
    {
        $world = new PaymentWorld(new WorkflowPolicy(), $transactional);
        $world->activeMandate();
        $world->paymentIn(self::RECORD, PaymentState::Pending, 0);

        return $world;
    }

    /**
     * Another writer changes the stored record while the 060 call is in flight (after it was journaled, before it
     * answers). "own" leaves the claim alone.
     */
    private function otherWriterDuringTheCall(PaymentWorld $world, string $row): void
    {
        if ($row === 'own') {
            return;
        }
        $world->gateway->duringNextCall(static function () use ($world, $row): void {
            $repository = $world->payments->inner();
            $id = PaymentRecordId::fromString(self::RECORD);
            if ($row === 'erased') {
                $repository->deleteBySiret(PaymentWorld::tenant(), PaymentWorld::siret(), PaymentState::cases());

                return;
            }
            $now = Instant::fromIso8601(PaymentWorld::NOW);
            $other = $repository->get($id);
            match ($row) {
                'outcome_unknown' => $other->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $now),
                'pending' => self::resolvedAsNotReceived($other, $now),
                'error' => $other->markFailed(PaymentWorld::errors(), $now),
                'invalid_credentials' => $other->markInvalidCredentials($now),
                'submitting_later' => self::claimedAgain($other, $now),
                'rejected' => $other->markRejected(PaymentWorld::errors(), $now),
                default => $other->markPaid(PaymentWorld::paid('22222222'), $now),
            };
            $repository->save($other);
        });
    }

    private static function resolvedAsNotReceived(PaymentRecord $record, Instant $now): void
    {
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], $now);
        $record->resolveAsNotReceived($now);
    }

    private static function claimedAgain(PaymentRecord $record, Instant $now): void
    {
        self::resolvedAsNotReceived($record, $now);
        $record->startSubmission($now);
    }

    /**
     * Another writer saves the record without changing it just before each of the next $times saves of the claim: every
     * save of the settlement meets a conflict.
     */
    private function bumpBeforeEachSave(PaymentWorld $world, int $times): void
    {
        if ($times === 0) {
            return;
        }
        $world->otherWriterBeforeNextSave(function (InMemoryPaymentRecordRepository $inner) use ($world, $times): void {
            $inner->save($inner->get(PaymentRecordId::fromString(self::RECORD)));
            $this->bumpBeforeEachSave($world, $times - 1);
        });
    }

    private function stored(PaymentWorld $world): PaymentRecord
    {
        return $world->payments->findByReference(PaymentWorld::tenant(), PaymentWorld::reference())
            ?? $world->payments->get(PaymentRecordId::fromString(self::RECORD));
    }

    private function recordingFailure(PaymentWorld $world): PaymentRecordingFailed
    {
        try {
            $this->pay($world);
        } catch (PaymentRecordingFailed $failure) {
            return $failure;
        }
        static::fail('Expected PaymentRecordingFailed.');
    }

    /**
     * @return array{?PaymentSubmission, ?Throwable}
     */
    private function payAndCatch(PaymentWorld $world): array
    {
        try {
            return [$this->pay($world), null];
        } catch (RuntimeException|InvariantViolation|RecordNotFound $failure) {
            return [null, $failure];
        }
    }

    private function pay(PaymentWorld $world): PaymentSubmission
    {
        return $this->payWith($world, $world->unitOfWork);
    }

    private function payWith(PaymentWorld $world, UnitOfWork $unitOfWork): PaymentSubmission
    {
        return $world->handlerWith($unitOfWork)(new PayContributions(
            PaymentWorld::tenant(),
            PaymentWorld::reference(),
            PaymentWorld::siret(),
            PaymentWorld::order(),
        ));
    }
}
