<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment\PaymentSamples;

/**
 * Behaviour every {@see PaymentRecordRepository} must have, independently of the storage. Identity-related behaviour
 * (copies versus managed instances) is deliberately not part of the contract. One method per scenario.
 *
 * A supersession (a rejected payment ordered again under the same reference) saves the superseded record first, then
 * the new one, in one transaction (the order the Doctrine adapter needs, ADR 0016).
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 * @mago-expect lint:too-many-methods
 */
trait PaymentRecordRepositoryContract
{
    protected PaymentRecordRepository $repository;

    abstract protected function createRepository(): PaymentRecordRepository;

    /**
     * An independent writer over the same store: another process, another EntityManager. The in-memory adapter has one
     * store per repository and returns the repository itself. Competing copies are read, and saved, through it.
     */
    abstract protected function anotherWriter(): PaymentRecordRepository;

    /**
     * The transaction manager of the storage (the supersession saves two records in one transaction).
     */
    abstract protected function transactions(): TransactionManager;

    protected function setUp(): void
    {
        $this->repository = $this->createRepository();
    }

    /**
     * @param list<PaymentRecord> $records
     *
     * @return list<string>
     */
    protected static function recordIds(array $records): array
    {
        return array_map(static fn(PaymentRecord $record): string => $record->id()->value(), $records);
    }

    /**
     * @param list<PaymentRecordId> $ids
     *
     * @return list<string>
     */
    protected static function idValues(array $ids): array
    {
        return array_map(static fn(PaymentRecordId $id): string => $id->value(), $ids);
    }

