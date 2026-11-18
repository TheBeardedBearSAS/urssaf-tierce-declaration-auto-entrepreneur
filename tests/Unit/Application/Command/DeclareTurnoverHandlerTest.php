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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclareAnswers;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use Throwable;

/**
 * DeclareTurnover: the re-entry table of ADR 0009 (every row, same and other fingerprint), the guards of ADR 0011 and the
 * races of ADR 0009.
 *
 * @mago-expect lint:too-many-methods
 */
final class DeclareTurnoverHandlerTest extends TestCase
{
    public function testANewDeclarationIsRecordedThenSent(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('new-1', $submission->declaration->recordId);
        static::assertSame('tenant-1', $submission->declaration->tenantId);
        static::assertSame('73282932000074', $submission->declaration->siret);
        static::assertSame('M', $submission->declaration->periodicity);
        static::assertSame(2026, $submission->declaration->periodYear);
        static::assertSame(10, $submission->declaration->periodNumber);
        static::assertNull($submission->declaration->supersedes);
        static::assertSame('declared', $submission->declaration->status->state);
        static::assertSame(
            [
                'begin',
                'save:new-1',
                'append:',
                'commit',
                'publish:DeclarationRecorded',
                'claim:new-1',
                'call:050',
                'begin',
                'save:new-1',
                'append:urssaf.tdae.declaration.declared',
                'commit',
                'publish:DeclarationDeclared',
            ],
            $world->journal->entries(),
        );
        static::assertEquals([DeclarationWorld::declaration()], $world->gateway->declarations());
    }

    public function testTheRecordKeepsTheTrackedAccountOfTheSiret(): void
    {
        $world = $this->worldWithMandate();
        $world->trackedAccount();
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame('account-1', $submission->declaration->accountId);
        static::assertSame('account-1', $world->records->get(DeclarationRecordId::fromString('new-1'))->accountId()?->value());
    }

    public function testTheRecordOfAnUntrackedSiretHasNoAccount(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertNull($submission->declaration->accountId);
    }

    public function testTheRecordHoldsTheFingerprintAndNeverTheAmounts(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $this->declare($world, DeclarationWorld::declaration());

        $stored = $world->records->get(DeclarationRecordId::fromString('new-1'));
        static::assertTrue($stored->fingerprint()->equals(DeclarationWorld::fingerprint()));
        static::assertStringNotContainsString('1000', serialize($stored->status()));
    }

    // Re-entry (ADR 0009)

    /**
     * One data set per cell of the re-entry table of ADR 0009: [state, attempts, 'same' or 'other' declaration, what happens].
     * "sent": the guards run, the declaration is recorded or requeued and sent. "no call": the guards do not run and
     * nothing is sent.
     *
     * @return iterable<string, array{DeclarationState, int, string, string}>
     */
    public static function reentryCells(): iterable
    {
        yield 'pending, same declaration' => [DeclarationState::Pending, 0, 'same', 'sent'];
        yield 'pending, other declaration' => [DeclarationState::Pending, 0, 'other', 'superseded'];
        yield 'error with attempts left, same declaration' => [DeclarationState::Error, 1, 'same', 'requeued'];
        yield 'error with attempts left, other declaration' => [DeclarationState::Error, 1, 'other', 'superseded'];
        yield 'error exhausted, same declaration' => [DeclarationState::Error, 5, 'same', 'exhausted'];
        yield 'error exhausted, other declaration' => [DeclarationState::Error, 5, 'other', 'superseded'];
        yield 'submitting, same declaration' => [DeclarationState::Submitting, 1, 'same', 'not_claimed'];
        yield 'submitting, other declaration' => [DeclarationState::Submitting, 1, 'other', 'not_claimed'];
        yield 'declared, same declaration' => [DeclarationState::Declared, 1, 'same', 'replayed'];
        yield 'declared, other declaration' => [DeclarationState::Declared, 1, 'other', 'superseded'];
        yield 'outcome_unknown, same declaration' => [DeclarationState::OutcomeUnknown, 1, 'same', 'replayed'];
        yield 'outcome_unknown, other declaration' => [DeclarationState::OutcomeUnknown, 1, 'other', 'superseded'];
        yield 'invalid_credentials, same declaration' => [DeclarationState::InvalidCredentials, 1, 'same', 'replayed'];
        yield 'invalid_credentials, other declaration' => [DeclarationState::InvalidCredentials, 1, 'other', 'superseded'];
        yield 'rejected, same declaration' => [DeclarationState::Rejected, 1, 'same', 'superseded'];
        yield 'rejected, other declaration' => [DeclarationState::Rejected, 1, 'other', 'superseded'];
    }

