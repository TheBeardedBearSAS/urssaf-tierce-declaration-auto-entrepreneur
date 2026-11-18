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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentAlreadyRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\RetryableUrssafFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;
use Throwable;

/**
 * PayContributions: the re-entry table of ADR 0009 (every row, same and other content), the guards of ADR 0011 and the
 * races of ADR 0009.
 *
 * @mago-expect lint:too-many-methods
 */
final class PayContributionsHandlerTest extends TestCase
{
    public function testANewPaymentIsRecordedThenSent(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame('new-1', $submission->payment->recordId);
        static::assertSame('tenant-1', $submission->payment->tenantId);
        static::assertSame('pay-1', $submission->payment->reference);
        static::assertSame('73282932000074', $submission->payment->siret);
        static::assertSame('M', $submission->payment->periodicity);
        static::assertSame(2026, $submission->payment->periodYear);
        static::assertSame(10, $submission->payment->periodNumber);
        static::assertSame(1500, $submission->payment->amount);
        static::assertSame(PaymentWorld::RUM, $submission->payment->rum);
        static::assertNull($submission->payment->supersedes);
        static::assertSame('paid', $submission->payment->status->state);
        static::assertSame(
            [
                'begin',
                'save:new-1',
                'append:',
                'commit',
                'publish:PaymentRecorded',
                'claim:new-1',
                'call:060',
                'begin',
                'save:new-1',
                'append:urssaf.tdae.payment.ordered',
                'commit',
                'publish:PaymentOrdered',
            ],
            $world->journal->entries(),
        );
        static::assertSame(['060'], $world->gateway->calls());
        static::assertSame([true], $world->gateway->ibansGiven());
    }

    public function testTheRecordKeepsTheTrackedAccountOfTheSiret(): void
    {
        $world = $this->worldWithMandate();
        $account = $world->trackedAccount('account-7');
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame($account->id()->value(), $submission->payment->accountId);
        static::assertSame('account-7', $world->payments->get(PaymentRecordId::fromString('new-1'))->accountId()?->value());
    }

    public function testTheRecordOfAnUntrackedSiretHasNoAccount(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        static::assertNull($this->pay($world, PaymentWorld::order())->payment->accountId);
    }

    public function testNoIbanIsEverStoredPublishedOrAppended(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $this->pay($world, PaymentWorld::order());

        static::assertSame([true], $world->gateway->ibansGiven());
        // Positive control: the scan finds the IBAN where it is held.
        static::assertStringContainsString(PaymentWorld::IBAN, serialize(PaymentWorld::order()));
        static::assertNotSame([], $world->payments->inner()->all());
        static::assertStringNotContainsString(PaymentWorld::IBAN, $world->everythingKept());
        static::assertStringNotContainsString('FR76', $world->everythingKept());
    }

    public function testAPaymentWithoutIbanSendsNone(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $this->pay($world, PaymentWorld::orderWithoutIban());

        static::assertSame([false], $world->gateway->ibansGiven());
    }

    public function testTheRetryableFailureOfASendIsThrownAfterTheCommit(): void
    {
        $world = $this->worldWithMandate();
        $failure = UrssafUnavailable::withStatus(Operation::Pay, 429, 30, ApiErrors::none());
        $world->gateway->queue(Operation::Pay, $failure);

        try {
            $this->pay($world, PaymentWorld::order());
            static::fail('Expected a RetryableUrssafFailure.');
        } catch (RetryableUrssafFailure $thrown) {
            static::assertSame($failure, $thrown->getPrevious());
        }
        $stored = $world->payments->get(PaymentRecordId::fromString('new-1'));
        static::assertSame(PaymentState::Pending, $stored->state());
        static::assertSame(1, $stored->attempts());
    }

    // The re-entry table (ADR 0009, section 6.1 of the design)

    /**
     * Rows that send with the same content: [state, attempts, record id sent].
     *
     * @return iterable<string, array{PaymentState, int}>
     */
    public static function sentRows(): iterable
    {
        yield 'pending' => [PaymentState::Pending, 0];
        yield 'error, attempts left' => [PaymentState::Error, 1];
    }

