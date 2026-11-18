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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateGuard;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;

/**
 * Wiring of the payment use cases for the unit tests: in-memory repositories (the payment and declaration ones journal),
 * a transactional manager that really rolls back (SnapshottingTransactionManager), a publisher and an outbox that journal
 * what they do, a scripted gateway, a frozen clock and sequential ids. Time: NOW (2026-11-17T09:00:00Z).
 *
 * One property per collaborator and one builder per aggregate, hence the property and method counts.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:too-many-properties
 */
final readonly class PaymentWorld
{
    public const NOW = '2026-11-17T09:00:00Z';
    public const RECORDED_AT = '2026-11-17T08:00:00Z';
    public const SIRET = '73282932000074';
    public const IBAN = 'FR7630001007941234567890185';
    public const RUM = 'T1234567891234567891234568912345678';

    public Journal $journal;
    public FixedClock $clock;
    public SequentialIdGenerator $ids;
    public JournalingPaymentRecords $payments;
    public JournalingDeclarationRecords $declarations;
    public InMemoryAeAccountRepository $accounts;
    public InMemoryMandateRepository $mandates;
    public FakePaymentGateway $gateway;
    public RecordingEventPublisher $publisher;
    public JournalingOutbox $outbox;
    public SnapshottingTransactionManager $transactions;
    public UnitOfWork $unitOfWork;
    public PaymentLocator $locator;

    /**
     * @param bool $transactional true: a failed unit of work is rolled back (the repositories and the outbox get their
     *                            state back, "rollback" in the journal); false: the unit of work runs on
     *                            ImmediateTransactionManager, like the in-memory persistence of the builder (nothing is
     *                            rolled back, no "begin" nor "commit")
     */
    public function __construct(
        public WorkflowPolicy $policy = new WorkflowPolicy(),
        bool $transactional = true,
    ) {
        $this->journal = new Journal();
        $this->clock = new FixedClock(self::NOW);
        $this->ids = new SequentialIdGenerator('new-');
        $this->payments = new JournalingPaymentRecords($this->journal);
        $this->declarations = new JournalingDeclarationRecords($this->journal);
        $this->accounts = new InMemoryAeAccountRepository();
        $this->mandates = new InMemoryMandateRepository();
        $this->gateway = new FakePaymentGateway($this->journal);
        $this->publisher = new RecordingEventPublisher($this->journal);
        $this->outbox = new JournalingOutbox($this->journal);
        $this->transactions = new SnapshottingTransactionManager(
            $this->journal,
            $this->payments->inner(),
            $this->declarations->inner(),
            $this->accounts,
            $this->mandates,
            $this->outbox,
        );
        $this->unitOfWork = new UnitOfWork(
            $transactional ? $this->transactions : new ImmediateTransactionManager(),
            $this->publisher,
            new RecordingFailureReporter(),
            $this->outbox,
        );
        $this->locator = new PaymentLocator($this->payments);
    }

    public static function tenant(string $value = 'tenant-1'): TenantId
    {
        return TenantId::fromString($value);
    }

    public static function siret(string $value = self::SIRET): Siret
    {
        return Siret::fromString($value);
    }

    public static function reference(string $value = 'pay-1'): PaymentReference
    {
        return PaymentReference::fromString($value);
    }

    public static function period(int $month = 10): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, $month);
    }

    public static function order(int $amount = 1500, ?string $rum = self::RUM, int $month = 10): PaymentOrder
    {
        return PaymentOrder::of(
            self::period($month),
            Euros::of($amount),
            Iban::fromString(self::IBAN),
            $rum === null ? null : Rum::fromString($rum),
        );
    }

    /**
     * The sample order without IBAN (the account holder's bank details are already known to URSSAF).
     */
    public static function orderWithoutIban(int $amount = 1500, ?string $rum = self::RUM, int $month = 10): PaymentOrder
    {
        return PaymentOrder::of(self::period($month), Euros::of($amount), null, $rum === null ? null : Rum::fromString($rum));
    }

    /**
     * The answer of an accepted 060.
     */
    public static function paid(string $certificat = '12345678', ?string $id = 'W1dl9W2Oa4R6ab3UlQAqxF2'): PaymentResult
    {
        return PaymentResult::of(Certificate::fromString($certificat), $id, CalendarDate::fromString('2026-11-20'), self::period());
    }

    /**
     * @return list<SubmissionError>
     */
    public static function errors(): array
    {
        return [SubmissionError::of('ER_API_060_0070', 'unknown', 'Message', 'Description')];
    }

    public function submitter(): PaymentSubmitter
    {
        return $this->submitterWith($this->unitOfWork);
    }

    public function submitterWith(UnitOfWork $unitOfWork): PaymentSubmitter
    {
        return new PaymentSubmitter($this->payments, $this->gateway, $this->clock, $unitOfWork, $this->policy);
    }

    public function guard(): MandateGuard
    {
        return new MandateGuard($this->mandates, $this->policy);
    }

    public function handler(): PayContributionsHandler
    {
        return $this->handlerWith($this->unitOfWork);
    }

    public function handlerWith(UnitOfWork $unitOfWork): PayContributionsHandler
    {
        return new PayContributionsHandler(
            $this->payments,
            $this->accounts,
            $this->submitterWith($unitOfWork),
            $this->guard(),
            $this->ids,
            $this->clock,
            $unitOfWork,
            $this->policy,
        );
    }

    /**
     * Another writer changes the payment store just before the next save reaches it, as a committed write of its own: it
     * survives the rollback of the transaction that is saving (a plain intrusion would be rolled back with it).
     *
     * @param Closure(InMemoryPaymentRecordRepository): void $writes
     *
     * @param-immediately-invoked-callable $writes
     */
    public function otherWriterBeforeNextSave(Closure $writes): void
    {
        $this->payments->beforeNextSave(function (InMemoryPaymentRecordRepository $inner) use ($writes): void {
            $this->transactions->outside(static fn() => $writes($inner));
        });
    }

    /**
     * Saves a tracked account of the SIRET (no event kept) and returns it.
     */
    public function trackedAccount(string $id = 'account-1'): AeAccount
    {
        $account = AeAccount::track(
            AccountId::fromString($id),
            self::tenant(),
            self::siret(),
            AccountStatus::of(false, Periodicity::Monthly, CalendarDate::fromString('2019-12-01'), false),
            null,
            Instant::fromIso8601(self::RECORDED_AT),
        );
        $account->releaseEvents();
        $this->accounts->save($account);

        return $account;
    }

    /**
     * Saves an active local mandate of the SIRET and returns it.
     */
    public function activeMandate(string $id = 'mandate-1'): Mandate
    {
        $mandate = Mandate::notify(
            MandateId::fromString($id),
            self::tenant(),
            self::siret(),
            null,
            Instant::fromIso8601(self::RECORDED_AT),
        );
        $mandate->releaseEvents();
        $this->mandates->save($mandate);

        return $mandate;
    }

    /**
     * Payment record $id (reference "pay-1", the sample order, SIRET 73282932000074, tenant-1) driven through the public
     * transitions to the state, with $attempts submission attempts (at least 1 for every state but pending), stored,
     * events released.
     */
    public function paymentIn(string $id, PaymentState $state, int $attempts = 1): PaymentRecord
    {
        return $this->paymentWith($id, $state, $attempts, self::order(), 'pay-1', self::SIRET, 'tenant-1');
    }

    /**
     * Same as {@see self::paymentIn()} with the order, the reference, the SIRET and the tenant given.
     *
     * @mago-expect lint:excessive-parameter-list
     */
    public function paymentWith(
        string $id,
        PaymentState $state,
        int $attempts,
        PaymentOrder $order,
        string $reference,
        string $siret,
        string $tenant,
    ): PaymentRecord {
        $at = Instant::fromIso8601(self::RECORDED_AT);
        $record = PaymentRecord::record(
            PaymentRecordId::fromString($id),
            self::tenant($tenant),
            self::reference($reference),
            self::siret($siret),
            $order,
            null,
            $at,
        );
        if ($state !== PaymentState::Pending) {
            for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
                $record->startSubmission($at);
                if ($attempt < $attempts) {
                    $record->markFailed([], $at);
                    $record->retry($at, max(1, $attempts));
                }
            }
            self::finish($record, $state, $at);
        }
        $record->releaseEvents();
        $this->payments->add($record);

        return $record;
    }

    /**
     * A declaration record of the SIRET (period 2026-10 by default, tenant-1) driven to the state, stored, events released.
     */
    public function declarationIn(string $id, DeclarationState $state, string $tenant = 'tenant-1', int $month = 10): DeclarationRecord
    {
        $at = Instant::fromIso8601(self::RECORDED_AT);
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString($id),
            self::tenant($tenant),
            self::siret(),
            self::period($month),
            DeclarationFingerprint::of(
                self::tenant($tenant),
                self::siret(),
                self::period($month),
                Turnover::of(Euros::of(1000), null, Euros::of(200), null, null),
            ),
            null,
            $at,
        );
        if ($state !== DeclarationState::Pending) {
            $record->startSubmission($at);
        }
        if ($state === DeclarationState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, [], $at);
        }
        if ($state === DeclarationState::InvalidCredentials) {
            $record->markInvalidCredentials($at);
        }
        $record->releaseEvents();
        $this->declarations->add($record);

        return $record;
    }

    /**
     * What is stored, published and appended, serialised: to look for data that must never be kept (the IBAN).
     */
    public function everythingKept(): string
    {
        return serialize([
            $this->payments->inner()->all(),
            $this->declarations->inner()->all(),
            $this->publisher->events(),
            $this->outbox->appended(),
        ]);
    }

    private static function finish(PaymentRecord $record, PaymentState $state, Instant $at): void
    {
        if ($state === PaymentState::Paid) {
            $record->markPaid(self::paid(), $at);
        }
        if ($state === PaymentState::Rejected) {
            $record->markRejected(self::errors(), $at);
        }
        if ($state === PaymentState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, [], $at);
        }
        if ($state === PaymentState::Error) {
            $record->markFailed(self::errors(), $at);
        }
        if ($state === PaymentState::InvalidCredentials) {
            $record->markInvalidCredentials($at);
        }
    }
}