    #[DataProvider('reentryCells')]
    public function testReentryRunsTheGuardsOnTheRowsThatSendOnly(DeclarationState $state, int $attempts, string $content, string $expected): void
    {
        $world = new DeclarationWorld();
        $world->recordWith('record-1', $state, $attempts, $content === 'same' ? DeclarationWorld::fingerprint() : DeclarationWorld::fingerprint(10, 2000), null);
        $entries = $world->journal->entries();

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        $sends = \in_array($expected, ['sent', 'requeued', 'superseded'], true);
        if ($sends) {
            static::assertInstanceOf(MandateRequired::class, $thrown, 'The mandate guard runs before any record or claim.');
            static::assertSame($entries, $world->journal->entries());
            static::assertSame([], $world->gateway->calls());
            static::assertSame([$state], array_map(static fn(DeclarationRecord $record): DeclarationState => $record->state(), array_values($world->records->inner()->all())));

            return;
        }
        static::assertNotInstanceOf(Throwable::class, $thrown, 'The guards do not run on a row that sends nothing.');
        static::assertSame([], $world->gateway->calls());
        static::assertSame($entries, $world->journal->entries());
    }

    #[DataProvider('reentryCells')]
    public function testReentryOutcomeAndRecords(DeclarationState $state, int $attempts, string $content, string $expected): void
    {
        $world = $this->worldWithMandate();
        $world->recordWith('record-1', $state, $attempts, $content === 'same' ? DeclarationWorld::fingerprint() : DeclarationWorld::fingerprint(10, 2000), null);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted('11112222'));

        $submission = $this->declare($world, DeclarationWorld::declaration());

