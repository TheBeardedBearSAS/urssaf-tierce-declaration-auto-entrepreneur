<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Closure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountRefresher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredFailures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateRecorder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use Throwable;

/**
 * Wiring of the account-side use cases for the unit tests: journaling in-memory repositories, a transaction manager that
 * rolls back (SnapshottingTransactionManager, or the immediate one on request), a publisher and an outbox that journal
 * what they do, a frozen clock and sequential ids.
 *
 * @mago-expect lint:too-many-properties
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity One builder per state of the records of the SIRET.
 */
final readonly class AccountWorld
{
    public const NOW = '2026-11-17T09:00:00Z';
    public const SIRET = '73282932000074';
    public const OTHER_SIRET = '73282932000090';
    public const BIRTH_DATE = '1975-02-23';
    private const CREATED_AT = '2026-11-01T08:00:00Z';

    public Journal $journal;
    public FixedClock $clock;
    public SequentialIdGenerator $ids;
    public JournalingAeAccounts $accounts;
    public JournalingMandates $mandates;
    public JournalingDeclarationRecords $declarations;
    public JournalingPaymentRecords $payments;
    public FakeAccountGateway $gateway;
    public RecordingEventPublisher $publisher;
    public JournalingOutbox $outbox;
    public ?SnapshottingTransactionManager $transactions;
    public UnitOfWork $unitOfWork;

    /**
     * @param bool $transactional true: a failed unit of work is rolled back (the repositories and the outbox get their
     *                            state back, "rollback" in the journal); false: ImmediateTransactionManager (nothing is
     *                            rolled back, no "begin" nor "commit")
     */
    public function __construct(bool $transactional = true, public WorkflowPolicy $policy = new WorkflowPolicy())
    {
        $this->journal = new Journal();
        $this->clock = new FixedClock(self::NOW);
        $this->ids = new SequentialIdGenerator('id-');
        $this->accounts = new JournalingAeAccounts($this->journal);
        $this->mandates = new JournalingMandates($this->journal);
        $this->declarations = new JournalingDeclarationRecords($this->journal);
        $this->payments = new JournalingPaymentRecords($this->journal);
        $this->gateway = new FakeAccountGateway($this->journal);
        $this->publisher = new RecordingEventPublisher($this->journal);
        $this->outbox = new JournalingOutbox($this->journal);
        $this->transactions = $transactional
            ? new SnapshottingTransactionManager($this->journal, $this->accounts, $this->mandates, $this->declarations, $this->payments, $this->outbox)
            : null;
        $this->unitOfWork = new UnitOfWork($this->transactions ?? new ImmediateTransactionManager(), $this->publisher, new IgnoredFailures(), $this->outbox);
    }

    public static function tenant(string $value = 'tenant-1'): TenantId
    {
        return TenantId::fromString($value);
    }

    public static function siret(string $value = self::SIRET): Siret
    {
        return Siret::fromString($value);
    }

    public static function birthDate(string $value = self::BIRTH_DATE): CalendarDate
    {
        return CalendarDate::fromString($value);
    }

    public static function status(bool $mandatePresent = false, Periodicity $periodicity = Periodicity::Monthly): AccountStatus
    {
        return AccountStatus::of($mandatePresent, $periodicity, CalendarDate::fromString('2025-03-01'), false);
    }

    public function recorder(): MandateRecorder
    {
        return new MandateRecorder($this->mandates, $this->accounts, $this->ids, $this->clock, $this->unitOfWork);
    }

    public function locator(): AccountLocator
    {
        return new AccountLocator($this->accounts);
    }

    public function refresher(): AccountRefresher
    {
        return new AccountRefresher($this->gateway, $this->accounts, $this->clock, $this->unitOfWork);
    }

    /**
     * Saves a tracked account (no event kept) and returns it.
     */
    public function trackedAccount(
        string $id = 'account-1',
        ?string $birthDate = self::BIRTH_DATE,
        bool $mandatePresent = false,
        Periodicity $periodicity = Periodicity::Monthly,
        string $siret = self::SIRET,
    ): AeAccount {
        $account = AeAccount::track(
            AccountId::fromString($id),
            self::tenant(),
            self::siret($siret),
            self::status($mandatePresent, $periodicity),
            $birthDate === null ? null : CalendarDate::fromString($birthDate),
            self::at(self::CREATED_AT),
        );
        $account->releaseEvents();
        $this->accounts->add($account);

        return $account;
    }

    /**
     * Saves a tracked account that stores no birth date (no event kept) and returns it.
     */
    public function trackedAccountWithoutBirthDate(string $id = 'account-1'): AeAccount
    {
        return $this->trackedAccount($id, null);
    }

    /**
     * Saves an active (notified) mandate of the SIRET, not linked to an account (no event kept), and returns it.
     */
    public function activeMandate(string $id = 'mandate-1', string $siret = self::SIRET): Mandate
    {
        return $this->saved(Mandate::notify(MandateId::fromString($id), self::tenant(), self::siret($siret), null, self::at('2026-11-02T08:00:00Z')));
    }

    /**
     * Saves an active (imported) mandate linked to the account (no event kept), and returns it.
     */
    public function activeMandateOf(AeAccount $account, string $id = 'mandate-1'): Mandate
    {
        return $this->saved(Mandate::import(MandateId::fromString($id), $account->tenantId(), $account->siret(), $account->id(), self::at('2026-11-02T08:00:00Z')));
    }

    /**
     * Saves a cancelled mandate of the SIRET, not linked to an account (no event kept), and returns it.
     */
    public function cancelledMandate(string $id = 'mandate-1', string $siret = self::SIRET): Mandate
    {
        $mandate = Mandate::import(MandateId::fromString($id), self::tenant(), self::siret($siret), null, self::at('2026-11-02T08:00:00Z'));
        $mandate->cancel(self::at('2026-11-10T08:00:00Z'));

        return $this->saved($mandate);
    }

    /**
     * A declaration record of the SIRET in the given state, for the period "month of 2026" (a record per period: one
     * current record each). Not saved.
     */
    public static function declarationRecord(
        string $id,
        DeclarationState $state = DeclarationState::Pending,
        int $month = 10,
        string $siret = self::SIRET,
    ): DeclarationRecord {
        $period = MonthlyPeriod::of(2026, $month);
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString($id),
            self::tenant(),
            self::siret($siret),
            $period,
            DeclarationFingerprint::of(self::tenant(), self::siret($siret), $period, Turnover::of(Euros::of(1000), Euros::of(200), Euros::of(300), Euros::of(400), Euros::of(500))),
            null,
            self::at(self::CREATED_AT),
        );
        $now = self::at('2026-11-05T08:00:00Z');
        self::driveDeclaration($record, $state, $now);
        $record->releaseEvents();

        return $record;
    }

    /**
     * A payment record of the SIRET in the given state, for the period "month of 2026" and a reference of its own. Not
     * saved.
     */
    public static function paymentRecord(
        string $id,
        PaymentState $state = PaymentState::Pending,
        int $month = 10,
        string $siret = self::SIRET,
    ): PaymentRecord {
        $period = MonthlyPeriod::of(2026, $month);
        $order = PaymentOrder::of($period, Euros::of(1500), Iban::fromString('FR7630001007941234567890185'), Rum::fromString('T1234567891234567891234568912345678'));
        $record = PaymentRecord::record(
            PaymentRecordId::fromString($id),
            self::tenant(),
            PaymentReference::fromString('reference-' . $id),
            self::siret($siret),
            $order,
            null,
            self::at(self::CREATED_AT),
        );
        $now = self::at('2026-11-05T08:00:00Z');
        self::drivePayment($record, $state, $now);
        $record->releaseEvents();

        return $record;
    }

    /**
     * Another writer commits $writes in the middle of the running transaction: they survive its rollback (see
     * {@see SnapshottingTransactionManager::outside()}). Without a transactional manager they just run.
     *
     * @param Closure(): void $writes
     *
     * @param-immediately-invoked-callable $writes
     */
    public function outside(Closure $writes): void
    {
        if ($this->transactions instanceof SnapshottingTransactionManager) {
            $this->transactions->outside($writes);

            return;
        }
        $writes();
    }

    /**
     * Another writer changes the accounts just before the next save of the account repository reaches the store.
     *
     * @param Closure(InMemoryAeAccountRepository): void $writes
     *
     * @param-immediately-invoked-callable $writes
     */
    public function beforeNextAccountSave(Closure $writes): void
    {
        $this->accounts->beforeNextSave(function (InMemoryAeAccountRepository $inner) use ($writes): void {
            $this->outside(static fn() => $writes($inner));
        });
    }

    /**
     * Another writer changes the mandates just before the next save of the mandate repository reaches the store.
     *
     * @param Closure(InMemoryMandateRepository): void $writes
     *
     * @param-immediately-invoked-callable $writes
     */
    public function beforeNextMandateSave(Closure $writes): void
    {
        $this->mandates->beforeNextSave(function (InMemoryMandateRepository $inner) use ($writes): void {
            $this->outside(static fn() => $writes($inner));
        });
    }

    /**
     * A recorder whose unit of work fails at the end of its transaction, after the aggregates were saved (the outbox goes
     * away): the transaction is rolled back.
     */
    public function recorderWithFailingOutbox(Throwable $failure): MandateRecorder
    {
        $unitOfWork = new UnitOfWork($this->transactions ?? new ImmediateTransactionManager(), $this->publisher, new IgnoredFailures(), new FailingOutbox($failure));

        return new MandateRecorder($this->mandates, $this->accounts, $this->ids, $this->clock, $unitOfWork);
    }

    /**
     * @return list<string>
     */
    public function publishedEvents(): array
    {
        return $this->publisher->names();
    }

    public static function period(int $month = 10): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, $month);
    }

    private function saved(Mandate $mandate): Mandate
    {
        $mandate->releaseEvents();
        $this->mandates->add($mandate);

        return $mandate;
    }

    private static function at(string $iso): Instant
    {
        return Instant::fromIso8601($iso);
    }

    /**
     * @return list<SubmissionError>
     */
    private static function errors(): array
    {
        return [SubmissionError::of('urssaf.sample', 'unknown', null, null)];
    }

    private static function driveDeclaration(DeclarationRecord $record, DeclarationState $state, Instant $now): void
    {
        if ($state === DeclarationState::Pending) {
            return;
        }
        $record->startSubmission($now);
        if ($state === DeclarationState::Declared) {
            $record->markDeclared(DeclarationResult::resolved(Certificate::fromString('12345678')), $now);
        }
        if ($state === DeclarationState::Rejected) {
            $record->markRejected(self::errors(), $now);
        }
        if ($state === DeclarationState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, self::errors(), $now);
        }
        if ($state === DeclarationState::Error) {
            $record->markFailed(self::errors(), $now);
        }
        if ($state === DeclarationState::InvalidCredentials) {
            $record->markInvalidCredentials($now);
        }
    }

    private static function drivePayment(PaymentRecord $record, PaymentState $state, Instant $now): void
    {
        if ($state === PaymentState::Pending) {
            return;
        }
        $record->startSubmission($now);
        if ($state === PaymentState::Paid) {
            $record->markPaid(PaymentResult::resolved(Certificate::fromString('12345678')), $now);
        }
        if ($state === PaymentState::Rejected) {
            $record->markRejected(self::errors(), $now);
        }
        if ($state === PaymentState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, self::errors(), $now);
        }
        if ($state === PaymentState::Error) {
            $record->markFailed(self::errors(), $now);
        }
        if ($state === PaymentState::InvalidCredentials) {
            $record->markInvalidCredentials($now);
        }
    }
}