    public function testAPendingPaymentIsReadBack(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));

        $read = $this->repository->get(PaymentRecordId::fromString('payment-1'));

        static::assertSame('payment-1', $read->id()->value());
        static::assertSame('tenant-1', $read->tenantId()->value());
        static::assertSame('payment-ref-1', $read->reference()->value());
        static::assertTrue($read->siret()->equals(ContractSamples::siret(1)));
        static::assertSame(1500, $read->amount()->toInt());
        static::assertSame(PaymentSamples::rum()->value(), $read->rum()?->value());
        static::assertTrue($read->period()->equals(ContractSamples::monthly()));
        static::assertSame(Periodicity::Monthly, $read->periodicity());
        static::assertTrue($read->fingerprint()->equals(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly())->fingerprint()));
        static::assertSame('account-1', $read->accountId()?->value());
        static::assertNull($read->supersedes());
        static::assertNull($read->supersededBy());
        static::assertTrue($read->isCurrent());
        static::assertSame(PaymentState::Pending, $read->state());
        static::assertSame(0, $read->attempts());
        static::assertNull($read->lastAttemptAt());
        static::assertSame([], $read->errors());
        static::assertNull($read->result());
        static::assertNull($read->outcomeUnknownReason());
        static::assertSame('2026-11-10T09:00:00Z', $read->createdAt()->toIso8601());
        static::assertSame('2026-11-10T09:00:00Z', $read->updatedAt()->toIso8601());
    }

    public function testAQuarterlyPaymentWithoutAccountNorRumIsReadBack(): void
    {
        $record = PaymentRecord::record(
            PaymentRecordId::fromString('payment-1'),
            ContractSamples::tenant(),
            PaymentReference::fromString('payment-ref-1'),
            ContractSamples::siret(1),
            PaymentOrder::of(ContractSamples::quarterly(), Euros::of(0), null, null),
            null,
            ContractSamples::at(),
        );
        $this->repository->save($record);

        $read = $this->repository->get($record->id());

        static::assertSame(Periodicity::Quarterly, $read->periodicity());
        static::assertTrue($read->period()->equals(ContractSamples::quarterly()));
        static::assertSame(0, $read->amount()->toInt());
        static::assertNull($read->rum());
        static::assertNull($read->accountId());
    }

    public function testAPaidPaymentIsReadBackWithItsResult(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T10:00:00Z', 2));

        $read = $this->repository->get(PaymentRecordId::fromString('payment-1'));

        static::assertSame(PaymentState::Paid, $read->state());
        static::assertSame(2, $read->attempts());
        static::assertSame('2026-11-10T10:00:00Z', $read->lastAttemptAt()?->toIso8601());
        $result = $read->result();
        static::assertNotNull($result);
        static::assertTrue($result->equals(PaymentSamples::result()));
        static::assertSame('12345678', $result->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $result->paymentId());
        static::assertSame('2026-11-20', $result->dueDate()?->toString());
        static::assertSame([], $read->errors());
    }

    public function testAPaymentResolvedByAnOperatorIsReadBackWithItsCertificateOnly(): void
    {
        $record = ContractSamples::paymentIn(PaymentState::OutcomeUnknown, 1, 1, 'payment-ref-1', ContractSamples::monthly());
        $record->resolveAsPaid(PaymentSamples::certificate(), ContractSamples::at('2026-11-11T09:00:00Z'));
        $this->repository->save($record);

        $result = $this->repository->get($record->id())->result();

        static::assertNotNull($result);
        static::assertTrue($result->isResolution());
        static::assertSame('12345678', $result->certificate()->value());
        static::assertNull($result->paymentId());
        static::assertNull($result->dueDate());
        static::assertNull($result->period());
    }

    public function testARejectedPaymentIsReadBackWithItsErrors(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 1, 1, 'payment-ref-1', ContractSamples::monthly()));

        $read = $this->repository->get(PaymentRecordId::fromString('payment-1'));

        static::assertSame(PaymentState::Rejected, $read->state());
        static::assertSame(
            [['ER_API_060_0070', 'unknown_mandate', 'Mandat inconnu', 'Détail']],
            array_map(
                static fn(SubmissionError $error): array => [$error->code(), $error->kind(), $error->message(), $error->description()],
                $read->errors(),
            ),
        );
        static::assertNull($read->result());
    }

    public function testPaymentsInOutcomeUnknownErrorAndInvalidCredentialsAreReadBack(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::OutcomeUnknown, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $stale = ContractSamples::paymentIn(PaymentState::Submitting, 2, 2, 'payment-ref-2', ContractSamples::monthly());
        $stale->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-15T11:00:00Z'));
        $this->repository->save($stale);
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 3, 3, 'payment-ref-3', ContractSamples::monthly()));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::InvalidCredentials, 4, 4, 'payment-ref-4', ContractSamples::monthly()));

        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $this->repository->get(PaymentRecordId::fromString('payment-1'))->outcomeUnknownReason());
        static::assertSame(OutcomeUnknownReason::StaleSubmission, $this->repository->get(PaymentRecordId::fromString('payment-2'))->outcomeUnknownReason());
        $failed = $this->repository->get(PaymentRecordId::fromString('payment-3'));
        static::assertSame(PaymentState::Error, $failed->state());
        static::assertSame(['ER_API_060_0070'], array_map(static fn(SubmissionError $error): string => $error->code(), $failed->errors()));
        static::assertSame(PaymentState::InvalidCredentials, $this->repository->get(PaymentRecordId::fromString('payment-4'))->state());
    }

    public function testTheEventsAreNeitherPersistedNorConsumedFromTheCaller(): void
    {
        $record = ContractSamples::pendingPayment(1, 1, 'payment-ref-1', ContractSamples::monthly());

        $this->repository->save($record);

        static::assertCount(1, $record->releaseEvents());
        static::assertSame([], $this->repository->get($record->id())->releaseEvents());
    }

    public function testASavedChangeIsReadBack(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $stored = $this->repository->get(PaymentRecordId::fromString('payment-1'));
        $stored->startSubmission(ContractSamples::at('2026-11-16T09:00:00Z'));
        $stored->markFailed([SubmissionError::of('urssaf.unavailable', 'technical', 'Down.', null)], ContractSamples::at('2026-11-16T09:00:05Z'));

        $this->repository->save($stored);
        $read = $this->repository->get(PaymentRecordId::fromString('payment-1'));

        static::assertSame(PaymentState::Error, $read->state());
        static::assertSame(1, $read->attempts());
        static::assertSame('2026-11-16T09:00:00Z', $read->lastAttemptAt()?->toIso8601());
        static::assertSame('2026-11-16T09:00:05Z', $read->updatedAt()->toIso8601());
        static::assertSame('2026-11-15T10:00:00Z', $read->createdAt()->toIso8601());
        static::assertSame(['urssaf.unavailable'], array_map(static fn(SubmissionError $error): string => $error->code(), $read->errors()));
    }

    public function testAnUnknownPaymentIsNotFound(): void
    {
        try {
            $this->repository->get(PaymentRecordId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $exception) {
            static::assertSame('payment_record.not_found', $exception->violationCode());
            static::assertSame('missing', $exception->identifier());
        }
    }

    public function testASecondCurrentPaymentOfTheSameReferenceIsRefused(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));

        try {
            $this->repository->save(ContractSamples::payment(2, 2, 'payment-ref-1', ContractSamples::monthly(9)));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('payment_record.duplicate_reference', $exception->violationCode());
            static::assertSame(PaymentRecordRepository::DUPLICATE_REFERENCE, $exception->violationCode());
            static::assertSame([], $exception->params());
            static::assertStringNotContainsString('payment-ref-1', $exception->getMessage());
        }
        static::assertSame(['payment-1'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), PaymentState::Pending, 10)));
    }

    public function testTheSameReferenceInAnotherTenantAndAnotherReferenceAreAccepted(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $this->repository->save(ContractSamples::payment(2, 1, 'payment-ref-1', ContractSamples::monthly(), ContractSamples::OTHER_TENANT));
        $this->repository->save(ContractSamples::payment(3, 1, 'payment-ref-2', ContractSamples::monthly()));

        static::assertSame('payment-2', $this->repository->findByReference(ContractSamples::tenant(ContractSamples::OTHER_TENANT), PaymentReference::fromString('payment-ref-1'))?->id()->value());
        static::assertSame('payment-3', $this->repository->findByReference(ContractSamples::tenant(), PaymentReference::fromString('payment-ref-2'))?->id()->value());
    }

    public function testSavingAnExistingPaymentAgainIsAnUpdateNotADuplicate(): void
    {
        $record = ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly());
        $this->repository->save($record);
        $this->repository->save($record);

        static::assertSame(['payment-1'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), PaymentState::Paid, 10)));
    }

    public function testFindByReferenceIsTenantScopedAndReturnsNullWhenAbsent(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $reference = PaymentReference::fromString('payment-ref-1');

        static::assertSame('payment-1', $this->repository->findByReference(ContractSamples::tenant(), $reference)?->id()->value());
        static::assertNull($this->repository->findByReference(ContractSamples::tenant(ContractSamples::OTHER_TENANT), $reference));
        static::assertNull($this->repository->findByReference(ContractSamples::tenant(), PaymentReference::fromString('payment-ref-2')));
    }

    public function testTheCurrentPaymentsOfAPeriodAreFilteredByStateAndScopedOldestFirst(): void
    {
        $monthly = ContractSamples::monthly();
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', $monthly, ContractSamples::TENANT, '2026-11-12T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 3, 1, 'payment-ref-3', $monthly, ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::OutcomeUnknown, 2, 1, 'payment-ref-2', $monthly, ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 4, 1, 'payment-ref-4', $monthly, ContractSamples::TENANT, '2026-11-09T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 5, 1, 'payment-ref-5', ContractSamples::monthly(9)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 6, 1, 'payment-ref-6', ContractSamples::quarterly(3)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 7, 2, 'payment-ref-7', $monthly));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 8, 1, 'payment-ref-8', $monthly, ContractSamples::OTHER_TENANT));
        $tenant = ContractSamples::tenant();
        $states = [PaymentState::Submitting, PaymentState::Paid, PaymentState::OutcomeUnknown];

        static::assertSame(['payment-2', 'payment-3', 'payment-1'], self::recordIds($this->repository->findCurrentForPeriod($tenant, ContractSamples::siret(1), $monthly, $states)));
        static::assertSame(['payment-4'], self::recordIds($this->repository->findCurrentForPeriod($tenant, ContractSamples::siret(1), $monthly, [PaymentState::Rejected])));
        static::assertSame(['payment-6'], self::recordIds($this->repository->findCurrentForPeriod($tenant, ContractSamples::siret(1), ContractSamples::quarterly(3), $states)));
        static::assertSame([], $this->repository->findCurrentForPeriod($tenant, ContractSamples::siret(3), $monthly, $states));
    }

    public function testASupersededPaymentIsNotCurrentForThePeriod(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $old = $this->repository->get(PaymentRecordId::fromString('payment-1'));
        $new = $old->supersede(PaymentRecordId::fromString('payment-2'), ContractSamples::paymentOrder(ContractSamples::monthly(), 1501), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($old);
        $this->repository->save($new);

        static::assertSame([], $this->repository->findCurrentForPeriod(ContractSamples::tenant(), ContractSamples::siret(1), ContractSamples::monthly(), [PaymentState::Rejected]));
        static::assertSame(['payment-2'], self::recordIds($this->repository->findCurrentForPeriod(ContractSamples::tenant(), ContractSamples::siret(1), ContractSamples::monthly(), [PaymentState::Pending])));
    }

    public function testThePaymentsOfAStateComeLeastRecentlyUpdatedFirstWithTiesByIdWithinTheLimitAndTheTenant(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 1, 1, 'payment-ref-1', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-12T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 3, 2, 'payment-ref-3', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 2, 3, 'payment-ref-2', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 4, 4, 'payment-ref-4', ContractSamples::monthly(), ContractSamples::OTHER_TENANT, '2026-11-09T09:00:00Z'));
        $this->repository->save(ContractSamples::payment(5, 5, 'payment-ref-5', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-08T09:00:00Z'));
        $tenant = ContractSamples::tenant();

        static::assertSame(['payment-2', 'payment-3', 'payment-1'], self::recordIds($this->repository->findByState($tenant, PaymentState::Error, 10)));
        static::assertSame(['payment-2', 'payment-3'], self::recordIds($this->repository->findByState($tenant, PaymentState::Error, 2)));
        static::assertSame(['payment-5'], self::recordIds($this->repository->findByState($tenant, PaymentState::Pending, 10)));
        static::assertSame([], $this->repository->findByState($tenant, PaymentState::Paid, 10));
    }

    public function testTheStaleSubmissionsAreTheOnesAttemptedAtOrBeforeTheInstantOfEveryTenantOldestFirst(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 1, 1, 'payment-ref-1', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 2, 2, 'payment-ref-2', ContractSamples::monthly(), ContractSamples::OTHER_TENANT, '2026-11-10T08:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 3, 3, 'payment-ref-3', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:01Z'));
        $this->repository->save(ContractSamples::payment(4, 4, 'payment-ref-4', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 5, 5, 'payment-ref-5', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 6, 6, 'payment-ref-6', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:30:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 7, 7, 'payment-ref-7', ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:30:00Z'));
        $olderThan = ContractSamples::at('2026-11-10T09:00:00Z');

        static::assertSame(['payment-6', 'payment-7', 'payment-2', 'payment-1'], self::recordIds($this->repository->findStaleSubmissions($olderThan, 10)));
        static::assertSame(['payment-6', 'payment-7'], self::recordIds($this->repository->findStaleSubmissions($olderThan, 2)));
        static::assertSame([], $this->repository->findStaleSubmissions(ContractSamples::at('2026-11-10T07:29:59Z'), 10));
        static::assertSame(['payment-6', 'payment-7'], self::recordIds($this->repository->findStaleSubmissions(ContractSamples::at('2026-11-10T07:59:59Z'), 10)));
        static::assertSame(['payment-6', 'payment-7', 'payment-2'], self::recordIds($this->repository->findStaleSubmissions(ContractSamples::at('2026-11-10T08:00:00Z'), 10)));
    }

    public function testEveryStateIsCountedWithZeroForTheEmptyOnes(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 2, 2, 'payment-ref-2', ContractSamples::monthly()));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 3, 3, 'payment-ref-3', ContractSamples::monthly()));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 4, 4, 'payment-ref-4', ContractSamples::monthly(), ContractSamples::OTHER_TENANT));

        $counts = $this->repository->countByState(ContractSamples::tenant());

        static::assertEqualsCanonicalizing(
            array_map(static fn(PaymentState $state): string => $state->value, PaymentState::cases()),
            array_keys($counts),
        );
        static::assertSame(2, $counts['paid']);
        static::assertSame(1, $counts['error']);
        static::assertSame(3, array_sum($counts));
        static::assertSame(0, array_sum($this->repository->countByState(ContractSamples::tenant('tenant-9'))));
        static::assertCount(\count(PaymentState::cases()), $this->repository->countByState(ContractSamples::tenant('tenant-9')));
    }

    public function testCountBySiretCountsEveryStateOfThatSiretOnly(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly(9)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 2, 1, 'payment-ref-2', ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 3, 1, 'payment-ref-3', ContractSamples::monthly(11)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 4, 2, 'payment-ref-4', ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 5, 1, 'payment-ref-5', ContractSamples::monthly(10), ContractSamples::OTHER_TENANT));

        $counts = $this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1));

        static::assertSame(
            array_map(static fn(PaymentState $state): string => $state->value, PaymentState::cases()),
            array_keys($counts),
        );
        static::assertSame(2, $counts['paid']);
        static::assertSame(1, $counts['error']);
        static::assertSame(3, array_sum($counts));
        static::assertSame(0, array_sum($this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(3))));
        static::assertCount(\count(PaymentState::cases()), $this->repository->countBySiret(ContractSamples::tenant('tenant-9'), ContractSamples::siret(1)));
    }

    public function testTheTenantsWithAStateAreDistinctAndSorted(): void
    {
        static::assertSame([], $this->repository->tenantsWithState(PaymentState::InvalidCredentials));

        $this->repository->save(ContractSamples::paymentIn(PaymentState::InvalidCredentials, 1, 1, 'payment-ref-1', ContractSamples::monthly(), 'tenant-b'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::InvalidCredentials, 2, 2, 'payment-ref-2', ContractSamples::monthly(), 'tenant-a'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::InvalidCredentials, 3, 3, 'payment-ref-3', ContractSamples::monthly(), 'tenant-b'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 4, 4, 'payment-ref-4', ContractSamples::monthly(), 'tenant-c'));

        static::assertSame(
            ['tenant-a', 'tenant-b'],
            array_map(static fn(TenantId $tenant): string => $tenant->value(), $this->repository->tenantsWithState(PaymentState::InvalidCredentials)),
        );
    }

    public function testAPendingPaymentIsClaimedOnceWithOneMoreAttempt(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $now = ContractSamples::at('2026-11-16T10:00:00Z');
        $id = PaymentRecordId::fromString('payment-1');

        static::assertTrue($this->repository->claimForSubmission($id, $now));
        static::assertFalse($this->repository->claimForSubmission($id, $now));

        $read = $this->repository->get($id);
        static::assertSame(PaymentState::Submitting, $read->state());
        static::assertSame(1, $read->attempts());
        static::assertSame('2026-11-16T10:00:00Z', $read->lastAttemptAt()?->toIso8601());
        static::assertSame('2026-11-16T10:00:00Z', $read->updatedAt()->toIso8601());
        static::assertSame([], $read->releaseEvents());
    }

    public function testTheClaimCountsTheAttemptsOfARequeuedPayment(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 1, 1, 'payment-ref-1', ContractSamples::monthly(), ContractSamples::TENANT, ContractSamples::AT, 2));
        $stored = $this->repository->get(PaymentRecordId::fromString('payment-1'));
        $stored->retry(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($stored);

        static::assertTrue($this->repository->claimForSubmission($stored->id(), ContractSamples::at('2026-11-16T10:00:00Z')));
        static::assertSame(3, $this->repository->get($stored->id())->attempts());
    }

    public function testAMissingOrNotPendingPaymentIsNotClaimed(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 2, 2, 'payment-ref-2', ContractSamples::monthly()));
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        static::assertFalse($this->repository->claimForSubmission(PaymentRecordId::fromString('missing'), $now));
        static::assertFalse($this->repository->claimForSubmission(PaymentRecordId::fromString('payment-1'), $now));
        static::assertFalse($this->repository->claimForSubmission(PaymentRecordId::fromString('payment-2'), $now));
        static::assertSame(1, $this->repository->get(PaymentRecordId::fromString('payment-1'))->attempts());
        static::assertSame('2026-11-15T10:00:00Z', $this->repository->get(PaymentRecordId::fromString('payment-1'))->lastAttemptAt()?->toIso8601());
    }

    public function testTheClaimRecordsNoEventOnTheCallerInstance(): void
    {
        $record = ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly());
        $this->repository->save($record);

        $this->repository->claimForSubmission($record->id(), ContractSamples::at('2026-11-16T10:00:00Z'));

        static::assertSame([], $record->releaseEvents());
    }

    public function testARejectedPaymentIsSupersededByANewOneSavedAfterItInOneTransaction(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $old = $this->repository->get(PaymentRecordId::fromString('payment-1'));
        $new = $old->supersede(PaymentRecordId::fromString('payment-2'), ContractSamples::paymentOrder(ContractSamples::monthly(), 1501), ContractSamples::at('2026-11-16T09:00:00Z'));

        $this->transactions()->transactional(function () use ($old, $new): void {
            $this->repository->save($old);
            $this->repository->save($new);
        });

        $current = $this->repository->findByReference(ContractSamples::tenant(), PaymentReference::fromString('payment-ref-1'));
        static::assertNotNull($current);
        static::assertSame('payment-2', $current->id()->value());
        static::assertSame('payment-1', $current->supersedes()?->value());
        static::assertSame(1501, $current->amount()->toInt());
        $kept = $this->repository->get(PaymentRecordId::fromString('payment-1'));
        static::assertSame('payment-2', $kept->supersededBy()?->value());
        static::assertFalse($kept->isCurrent());
        static::assertSame(PaymentState::Rejected, $kept->state());
        static::assertSame(['payment-2'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), PaymentState::Pending, 10)));
        static::assertSame(['payment-1'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), PaymentState::Rejected, 10)));
    }

    public function testTheNewPaymentSavedBeforeTheSupersededOneIsRefused(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $old = $this->repository->get(PaymentRecordId::fromString('payment-1'));
        $new = $old->supersede(PaymentRecordId::fromString('payment-2'), ContractSamples::paymentOrder(ContractSamples::monthly(), 1501), ContractSamples::at('2026-11-16T09:00:00Z'));

        try {
            $this->repository->save($new);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('payment_record.duplicate_reference', $exception->violationCode());
            static::assertSame([], $exception->params());
        }
        static::assertSame('payment-1', $this->repository->findByReference(ContractSamples::tenant(), PaymentReference::fromString('payment-ref-1'))?->id()->value());
    }

    public function testASupersededPaymentIsNotClaimedButTheNewOneIs(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $old = $this->repository->get(PaymentRecordId::fromString('payment-1'));
        $new = $old->supersede(PaymentRecordId::fromString('payment-2'), ContractSamples::paymentOrder(ContractSamples::monthly(), 1501), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($old);
        $this->repository->save($new);
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        static::assertFalse($this->repository->claimForSubmission($old->id(), $now));
        static::assertSame(PaymentState::Rejected, $this->repository->get($old->id())->state());
        static::assertTrue($this->repository->claimForSubmission($new->id(), $now));
    }

    public function testTheLoserOfTwoConcurrentSupersessionsIsRefused(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        $first = $this->repository->get($id);
        $second = $this->anotherWriter()->get($id);
        $firstNew = $first->supersede(PaymentRecordId::fromString('payment-2'), ContractSamples::paymentOrder(ContractSamples::monthly(), 1501), ContractSamples::at('2026-11-16T09:00:00Z'));
        $loserNew = $second->supersede(PaymentRecordId::fromString('payment-3'), ContractSamples::paymentOrder(ContractSamples::monthly(), 1502), ContractSamples::at('2026-11-16T09:00:01Z'));
        $this->repository->save($first);
        $this->repository->save($firstNew);

        $this->assertSaveIsRefusedAsConcurrent($second, $this->anotherWriter());

        static::assertSame('payment-2', $this->repository->findByReference(ContractSamples::tenant(), PaymentReference::fromString('payment-ref-1'))?->id()->value());
        static::assertSame('payment-2', $this->repository->get($id)->supersededBy()?->value());

        try {
            $this->anotherWriter()->save($loserNew);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('payment_record.duplicate_reference', $exception->violationCode());
        }
    }

    public function testDeleteBySiretDeletesThePaymentsInTheGivenStatesAndReturnsTheirIdsOldestFirst(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly(9), ContractSamples::TENANT, '2026-11-12T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Error, 4, 1, 'payment-ref-4', ContractSamples::monthly(10), ContractSamples::TENANT, '2026-11-11T09:00:00Z'));
        $this->repository->save(ContractSamples::payment(3, 1, 'payment-ref-3', ContractSamples::monthly(11), ContractSamples::TENANT, '2026-11-11T09:00:00Z'));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Rejected, 2, 1, 'payment-ref-2', ContractSamples::monthly(12), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));

        $deleted = $this->repository->deleteBySiret(
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            [PaymentState::Paid, PaymentState::Error, PaymentState::Pending, PaymentState::Rejected],
        );

        static::assertSame(['payment-2', 'payment-3', 'payment-4', 'payment-1'], self::idValues($deleted));
        static::assertSame(0, array_sum($this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1))));
    }

    public function testDeleteBySiretKeepsThePaymentsInOtherStatesAndOfOtherSiretsAndTenants(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly(9)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 2, 1, 'payment-ref-2', ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 3, 2, 'payment-ref-3', ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 4, 1, 'payment-ref-4', ContractSamples::monthly(10), ContractSamples::OTHER_TENANT));

        $deleted = $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [PaymentState::Paid]);

        static::assertSame(['payment-1'], self::idValues($deleted));
        static::assertSame(PaymentState::Submitting, $this->repository->get(PaymentRecordId::fromString('payment-2'))->state());
        static::assertSame(PaymentState::Paid, $this->repository->get(PaymentRecordId::fromString('payment-3'))->state());
        static::assertSame(PaymentState::Paid, $this->repository->get(PaymentRecordId::fromString('payment-4'))->state());
        static::assertSame(1, $this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1))['submitting']);
        static::assertSame(0, $this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1))['paid']);
    }

    public function testDeleteBySiretWithoutMatchReturnsNothing(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly()));

        static::assertSame([], $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(2), [PaymentState::Paid]));
        static::assertSame([], $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [PaymentState::Error]));
        static::assertSame('payment-1', $this->repository->get(PaymentRecordId::fromString('payment-1'))->id()->value());
    }

    public function testAPaymentDeletedBySiretIsNotFoundEvenIfItWasReadBefore(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        $this->repository->get($id);

        $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [PaymentState::Paid]);

        $this->expectException(RecordNotFound::class);
        $this->repository->get($id);
    }

    public function testDeleteBySiretFreesTheReference(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'payment-ref-1', ContractSamples::monthly()));

        $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [PaymentState::Paid]);
        $this->repository->save(ContractSamples::payment(2, 1, 'payment-ref-1', ContractSamples::monthly()));

        static::assertSame('payment-2', $this->repository->findByReference(ContractSamples::tenant(), PaymentReference::fromString('payment-ref-1'))?->id()->value());
    }

    public function testACopyChangedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        $first = $this->repository->get($id);
        $second = $this->anotherWriter()->get($id);
        $first->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-16T09:00:00Z'));
        $second->markFailed([SubmissionError::of('urssaf.unavailable', 'technical', 'Down.', null)], ContractSamples::at('2026-11-17T09:00:00Z'));
        $this->repository->save($first);

        $this->assertSaveIsRefusedAsConcurrent($second, $this->anotherWriter());
        static::assertSame(PaymentState::OutcomeUnknown, $this->repository->get($id)->state());
    }

    public function testACopyReadBeforeAClaimIsRefused(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        $stale = $this->repository->get($id);
        static::assertTrue($this->anotherWriter()->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));
        $stale->startSubmission(ContractSamples::at('2026-11-16T10:00:01Z'));

        $this->assertSaveIsRefusedAsConcurrent($stale, $this->repository);
        static::assertSame(PaymentState::Submitting, $this->repository->get($id)->state());
        static::assertSame('2026-11-16T10:00:00Z', $this->repository->get($id)->lastAttemptAt()?->toIso8601());
    }

    public function testACopyReadAfterTheClaimCanBeSavedAndSavedAgain(): void
    {
        $this->repository->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        static::assertTrue($this->repository->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));

        $claimed = $this->repository->get($id);
        $claimed->markFailed([SubmissionError::of('urssaf.unavailable', 'technical', 'Down.', null)], ContractSamples::at('2026-11-16T10:00:05Z'));
        $this->repository->save($claimed);
        $claimed->retry(ContractSamples::at('2026-11-16T10:00:06Z'));
        $this->repository->save($claimed);

        static::assertSame(PaymentState::Pending, $this->repository->get($id)->state());
    }

    public function testACopyErasedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        $stale = $this->repository->get($id);
        $stale->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [PaymentState::Submitting]);

        $this->assertSaveIsRefusedAsConcurrent($stale, $this->repository);
        $this->expectException(RecordNotFound::class);
        $this->repository->get($id);
    }

    public function testACopyFoundByAQueryIsCheckedLikeACopyReadById(): void
    {
        $this->repository->save(ContractSamples::paymentIn(PaymentState::Submitting, 1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $tenant = ContractSamples::tenant();
        $found = [
            $this->repository->findByReference($tenant, PaymentReference::fromString('payment-ref-1')),
            $this->repository->findByState($tenant, PaymentState::Submitting, 10)[0] ?? null,
            $this->repository->findStaleSubmissions(ContractSamples::at('2026-12-01T00:00:00Z'), 10)[0] ?? null,
            $this->repository->findCurrentForPeriod($tenant, ContractSamples::siret(1), ContractSamples::monthly(), [PaymentState::Submitting])[0] ?? null,
        ];
        $other = $this->anotherWriter()->get(PaymentRecordId::fromString('payment-1'));
        $other->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->save($other);

        foreach ($found as $stale) {
            static::assertNotNull($stale);
            $this->assertSaveIsRefusedAsConcurrent($stale, $this->repository);
        }
    }

    private function assertSaveIsRefusedAsConcurrent(PaymentRecord $stale, PaymentRecordRepository $writer): void
    {
        try {
            $writer->save($stale);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('payment_record.concurrent_modification', $exception->violationCode());
            static::assertSame(PaymentRecordRepository::CONCURRENT_MODIFICATION, $exception->violationCode());
        }
    }
}