        match ($expected) {
            'sent' => $this->assertSent($world, $submission),
            'requeued' => $this->assertRequeued($world, $submission),
            'exhausted' => $this->assertNothingSent($world, $submission, ItemOutcome::Exhausted, 'error'),
            'not_claimed' => $this->assertNothingSent($world, $submission, ItemOutcome::NotClaimed, 'submitting'),
            'replayed' => $this->assertNothingSent($world, $submission, ItemOutcome::Replayed, $state->value),
            default => $this->assertSuperseded($world, $submission, $state),
        };
    }

    private function assertSent(DeclarationWorld $world, DeclarationSubmission $submission): void
    {
        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('record-1', $submission->declaration->recordId);
        static::assertSame(['050'], $world->gateway->calls());
        static::assertCount(1, $world->records->inner()->all());
    }

    private function assertRequeued(DeclarationWorld $world, DeclarationSubmission $submission): void
    {
        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('record-1', $submission->declaration->recordId);
        static::assertSame(2, $submission->declaration->status->attempts);
        static::assertSame(['050'], $world->gateway->calls());
        static::assertContains('publish:DeclarationRequeued', $world->journal->entries());
    }

    private function assertNothingSent(DeclarationWorld $world, DeclarationSubmission $submission, ItemOutcome $outcome, string $state): void
    {
        static::assertSame($outcome, $submission->outcome);
        static::assertSame($state, $submission->declaration->status->state);
        static::assertSame('record-1', $submission->declaration->recordId);
        static::assertSame([], $world->gateway->calls());
    }

    private function assertSuperseded(DeclarationWorld $world, DeclarationSubmission $submission, DeclarationState $state): void
    {
        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('new-1', $submission->declaration->recordId);
        static::assertSame('record-1', $submission->declaration->supersedes);
        static::assertSame(['050'], $world->gateway->calls());
        static::assertCount(2, $world->records->inner()->all());
        $old = $world->records->get(DeclarationRecordId::fromString('record-1'));
        static::assertSame('new-1', $old->supersededBy()?->value());
        static::assertSame($state, $old->state());
        static::assertFalse($old->isCurrent());
        static::assertTrue($world->records->get(DeclarationRecordId::fromString('new-1'))->fingerprint()->equals(DeclarationWorld::fingerprint()));
    }

    public function testASupersessionSavesTheSupersededRecordBeforeTheNewOne(): void
    {
        $world = $this->worldWithMandate();
        $world->trackedAccount();
        $world->recordWith('record-1', DeclarationState::Rejected, 1, DeclarationWorld::fingerprint(10, 2000), $world->accounts->findBySiret(DeclarationWorld::tenant(), DeclarationWorld::siret())?->id());
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(
            ['begin', 'save:record-1', 'save:new-1', 'append:', 'commit', 'publish:DeclarationRecorded', 'claim:new-1', 'call:050'],
            \array_slice($world->journal->entries(), 0, 8),
        );
        static::assertSame('account-1', $submission->declaration->accountId);
        static::assertSame('record-1', $submission->declaration->supersedes);
        static::assertSame('new-1', $world->records->get(DeclarationRecordId::fromString('record-1'))->supersededBy()?->value());
    }

    public function testAnotherPeriodOfTheSameSiretIsAnotherDeclaration(): void
    {
        $world = $this->worldWithMandate();
        $world->recordIn('record-1', DeclarationState::Declared, 1);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, DeclarationWorld::declaration(9));

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('new-1', $submission->declaration->recordId);
        static::assertNull($submission->declaration->supersedes);
        static::assertTrue($world->records->get(DeclarationRecordId::fromString('record-1'))->isCurrent());
    }

    public function testASentReentryOfAPendingRecordKeepsItsId(): void
    {
        $world = $this->worldWithMandate();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(['claim:record-1', 'call:050', 'begin', 'save:record-1', 'append:urssaf.tdae.declaration.declared', 'commit', 'publish:DeclarationDeclared'], $world->journal->entries());
    }

    public function testTheRetryableFailureOfASendIsThrownAfterTheCommit(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Declare, DeclareAnswers::failure('throttled'));

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertInstanceOf(RetryableUrssafFailure::class, $thrown);
        static::assertSame(DeclarationState::Pending, $world->records->get(DeclarationRecordId::fromString('new-1'))->state());
    }

    // Guards (ADR 0011)

    /**
     * @return iterable<string, array{Periodicity}>
     */
    public static function mismatchedPeriodicities(): iterable
    {
        yield 'a monthly declaration on a quarterly account' => [Periodicity::Quarterly];
        yield 'a quarterly declaration on a monthly account' => [Periodicity::Monthly];
    }

    /**
     * The declaration whose periodicity is not the one of the account.
     */
    private static function declarationOtherThan(Periodicity $accountPeriodicity): TurnoverDeclaration
    {
        return $accountPeriodicity === Periodicity::Monthly
            ? TurnoverDeclaration::of(QuarterlyPeriod::of(2026, 3), DeclarationWorld::turnover())
            : DeclarationWorld::declaration();
    }

    #[DataProvider('mismatchedPeriodicities')]
    public function testThePeriodicityOfTheTrackedAccountIsChecked(Periodicity $periodicity): void
    {
        $declaration = self::declarationOtherThan($periodicity);
        $world = $this->worldWithMandate();
        $world->trackedAccount($periodicity);

        $thrown = $this->declareAndCatch($world, $declaration, false);

        static::assertInstanceOf(ValidationFailed::class, $thrown);
        static::assertSame(['error period declaration_period.periodicity_mismatch'], DomainAssertions::summary($thrown->result()));
        static::assertSame(
            'The declaration period does not match the periodicity of the account (method 020).',
            $thrown->result()->errors()[0]->message(),
        );
        static::assertSame([], $thrown->result()->errors()[0]->params());
        static::assertSame([], $world->journal->entries());
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->records->inner()->all());
    }

    #[DataProvider('mismatchedPeriodicities')]
    public function testThePeriodicityCheckCanBeSkipped(Periodicity $periodicity): void
    {
        $declaration = self::declarationOtherThan($periodicity);
        $world = $this->worldWithMandate();
        $world->trackedAccount($periodicity);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declareWith($world, new DeclareTurnover(DeclarationWorld::tenant(), DeclarationWorld::siret(), $declaration, false, true));

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
    }

    public function testAnUntrackedSiretIsNotCheckedAgainstAPeriodicity(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, TurnoverDeclaration::of(QuarterlyPeriod::of(2026, 3), DeclarationWorld::turnover()));

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame('T', $submission->declaration->periodicity);
        static::assertSame(3, $submission->declaration->periodNumber);
    }

    public function testAMatchingPeriodicityPasses(): void
    {
        $world = $this->worldWithMandate();
        $world->trackedAccount(Periodicity::Quarterly);
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, TurnoverDeclaration::of(QuarterlyPeriod::of(2026, 3), DeclarationWorld::turnover()));

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
    }

    public function testThePeriodicityGuardRunsBeforeTheMandateGuard(): void
    {
        $world = new DeclarationWorld();
        $world->trackedAccount(Periodicity::Quarterly);

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertInstanceOf(ValidationFailed::class, $thrown);
    }

    public function testWithoutAnActiveMandateNothingIsRecordedNorSent(): void
    {
        $world = new DeclarationWorld();
        $world->trackedAccount();

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertInstanceOf(MandateRequired::class, $thrown);
        static::assertSame('mandate.required', $thrown->violationCode());
        static::assertSame(['operation' => '050'], $thrown->params());
        static::assertSame([], $world->journal->entries());
        static::assertSame([], $world->records->inner()->all());
    }

    public function testASyncedAccountWithAMandatePresentDoesNotSatisfyTheGuard(): void
    {
        $world = new DeclarationWorld();
        $world->trackedAccount();

        static::assertInstanceOf(MandateRequired::class, $this->declareAndCatch($world, DeclarationWorld::declaration(), false));
    }

    public function testTheMandateGuardCanBeSkippedForACommand(): void
    {
        $world = new DeclarationWorld();
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declareWith($world, new DeclareTurnover(DeclarationWorld::tenant(), DeclarationWorld::siret(), DeclarationWorld::declaration(), true));

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
    }

    public function testTheMandateGuardCanBeSwitchedOffByThePolicy(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withRequireLocalMandate(false));
        $world->gateway->queue(Operation::Declare, DeclarationWorld::accepted());

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
    }

    public function testTheMandateOfAnotherSiretDoesNotCount(): void
    {
        $world = new DeclarationWorld();
        $world->activeMandate();

        $thrown = $this->declareSiretAndCatch($world, DeclarationWorld::siret('12345678901231'), DeclarationWorld::declaration(), false);

        static::assertInstanceOf(MandateRequired::class, $thrown);
    }

    // Races (ADR 0009)

    public function testTheLoserOfAFirstRecordRaceSendsNothingAndDescribesTheWinner(): void
    {
        $world = $this->worldWithMandate();
        $world->beforeNextSaveCommit(static function () use ($world): void {
            $world->records->inner()->save(self::winner('winner-1'));
        });

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('winner-1', $submission->declaration->recordId);
        static::assertSame([], $world->gateway->calls());
        static::assertSame(['begin', 'save:new-1', 'rollback'], $world->journal->entries());
        static::assertSame([], $world->publisher->names());
    }

    public function testTheLoserOfASupersessionRaceSendsNothingAndDescribesTheWinner(): void
    {
        $world = $this->worldWithMandate();
        $world->recordWith('record-1', DeclarationState::Pending, 0, DeclarationWorld::fingerprint(10, 2000), null);
        $world->beforeNextSaveCommit(static function () use ($world): void {
            $other = $world->records->get(DeclarationRecordId::fromString('record-1'));
            $other->startSubmission(Instant::fromIso8601(DeclarationWorld::NOW));
            $world->records->inner()->save($other);
        });

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('record-1', $submission->declaration->recordId);
        static::assertSame('submitting', $submission->declaration->status->state);
        static::assertNull($submission->declaration->supersededBy);
        static::assertSame([], $world->gateway->calls());
        static::assertCount(1, $world->records->inner()->all());
    }

    public function testAnotherViolationWhileRecordingIsRethrown(): void
    {
        $world = $this->worldWithMandate();
        $failure = InvariantViolation::because('storage.broken', 'The storage is broken.');
        $world->beforeNextSave(static function () use ($failure): never {
            throw $failure;
        });

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertSame($failure, $thrown);
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->records->inner()->all());
    }

    public function testAnotherViolationIsRethrownEvenWhenAWriterRecordedTheCurrentRecordMeanwhile(): void
    {
        $world = $this->worldWithMandate();
        $failure = InvariantViolation::because('storage.broken', 'The storage is broken.');
        $world->beforeNextSave(static function () use ($world, $failure): never {
            $world->transactions->outside(static function () use ($world): void {
                $world->recordIn('record-1', DeclarationState::Pending, 0);
            });

            throw $failure;
        });

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertSame($failure, $thrown);
        static::assertSame([], $world->gateway->calls());
    }

    public function testAConflictWithoutAWinnerIsRethrown(): void
    {
        $world = $this->worldWithMandate();
        $conflict = InvariantViolation::because(DeclarationRecordRepository::CONCURRENT_MODIFICATION, 'Changed.');
        $world->beforeNextSave(static function () use ($conflict): never {
            throw $conflict;
        });

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertSame($conflict, $thrown);
        static::assertSame([], $world->gateway->calls());
    }

    public function testTheLoserOfARequeueRaceSendsNothingAndDescribesTheCurrentRecord(): void
    {
        $world = $this->worldWithMandate();
        $world->recordIn('record-1', DeclarationState::Error, 1);
        $world->beforeNextSaveCommit(static function () use ($world): void {
            $other = $world->records->get(DeclarationRecordId::fromString('record-1'));
            $other->retry(Instant::fromIso8601(DeclarationWorld::NOW));
            $world->records->inner()->save($other);
        });

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('pending', $submission->declaration->status->state);
        static::assertSame([], $world->gateway->calls());
    }

    public function testARequeueOfAnErasedRecordRethrowsTheConflict(): void
    {
        $world = $this->worldWithMandate();
        $world->recordIn('record-1', DeclarationState::Error, 1);
        $world->beforeNextSaveCommit(static function () use ($world): void {
            $world->records->inner()->deleteBySiret(DeclarationWorld::tenant(), DeclarationWorld::siret(), DeclarationState::cases());
        });

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertInstanceOf(InvariantViolation::class, $thrown);
        static::assertSame(DeclarationRecordRepository::CONCURRENT_MODIFICATION, $thrown->violationCode());
        static::assertSame([], $world->gateway->calls());
    }

    public function testAnotherViolationWhileRequeuingIsRethrown(): void
    {
        $world = $this->worldWithMandate();
        $world->recordIn('record-1', DeclarationState::Error, 1);
        $failure = InvariantViolation::because('storage.broken', 'The storage is broken.');
        $world->beforeNextSave(static function () use ($failure): never {
            throw $failure;
        });

        $thrown = $this->declareAndCatch($world, DeclarationWorld::declaration(), false);

        static::assertSame($failure, $thrown);
        static::assertSame(DeclarationState::Error, $world->records->get(DeclarationRecordId::fromString('record-1'))->state());
    }

    public function testTheLoserOfTheClaimGetsNotClaimed(): void
    {
        $world = $this->worldWithMandate();
        $world->recordIn('record-1', DeclarationState::Pending, 0);
        $world->records->refuseClaim(DeclarationRecordId::fromString('record-1'));

        $submission = $this->declare($world, DeclarationWorld::declaration());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame([], $world->gateway->calls());
    }

    private function worldWithMandate(): DeclarationWorld
    {
        $world = new DeclarationWorld();
        $world->activeMandate();
        $world->journal->clear();

        return $world;
    }

    private static function winner(string $id): DeclarationRecord
    {
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString($id),
            DeclarationWorld::tenant(),
            DeclarationWorld::siret(),
            DeclarationWorld::period(),
            DeclarationWorld::fingerprint(),
            null,
            Instant::fromIso8601(DeclarationWorld::RECORDED_AT),
        );
        $record->releaseEvents();

        return $record;
    }

    private function declare(DeclarationWorld $world, TurnoverDeclaration $declaration): DeclarationSubmission
    {
        return $this->declareWith($world, new DeclareTurnover(DeclarationWorld::tenant(), DeclarationWorld::siret(), $declaration));
    }

    private function declareWith(DeclarationWorld $world, DeclareTurnover $command): DeclarationSubmission
    {
        return $world->handler()($command);
    }

    private function declareAndCatch(DeclarationWorld $world, TurnoverDeclaration $declaration, bool $skipMandate): ?Throwable
    {
        return $this->declareSiretAndCatch($world, DeclarationWorld::siret(), $declaration, $skipMandate);
    }

    private function declareSiretAndCatch(DeclarationWorld $world, Siret $siret, TurnoverDeclaration $declaration, bool $skipMandate): ?Throwable
    {
        try {
            $this->declareWith($world, new DeclareTurnover(DeclarationWorld::tenant(), $siret, $declaration, $skipMandate));
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