    #[DataProvider('sentRows')]
    public function testASameContentRowThatSendsIsSentOnTheExistingRecord(PaymentState $state, int $attempts): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', $state, $attempts);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame('record-1', $submission->payment->recordId);
        static::assertSame($attempts + 1, $submission->payment->status->attempts);
        static::assertCount(1, $world->payments->inner()->all());
        static::assertSame(['060'], $world->gateway->calls());
        static::assertSame(
            $state === PaymentState::Error ? ['PaymentRequeued', 'PaymentOrdered'] : ['PaymentOrdered'],
            $world->publisher->names(),
        );
    }

    #[DataProvider('sentRows')]
    public function testASameContentRowThatSendsRunsTheMandateGuard(PaymentState $state, int $attempts): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', $state, $attempts);

        $this->assertRefusedWith(MandateRequired::class, $world, PaymentWorld::order());

        static::assertSame($state, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
        static::assertSame($attempts, $world->payments->get(PaymentRecordId::fromString('record-1'))->attempts());
    }

    /**
     * Rows answered without any call, with the same content: [state, attempts, outcome].
     *
     * @return iterable<string, array{PaymentState, int, ItemOutcome}>
     */
    public static function answeredRows(): iterable
    {
        yield 'error, exhausted' => [PaymentState::Error, PaymentRecord::DEFAULT_MAX_ATTEMPTS, ItemOutcome::Exhausted];
        yield 'submitting' => [PaymentState::Submitting, 1, ItemOutcome::NotClaimed];
        yield 'paid' => [PaymentState::Paid, 1, ItemOutcome::Replayed];
        yield 'outcome unknown' => [PaymentState::OutcomeUnknown, 1, ItemOutcome::Replayed];
        yield 'invalid credentials' => [PaymentState::InvalidCredentials, 1, ItemOutcome::Replayed];
    }

    #[DataProvider('answeredRows')]
    public function testASameContentRowThatDoesNotSendAnswersFromTheRecordWithoutGuards(PaymentState $state, int $attempts, ItemOutcome $outcome): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', $state, $attempts);

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame($outcome, $submission->outcome);
        static::assertSame('record-1', $submission->payment->recordId);
        static::assertSame($state->value, $submission->payment->status->state);
        static::assertSame([], $world->journal->entries());
        static::assertSame([], $world->gateway->calls());
    }

    /**
     * Every state that is not submitting, with another content: [state, attempts].
     *
     * @return iterable<string, array{PaymentState, int}>
     */
    public static function refusedRows(): iterable
    {
        yield 'pending' => [PaymentState::Pending, 0];
        yield 'error, attempts left' => [PaymentState::Error, 1];
        yield 'error, exhausted' => [PaymentState::Error, PaymentRecord::DEFAULT_MAX_ATTEMPTS];
        yield 'paid' => [PaymentState::Paid, 1];
        yield 'outcome unknown' => [PaymentState::OutcomeUnknown, 1];
        yield 'invalid credentials' => [PaymentState::InvalidCredentials, 1];
    }

    /**
     * @return iterable<string, array{PaymentState, int, string}>
     */
    public static function refusedCells(): iterable
    {
        foreach (self::refusedRows() as $row => [$state, $attempts]) {
            foreach (['amount', 'rum', 'iban', 'period'] as $change) {
                yield $row . ', another ' . $change => [$state, $attempts, $change];
            }
        }
    }

    #[DataProvider('refusedCells')]
    public function testAnotherContentForTheReferenceIsRefusedWithoutCallNorGuard(PaymentState $state, int $attempts, string $change): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', $state, $attempts);

        try {
            $this->pay($world, self::changed($change));
            static::fail('Expected PaymentAlreadyRecorded.');
        } catch (PaymentAlreadyRecorded $refusal) {
            static::assertSame('payment_record.already_recorded', $refusal->violationCode());
            static::assertSame(['payment_reference' => 'pay-1', 'payment_record_id' => 'record-1'], $refusal->params());
        }
        static::assertSame([], $world->journal->entries());
        static::assertSame([], $world->gateway->calls());
        static::assertSame($state, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changes(): iterable
    {
        yield 'amount' => ['amount'];
        yield 'rum' => ['rum'];
        yield 'iban' => ['iban'];
    }

    #[DataProvider('changes')]
    public function testASubmittingRecordIsNotClaimedWhateverTheContent(string $change): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Submitting);

        foreach ([PaymentWorld::order(), self::changed($change), self::changed('period')] as $order) {
            $submission = $this->pay($world, $order);

            static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
            static::assertSame('submitting', $submission->payment->status->state);
        }
        static::assertSame([], $world->journal->entries());
    }

    #[DataProvider('changes')]
    public function testARejectedRecordIsSupersededWithAnotherContentOfTheSameSiretAndPeriod(string $change): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Rejected);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->pay($world, self::changed($change));

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame('new-1', $submission->payment->recordId);
        static::assertSame('record-1', $submission->payment->supersedes);
        static::assertSame('new-1', $world->payments->get(PaymentRecordId::fromString('record-1'))->supersededBy()?->value());
        static::assertSame(['060'], $world->gateway->calls());
        static::assertSame($change === 'iban' ? [false] : [true], $world->gateway->ibansGiven());
    }

    public function testARejectedRecordIsSupersededWithTheSameContent(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Rejected);
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame('record-1', $submission->payment->supersedes);
    }

    public function testASupersessionSavesTheSupersededRecordBeforeTheNewOneAndKeepsItsAccount(): void
    {
        $world = $this->worldWithMandate();
        $at = Instant::fromIso8601(PaymentWorld::RECORDED_AT);
        $rejected = PaymentRecord::record(
            PaymentRecordId::fromString('record-1'),
            PaymentWorld::tenant(),
            PaymentWorld::reference(),
            PaymentWorld::siret(),
            PaymentWorld::order(),
            AccountId::fromString('account-9'),
            $at,
        );
        $rejected->startSubmission($at);
        $rejected->markRejected(PaymentWorld::errors(), $at);
        $rejected->releaseEvents();
        $world->payments->add($rejected);
        $world->trackedAccount('account-1');
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->pay($world, PaymentWorld::order(1600));

        static::assertSame(
            ['begin', 'save:record-1', 'save:new-1', 'append:', 'commit', 'publish:PaymentRecorded', 'claim:new-1', 'call:060'],
            \array_slice($world->journal->entries(), 0, 8),
        );
        // The superseding record keeps the account of the superseded one (it is not looked up again).
        static::assertSame('account-9', $submission->payment->accountId);
    }

    public function testARejectedRecordReferenceStaysBoundToItsSiret(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Rejected);

        $this->assertRefusedWith(PaymentAlreadyRecorded::class, $world, PaymentWorld::order(), '12345678901234');

        static::assertNull($world->payments->get(PaymentRecordId::fromString('record-1'))->supersededBy());
    }

    public function testARejectedRecordReferenceStaysBoundToItsPeriod(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Rejected);

        $this->assertRefusedWith(PaymentAlreadyRecorded::class, $world, PaymentWorld::order(1500, PaymentWorld::RUM, 9));

        static::assertNull($world->payments->get(PaymentRecordId::fromString('record-1'))->supersededBy());
    }

    public function testARejectedRecordRunsTheMandateGuardButNotTheSecondPaymentGuard(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Rejected);
        $world->paymentWith('other', PaymentState::Paid, 1, PaymentWorld::order(), 'other-ref', PaymentWorld::SIRET, 'tenant-1');

        $this->assertRefusedWith(MandateRequired::class, $world, PaymentWorld::order());

        $world->activeMandate();
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());
        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame('record-1', $submission->payment->supersedes);
    }

    // The guards (ADR 0011)

    /**
     * @return iterable<string, array{PaymentState}>
     */
    public static function blockingStates(): iterable
    {
        yield 'submitting' => [PaymentState::Submitting];
        yield 'paid' => [PaymentState::Paid];
        yield 'outcome unknown' => [PaymentState::OutcomeUnknown];
    }

    /**
     * @return iterable<string, array{PaymentState}>
     */
    public static function nonBlockingStates(): iterable
    {
        yield 'pending' => [PaymentState::Pending];
        yield 'rejected' => [PaymentState::Rejected];
        yield 'error' => [PaymentState::Error];
        yield 'invalid credentials' => [PaymentState::InvalidCredentials];
    }

    /**
     * @return iterable<string, array{PaymentState}>
     */
    public static function periodStates(): iterable
    {
        yield from self::blockingStates();
        yield from self::nonBlockingStates();
    }

    #[DataProvider('blockingStates')]
    public function testANewReferenceForAPeriodThatHasABlockingPaymentIsRefused(PaymentState $state): void
    {
        $world = $this->worldWithMandate();
        $world->paymentWith('other', $state, 1, PaymentWorld::order(), 'other-ref', PaymentWorld::SIRET, 'tenant-1');
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $violation = $this->refusedWith(ValidationFailed::class, $world, PaymentWorld::order());

        $errors = $violation->result()->errors();
        static::assertCount(1, $errors);
        static::assertSame('payment.period_already_paid', $errors[0]->code());
        static::assertSame('period', $errors[0]->path());
        static::assertSame([], $errors[0]->params());
        static::assertCount(1, $world->payments->inner()->all());
    }

    #[DataProvider('nonBlockingStates')]
    public function testANewReferenceForAPeriodThatHasANonBlockingPaymentIsSent(PaymentState $state): void
    {
        $world = $this->worldWithMandate();
        $world->paymentWith('other', $state, 1, PaymentWorld::order(), 'other-ref', PaymentWorld::SIRET, 'tenant-1');
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        static::assertSame(ItemOutcome::Paid, $this->pay($world, PaymentWorld::order())->outcome);
    }
    #[DataProvider('periodStates')]
    public function testAllowAdditionalPaymentLetsAnIntendedSecondPaymentThrough(PaymentState $state): void
    {
        $world = $this->worldWithMandate();
        $world->paymentWith('other', $state, 1, PaymentWorld::order(), 'other-ref', PaymentWorld::SIRET, 'tenant-1');
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->handle($world, new PayContributions(
            PaymentWorld::tenant(),
            PaymentWorld::reference(),
            PaymentWorld::siret(),
            PaymentWorld::order(),
            false,
            true,
        ));

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
    }

    public function testAnotherPeriodAnotherSiretAndAnotherTenantAreNotTheSamePeriod(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentWith('month-9', PaymentState::Paid, 1, PaymentWorld::order(1500, PaymentWorld::RUM, 9), 'ref-9', PaymentWorld::SIRET, 'tenant-1');
        $world->paymentWith('siret', PaymentState::Paid, 1, PaymentWorld::order(), 'ref-s', '12345678901234', 'tenant-1');
        $world->paymentWith('tenant', PaymentState::Paid, 1, PaymentWorld::order(), 'ref-t', PaymentWorld::SIRET, 'tenant-2');
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        static::assertSame(ItemOutcome::Paid, $this->pay($world, PaymentWorld::order())->outcome);
    }

    public function testASupersededPaymentDoesNotBlockItsPeriod(): void
    {
        $world = $this->worldWithMandate();
        $rejected = $world->paymentIn('old', PaymentState::Rejected);
        $new = $rejected->supersede(PaymentRecordId::fromString('newer'), PaymentWorld::order(), Instant::fromIso8601(PaymentWorld::RECORDED_AT));
        $new->startSubmission(Instant::fromIso8601(PaymentWorld::RECORDED_AT));
        $new->markPaid(PaymentWorld::paid(), Instant::fromIso8601(PaymentWorld::RECORDED_AT));
        $world->payments->add($rejected, $new);

        // The current record of the period is paid: another reference is refused.
        $this->assertRefusedWith(ValidationFailed::class, $world, PaymentWorld::order(), PaymentWorld::SIRET, 'another-ref');
    }

    public function testTheSecondPaymentGuardRunsBeforeTheMandateGuard(): void
    {
        $world = new PaymentWorld();
        $world->paymentWith('other', PaymentState::Paid, 1, PaymentWorld::order(), 'other-ref', PaymentWorld::SIRET, 'tenant-1');

        $this->assertRefusedWith(ValidationFailed::class, $world, PaymentWorld::order());
    }

    public function testANewPaymentWithoutMandateIsRefusedBeforeAnyRecord(): void
    {
        $world = new PaymentWorld();

        $refusal = $this->refusedWith(MandateRequired::class, $world, PaymentWorld::order());

        static::assertSame('mandate.required', $refusal->violationCode());
        static::assertSame(['operation' => '060'], $refusal->params());
        static::assertSame([], $world->payments->inner()->all());
    }

    public function testASkippedMandateCheckSendsWithoutMandate(): void
    {
        $world = new PaymentWorld();
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        $submission = $this->handle($world, new PayContributions(
            PaymentWorld::tenant(),
            PaymentWorld::reference(),
            PaymentWorld::siret(),
            PaymentWorld::order(),
            true,
        ));

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
    }

    public function testThePolicyCanSwitchTheMandateGuardOff(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withRequireLocalMandate(false));
        $world->gateway->queue(Operation::Pay, PaymentWorld::paid());

        static::assertSame(ItemOutcome::Paid, $this->pay($world, PaymentWorld::order())->outcome);
    }

    public function testASyncedAccountWithAMandateOnTheUrssafSideDoesNotSatisfyTheGuard(): void
    {
        $world = new PaymentWorld();
        $world->trackedAccount();

        $this->assertRefusedWith(MandateRequired::class, $world, PaymentWorld::order());
    }

    public function testNotSentAndExhaustedFailuresFollowTheSubmitterTable(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(1));
        $world->activeMandate();
        $world->gateway->queue(Operation::Pay, UrssafUnavailable::withStatus(Operation::Pay, 429, null, ApiErrors::none()));

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::Exhausted, $submission->outcome);
        static::assertSame('error', $submission->payment->status->state);
        // Exhausted: the same content is answered without a call.
        static::assertSame(ItemOutcome::Exhausted, $this->pay($world, PaymentWorld::order())->outcome);
        static::assertSame(['060'], $world->gateway->calls());
    }

    public function testARejectionIsFinalThenSupersededByTheNextCall(): void
    {
        $world = $this->worldWithMandate();
        $world->gateway->queue(
            Operation::Pay,
            UrssafRequestRejected::withErrors(Operation::Pay, 400, ApiErrors::of(ApiError::of('ER_API_060_0070', null, null)), RejectionKind::InvalidBankDetails),
            PaymentWorld::paid(),
        );

        $first = $this->pay($world, PaymentWorld::order());
        $second = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::Rejected, $first->outcome);
        static::assertSame(ItemOutcome::Paid, $second->outcome);
        static::assertSame('new-1', $second->payment->supersedes);
    }

    // Races (ADR 0009)

    public function testALostClaimIsNotClaimedWithTheWinnerRecord(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Pending, 0);
        $world->payments->refuseClaim(PaymentRecordId::fromString('record-1'));

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame([], $world->gateway->calls());
    }

    public function testAConcurrentFirstRecordGivesTheWinnerAndSendsNothing(): void
    {
        $world = $this->worldWithMandate();
        $world->otherWriterBeforeNextSave(static function () use ($world): void {
            $world->paymentIn('winner', PaymentState::Pending, 0);
        });

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('winner', $submission->payment->recordId);
        static::assertCount(1, $world->payments->inner()->all());
        static::assertSame([], $world->gateway->calls());
        static::assertContains('rollback', $world->journal->entries());
    }

    public function testASupersessionConflictGivesTheWinnerAndSendsNothing(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Rejected);
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $other = $inner->get(PaymentRecordId::fromString('record-1'));
            $other->markPaid(PaymentWorld::paid(), Instant::fromIso8601(PaymentWorld::NOW));
            $inner->save($other);
        });

        $submission = $this->pay($world, PaymentWorld::order(1600));

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('record-1', $submission->payment->recordId);
        static::assertSame('paid', $submission->payment->status->state);
        static::assertSame([], $world->gateway->calls());
        static::assertCount(1, $world->payments->inner()->all());
    }

    public function testASupersessionConflictWithoutWinnerIsRethrown(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Rejected);
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $inner->deleteBySiret(PaymentWorld::tenant(), PaymentWorld::siret(), [PaymentState::Rejected]);
        });

        $violation = $this->refusedWith(InvariantViolation::class, $world, PaymentWorld::order(1600));

        static::assertSame(PaymentRecordRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        static::assertSame([], $world->payments->inner()->all());
    }

    public function testAnotherViolationIsRethrownEvenWhenAWriterRecordedThePaymentMeanwhile(): void
    {
        $world = $this->worldWithMandate();
        $world->otherWriterBeforeNextSave(static function () use ($world): never {
            $world->paymentIn('winner', PaymentState::Pending, 0);

            throw InvariantViolation::because('storage.broken', 'The storage is broken.');
        });

        $violation = $this->refusedWith(InvariantViolation::class, $world, PaymentWorld::order());

        static::assertSame('storage.broken', $violation->violationCode());
        static::assertSame([], $world->gateway->calls());
    }

    public function testAnotherViolationWhileRecordingIsRethrown(): void
    {
        $world = $this->worldWithMandate();
        $world->payments->beforeNextSave(static function (): never {
            throw InvariantViolation::because('storage.broken', 'The storage is broken.');
        });

        $violation = $this->refusedWith(InvariantViolation::class, $world, PaymentWorld::order());

        static::assertSame('storage.broken', $violation->violationCode());
        static::assertSame([], $world->payments->inner()->all());
    }

    public function testARequeueConflictGivesTheCurrentRecordAndSendsNothing(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Error, 1);
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $other = $inner->get(PaymentRecordId::fromString('record-1'));
            $other->markPaid(PaymentWorld::paid(), Instant::fromIso8601(PaymentWorld::NOW));
            $inner->save($other);
        });

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('paid', $submission->payment->status->state);
        static::assertSame([], $world->gateway->calls());
    }

    public function testARequeueConflictOnARecordRequeuedMeanwhileDescribesItWithoutSending(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Error, 1);
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $other = $inner->get(PaymentRecordId::fromString('record-1'));
            $other->retry(Instant::fromIso8601(PaymentWorld::NOW), (new WorkflowPolicy())->maxSubmissionAttempts());
            $inner->save($other);
        });

        $submission = $this->pay($world, PaymentWorld::order());

        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
        static::assertSame('pending', $submission->payment->status->state);
        static::assertSame([], $world->gateway->calls());
    }

    public function testARequeueConflictOnAnErasedRecordRethrowsTheConflict(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Error, 1);
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $inner->deleteBySiret(PaymentWorld::tenant(), PaymentWorld::siret(), [PaymentState::Error]);
        });

        $violation = $this->refusedWith(InvariantViolation::class, $world, PaymentWorld::order());

        static::assertSame(PaymentRecordRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        static::assertSame([], $world->gateway->calls());
    }

    public function testAnotherViolationWhileRequeueingIsRethrown(): void
    {
        $world = $this->worldWithMandate();
        $world->paymentIn('record-1', PaymentState::Error, 1);
        $world->payments->beforeNextSave(static function (): never {
            throw InvariantViolation::because('storage.broken', 'The storage is broken.');
        });

        $violation = $this->refusedWith(InvariantViolation::class, $world, PaymentWorld::order());

        static::assertSame('storage.broken', $violation->violationCode());
        static::assertSame(1, $world->payments->get(PaymentRecordId::fromString('record-1'))->attempts());
    }

    // Helpers

    private function worldWithMandate(): PaymentWorld
    {
        $world = new PaymentWorld();
        $world->activeMandate();

        return $world;
    }

    private static function changed(string $change): PaymentOrder
    {
        return match ($change) {
            'amount' => PaymentWorld::order(1501),
            'rum' => PaymentWorld::order(1500, null),
            'iban' => PaymentWorld::orderWithoutIban(),
            default => PaymentWorld::order(1500, PaymentWorld::RUM, 9),
        };
    }

    private function pay(PaymentWorld $world, PaymentOrder $order): PaymentSubmission
    {
        return $this->handle($world, new PayContributions(PaymentWorld::tenant(), PaymentWorld::reference(), PaymentWorld::siret(), $order));
    }

    private function handle(PaymentWorld $world, PayContributions $command): PaymentSubmission
    {
        return $world->handler()($command);
    }

    /**
     * Asserts that the call is refused with the exception, before any call, journal entry or record created.
     *
     * @param class-string<Throwable> $expected
     */
    private function assertRefusedWith(
        string $expected,
        PaymentWorld $world,
        PaymentOrder $order,
        string $siret = PaymentWorld::SIRET,
        string $reference = 'pay-1',
    ): void {
        $before = \count($world->payments->inner()->all());

        static::assertInstanceOf($expected, $this->refusedWith($expected, $world, $order, $siret, $reference));
        static::assertCount($before, $world->payments->inner()->all());
    }

    /**
     * Same as {@see self::assertRefusedWith()}, without the check of the stored records, returning the exception.
     *
     * @template T of Throwable
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private function refusedWith(
        string $expected,
        PaymentWorld $world,
        PaymentOrder $order,
        string $siret = PaymentWorld::SIRET,
        string $reference = 'pay-1',
    ): Throwable {
        try {
            $world->handler()(new PayContributions(PaymentWorld::tenant(), PaymentWorld::reference($reference), PaymentWorld::siret($siret), $order));
        } catch (Throwable $failure) {
            static::assertInstanceOf($expected, $failure);
            static::assertSame([], $world->gateway->calls());

            return $failure;
        }
        static::fail('Expected ' . $expected . '.');
    }
}
