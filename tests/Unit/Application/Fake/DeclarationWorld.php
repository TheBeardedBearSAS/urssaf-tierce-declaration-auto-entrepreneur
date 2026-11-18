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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnoverHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateGuard;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;

/**
 * Wiring of the declaration use cases for the unit tests: in-memory repositories (the declaration one journals), a
 * transactional manager that really rolls back (SnapshottingTransactionManager), a publisher and an outbox that journal
 * what they do, a scripted gateway, a frozen clock and sequential ids. Time: NOW (2026-11-17T09:00:00Z).
 *
 * One property per collaborator and one builder per aggregate, hence the property and method counts.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:too-many-properties
 */
final readonly class DeclarationWorld
{
    public const NOW = '2026-11-17T09:00:00Z';
    public const RECORDED_AT = '2026-11-17T08:00:00Z';
    public const SIRET = '73282932000074';
    public const BIRTH_DATE = '1975-02-23';

    public Journal $journal;
    public FixedClock $clock;
    public SequentialIdGenerator $ids;
    public JournalingDeclarationRecords $records;
    public InMemoryAeAccountRepository $accounts;
    public InMemoryMandateRepository $mandates;
    public FakeContributionGateway $gateway;
    public RecordingEventPublisher $publisher;
    public JournalingOutbox $outbox;
    public SnapshottingTransactionManager $transactions;
    public UnitOfWork $unitOfWork;
    public DeclarationLocator $locator;

    /**
     * @param bool $transactional true: a failed unit of work is rolled back (the repositories and the outbox get their
     *                            state back, "rollback" in the journal); false: the unit of work runs on
     *                            ImmediateTransactionManager, like the in-memory persistence of the builder (nothing is
     *                            rolled back, no "begin" nor "commit"; $transactions is then unused)
     */
    public function __construct(public WorkflowPolicy $policy = new WorkflowPolicy(), bool $transactional = true)
    {
        $this->journal = new Journal();
        $this->clock = new FixedClock(self::NOW);
        $this->ids = new SequentialIdGenerator('new-');
        $this->records = new JournalingDeclarationRecords($this->journal);
        $this->accounts = new InMemoryAeAccountRepository();
        $this->mandates = new InMemoryMandateRepository();
        $this->gateway = new FakeContributionGateway($this->journal);
        $this->publisher = new RecordingEventPublisher($this->journal);
        $this->outbox = new JournalingOutbox($this->journal);
        $this->transactions = new SnapshottingTransactionManager(
            $this->journal,
            $this->records->inner(),
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
        $this->locator = new DeclarationLocator($this->records);
    }

    public static function tenant(string $value = 'tenant-1'): TenantId
    {
        return TenantId::fromString($value);
    }

    public static function siret(string $value = self::SIRET): Siret
    {
        return Siret::fromString($value);
    }

    public static function period(int $month = 10): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, $month);
    }

    public static function turnover(?int $sales = 1000): Turnover
    {
        return Turnover::of($sales === null ? null : Euros::of($sales), null, Euros::of(200), null, null);
    }

    public static function declaration(int $month = 10, ?int $sales = 1000): TurnoverDeclaration
    {
        return TurnoverDeclaration::of(self::period($month), self::turnover($sales));
    }

    public static function fingerprint(int $month = 10, ?int $sales = 1000, string $siret = self::SIRET, string $tenant = 'tenant-1'): DeclarationFingerprint
    {
        return DeclarationFingerprint::of(self::tenant($tenant), self::siret($siret), self::period($month), self::turnover($sales));
    }

    public static function amounts(): ContributionAmounts
    {
        return ContributionAmounts::of(
            Euros::of(100),
            Euros::of(60),
            null,
            Euros::of(40),
            null,
            null,
            null,
            LiberatoryContributions::none(),
            ChamberFees::none(),
            null,
            null,
        );
    }

    /**
     * The answer of an accepted 050.
     */
    public static function accepted(string $certificat = '12345678', string $id = 'W1dl9W2Oa4R6ab3UlQAqxF3'): DeclarationResult
    {
        return DeclarationResult::of(Certificate::fromString($certificat), $id, CalendarDate::fromString('2026-11-20'), self::amounts());
    }

    /**
     * @return list<SubmissionError>
     */
    public static function errors(): array
    {
        return [SubmissionError::of('ER_API_050_0070', 'unknown', 'Message', 'Description')];
    }

    /**
     * Runs $intrusion once, inside the transaction of the next save of a declaration record, just before the save reaches
     * the store (what it writes is part of the transaction: a rollback undoes it).
     *
     * @param Closure(): void $intrusion
     * @param-immediately-invoked-callable $intrusion
     */
    public function beforeNextSave(Closure $intrusion): void
    {
        $this->records->beforeNextSave(static function () use ($intrusion): void {
            $intrusion();
        });
    }

    /**
     * Same as {@see self::beforeNextSave()} for another process that commits in the middle of the transaction: what
     * $writes does survives the rollback of the running transaction.
     *
     * @param Closure(): void $writes
     * @param-immediately-invoked-callable $writes
     */
    public function beforeNextSaveCommit(Closure $writes): void
    {
        $this->beforeNextSave(function () use ($writes): void {
            $this->transactions->outside($writes);
        });
    }

    public function submitter(): DeclarationSubmitter
    {
        return new DeclarationSubmitter($this->records, $this->gateway, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function guard(): MandateGuard
    {
        return new MandateGuard($this->mandates, $this->policy);
    }

    public function handler(): DeclareTurnoverHandler
    {
        return $this->handlerWith($this->unitOfWork);
    }

    public function handlerWith(UnitOfWork $unitOfWork): DeclareTurnoverHandler
    {
        return new DeclareTurnoverHandler(
            $this->records,
            $this->accounts,
            new DeclarationSubmitter($this->records, $this->gateway, $this->clock, $unitOfWork, $this->policy),
            $this->guard(),
            $this->ids,
            $this->clock,
            $unitOfWork,
            $this->policy,
        );
    }

    /**
     * Saves a tracked account of the SIRET (no event kept) and returns it.
     */
    public function trackedAccount(Periodicity $periodicity = Periodicity::Monthly, string $id = 'account-1'): AeAccount
    {
        $account = AeAccount::track(
            AccountId::fromString($id),
            self::tenant(),
            self::siret(),
            AccountStatus::of(false, $periodicity, CalendarDate::fromString('2019-12-01'), false),
            CalendarDate::fromString(self::BIRTH_DATE),
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
     * Record $id (period 2026-10 for the sample declaration, SIRET 73282932000074, tenant-1) driven through the public
     * transitions to the state, with $attempts submission attempts (at least 1 for every state but pending), stored,
     * events released. It is the current record of its period.
     */
    public function recordIn(string $id, DeclarationState $state, int $attempts = 1): DeclarationRecord
    {
        return $this->recordWith($id, $state, $attempts, self::fingerprint(), null);
    }

    /**
     * Same as {@see self::recordIn()} with the fingerprint and the tracked account given.
     */
    public function recordWith(
        string $id,
        DeclarationState $state,
        int $attempts,
        DeclarationFingerprint $fingerprint,
        ?AccountId $accountId,
    ): DeclarationRecord {
        $at = Instant::fromIso8601(self::RECORDED_AT);
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString($id),
            self::tenant(),
            self::siret(),
            self::period(),
            $fingerprint,
            $accountId,
            $at,
        );
        if ($state !== DeclarationState::Pending) {
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
        $this->records->add($record);

        return $record;
    }

    private static function finish(DeclarationRecord $record, DeclarationState $state, Instant $at): void
    {
        if ($state === DeclarationState::Declared) {
            $record->markDeclared(self::accepted(), $at);
        }
        if ($state === DeclarationState::Rejected) {
            $record->markRejected(self::errors(), $at);
        }
        if ($state === DeclarationState::OutcomeUnknown) {
            $record->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, [], $at);
        }
        if ($state === DeclarationState::Error) {
            $record->markFailed(self::errors(), $at);
        }
        if ($state === DeclarationState::InvalidCredentials) {
            $record->markInvalidCredentials($at);
        }
    }
}
