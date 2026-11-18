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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;

/**
 * Behaviour every {@see DeclarationRecordRepository} must have, independently of the storage. Identity-related
 * behaviour (copies versus managed instances) is deliberately not part of the contract. One method per scenario.
 *
 * A supersession saves the superseded record first, then the new one, in one transaction (the order the Doctrine
 * adapter needs: the key row moves after the update of the old row, ADR 0016).
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 * @mago-expect lint:too-many-methods
 */
trait DeclarationRecordRepositoryContract
{
    protected DeclarationRecordRepository $repository;

    abstract protected function createRepository(): DeclarationRecordRepository;

    /**
     * An independent writer over the same store: another process, another EntityManager. The in-memory adapter has one
     * store per repository and returns the repository itself. Competing copies are read, and saved, through it.
     */
    abstract protected function anotherWriter(): DeclarationRecordRepository;

    /**
     * The transaction manager of the storage (the supersession saves two records in one transaction).
     */
    abstract protected function transactions(): TransactionManager;

    protected function setUp(): void
    {
        $this->repository = $this->createRepository();
    }

    /**
     * @param list<DeclarationRecord> $records
     *
     * @return list<string>
     */
    protected static function recordIds(array $records): array
    {
        return array_map(static fn(DeclarationRecord $record): string => $record->id()->value(), $records);
    }

    /**
     * @param list<DeclarationRecordId> $ids
     *
     * @return list<string>
     */
    protected static function idValues(array $ids): array
    {
        return array_map(static fn(DeclarationRecordId $id): string => $id->value(), $ids);
    }

    public function testAPendingRecordIsReadBack(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));

        $read = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));

        static::assertSame('declaration-1', $read->id()->value());
        static::assertSame('tenant-1', $read->tenantId()->value());
        static::assertTrue($read->siret()->equals(ContractSamples::siret(1)));
        static::assertTrue($read->period()->equals(ContractSamples::monthly()));
        static::assertSame(Periodicity::Monthly, $read->periodicity());
        static::assertTrue($read->fingerprint()->equals(ContractSamples::declaration(1, 1, ContractSamples::monthly())->fingerprint()));
        static::assertSame('account-1', $read->accountId()?->value());
        static::assertNull($read->supersedes());
        static::assertNull($read->supersededBy());
        static::assertTrue($read->isCurrent());
        static::assertFalse($read->isAcceptedAfterSupersession());
        static::assertSame(DeclarationState::Pending, $read->state());
        static::assertSame(0, $read->attempts());
        static::assertNull($read->lastAttemptAt());
        static::assertSame([], $read->errors());
        static::assertNull($read->result());
        static::assertNull($read->outcomeUnknownReason());
        static::assertSame('2026-11-10T09:00:00Z', $read->createdAt()->toIso8601());
        static::assertSame('2026-11-10T09:00:00Z', $read->updatedAt()->toIso8601());
    }

    public function testAQuarterlyRecordWithoutAccountIsReadBack(): void
    {
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString('declaration-1'),
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            ContractSamples::quarterly(),
            DeclarationSamples::fingerprint(),
            null,
            ContractSamples::at(),
        );
        $this->repository->save($record);

        $read = $this->repository->get($record->id());

        static::assertSame(Periodicity::Quarterly, $read->periodicity());
        static::assertTrue($read->period()->equals(ContractSamples::quarterly()));
        static::assertNull($read->accountId());
    }

    public function testADeclaredRecordIsReadBackWithItsResult(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T10:00:00Z', 2));

        $read = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));

        static::assertSame(DeclarationState::Declared, $read->state());
        static::assertSame(2, $read->attempts());
        static::assertSame('2026-11-10T10:00:00Z', $read->lastAttemptAt()?->toIso8601());
        $result = $read->result();
        static::assertNotNull($result);
        static::assertTrue($result->equals(DeclarationSamples::result()));
        static::assertSame('12345678', $result->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $result->declarationId());
        static::assertSame('2026-11-20', $result->dueDate()?->toString());
        static::assertNotNull($result->amounts());
        static::assertSame([], $read->errors());
        static::assertNull($read->outcomeUnknownReason());
    }

    public function testARecordResolvedByAnOperatorIsReadBackWithItsCertificateOnly(): void
    {
        $record = ContractSamples::declarationIn(DeclarationState::OutcomeUnknown, 1, 1, ContractSamples::monthly());
        $record->resolveAsDeclared(DeclarationSamples::certificate(), ContractSamples::at('2026-11-11T09:00:00Z'));
        $this->repository->save($record);

        $result = $this->repository->get($record->id())->result();

        static::assertNotNull($result);
        static::assertTrue($result->isResolution());
        static::assertSame('12345678', $result->certificate()->value());
        static::assertNull($result->declarationId());
        static::assertNull($result->dueDate());
        static::assertNull($result->amounts());
    }

    public function testARejectedRecordIsReadBackWithItsErrors(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));

        $read = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));

        static::assertSame(DeclarationState::Rejected, $read->state());
        static::assertSame(
            [['ER_API_050_0070', 'unknown_mandate', 'Mandat inconnu', 'Détail']],
            array_map(
                static fn(SubmissionError $error): array => [$error->code(), $error->kind(), $error->message(), $error->description()],
                $read->errors(),
            ),
        );
        static::assertNull($read->result());
    }

    public function testRecordsInOutcomeUnknownErrorAndInvalidCredentialsAreReadBack(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::OutcomeUnknown, 1, 1, ContractSamples::monthly()));
        $stale = ContractSamples::declarationIn(DeclarationState::Submitting, 2, 2, ContractSamples::monthly());
        $stale->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-15T11:00:00Z'));
        $this->repository->save($stale);
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 3, 3, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::InvalidCredentials, 4, 4, ContractSamples::monthly()));

        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf, $this->repository->get(DeclarationRecordId::fromString('declaration-1'))->outcomeUnknownReason());
        static::assertSame(OutcomeUnknownReason::StaleSubmission, $this->repository->get(DeclarationRecordId::fromString('declaration-2'))->outcomeUnknownReason());
        $failed = $this->repository->get(DeclarationRecordId::fromString('declaration-3'));
        static::assertSame(DeclarationState::Error, $failed->state());
        static::assertSame(['ER_API_050_0070'], array_map(static fn(SubmissionError $error): string => $error->code(), $failed->errors()));
        static::assertSame(DeclarationState::InvalidCredentials, $this->repository->get(DeclarationRecordId::fromString('declaration-4'))->state());
    }

    public function testTheEventsAreNeitherPersistedNorConsumedFromTheCaller(): void
    {
        $record = ContractSamples::pendingDeclaration(1, 1, ContractSamples::monthly());

        $this->repository->save($record);

        static::assertCount(1, $record->releaseEvents());
        static::assertSame([], $this->repository->get($record->id())->releaseEvents());
    }

    public function testASavedChangeIsReadBack(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $stored = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $stored->startSubmission(ContractSamples::at('2026-11-16T09:00:00Z'));
        $stored->markFailed([SubmissionError::of('urssaf.unavailable', 'technical', 'Down.', null)], ContractSamples::at('2026-11-16T09:00:05Z'));

        $this->repository->save($stored);
        $read = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));

        static::assertSame(DeclarationState::Error, $read->state());
        static::assertSame(1, $read->attempts());
        static::assertSame('2026-11-16T09:00:00Z', $read->lastAttemptAt()?->toIso8601());
        static::assertSame('2026-11-16T09:00:05Z', $read->updatedAt()->toIso8601());
        static::assertSame('2026-11-15T10:00:00Z', $read->createdAt()->toIso8601());
        static::assertSame(['urssaf.unavailable'], array_map(static fn(SubmissionError $error): string => $error->code(), $read->errors()));
    }

    public function testAnUnknownRecordIsNotFound(): void
    {
        try {
            $this->repository->get(DeclarationRecordId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $exception) {
            static::assertSame('declaration_record.not_found', $exception->violationCode());
            static::assertSame('missing', $exception->identifier());
        }
    }

    public function testASecondCurrentRecordOfTheSamePeriodIsRefused(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));

        try {
            $this->repository->save(ContractSamples::declaration(2, 1, ContractSamples::monthly()));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.duplicate_current', $exception->violationCode());
            static::assertSame(DeclarationRecordRepository::DUPLICATE_CURRENT, $exception->violationCode());
            // The SIRET is personal data (ADR 0010): never in an exception.
            static::assertSame([], $exception->params());
            static::assertStringNotContainsString(ContractSamples::siret(1)->toString(), $exception->getMessage());
        }
        static::assertSame(['declaration-1'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), DeclarationState::Pending, 10)));
    }

    public function testAnotherTenantAnotherSiretOrAnotherPeriodIsAccepted(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declaration(2, 1, ContractSamples::monthly(), ContractSamples::OTHER_TENANT));
        $this->repository->save(ContractSamples::declaration(3, 2, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declaration(4, 1, ContractSamples::monthly(9)));
        $this->repository->save(ContractSamples::declaration(5, 1, ContractSamples::quarterly(3)));

        static::assertCount(4, $this->repository->findByState(ContractSamples::tenant(), DeclarationState::Pending, 10));
        static::assertSame('declaration-2', $this->repository->findCurrent(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(1), ContractSamples::monthly())?->id()->value());
    }

    public function testSavingAnExistingRecordAgainIsAnUpdateNotADuplicate(): void
    {
        $record = ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly());
        $this->repository->save($record);
        $this->repository->save($record);

        static::assertSame(['declaration-1'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), DeclarationState::Declared, 10)));
    }

    public function testFindCurrentIsScopedByTenantSiretAndPeriodAndReturnsNullWhenAbsent(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $tenant = ContractSamples::tenant();

        static::assertSame('declaration-1', $this->repository->findCurrent($tenant, ContractSamples::siret(1), ContractSamples::monthly())?->id()->value());
        static::assertNull($this->repository->findCurrent(ContractSamples::tenant(ContractSamples::OTHER_TENANT), ContractSamples::siret(1), ContractSamples::monthly()));
        static::assertNull($this->repository->findCurrent($tenant, ContractSamples::siret(2), ContractSamples::monthly()));
        static::assertNull($this->repository->findCurrent($tenant, ContractSamples::siret(1), ContractSamples::monthly(9)));
        static::assertNull($this->repository->findCurrent($tenant, ContractSamples::siret(1), ContractSamples::quarterly(3)));
    }

    public function testTheRecordsOfAStateComeLeastRecentlyUpdatedFirstWithTiesByIdWithinTheLimitAndTheTenant(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 1, 1, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-12T09:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 3, 2, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 2, 3, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 4, 4, ContractSamples::monthly(), ContractSamples::OTHER_TENANT, '2026-11-09T09:00:00Z'));
        $this->repository->save(ContractSamples::declaration(5, 5, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-08T09:00:00Z'));
        $tenant = ContractSamples::tenant();

        static::assertSame(['declaration-2', 'declaration-3', 'declaration-1'], self::recordIds($this->repository->findByState($tenant, DeclarationState::Error, 10)));
        static::assertSame(['declaration-2', 'declaration-3'], self::recordIds($this->repository->findByState($tenant, DeclarationState::Error, 2)));
        static::assertSame(['declaration-5'], self::recordIds($this->repository->findByState($tenant, DeclarationState::Pending, 10)));
        static::assertSame([], $this->repository->findByState($tenant, DeclarationState::Declared, 10));
    }

    public function testTheStaleSubmissionsAreTheOnesAttemptedAtOrBeforeTheInstantOfEveryTenantOldestFirst(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 1, 1, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 2, 2, ContractSamples::monthly(), ContractSamples::OTHER_TENANT, '2026-11-10T08:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 3, 3, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T09:00:01Z'));
        $this->repository->save(ContractSamples::declaration(4, 4, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 5, 5, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 6, 6, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:30:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 7, 7, ContractSamples::monthly(), ContractSamples::TENANT, '2026-11-10T07:30:00Z'));
        $olderThan = ContractSamples::at('2026-11-10T09:00:00Z');

        static::assertSame(['declaration-6', 'declaration-7', 'declaration-2', 'declaration-1'], self::recordIds($this->repository->findStaleSubmissions($olderThan, 10)));
        static::assertSame(['declaration-6', 'declaration-7'], self::recordIds($this->repository->findStaleSubmissions($olderThan, 2)));
        static::assertSame([], $this->repository->findStaleSubmissions(ContractSamples::at('2026-11-10T07:29:59Z'), 10));
        static::assertSame(['declaration-6', 'declaration-7'], self::recordIds($this->repository->findStaleSubmissions(ContractSamples::at('2026-11-10T07:59:59Z'), 10)));
        static::assertSame(['declaration-6', 'declaration-7', 'declaration-2'], self::recordIds($this->repository->findStaleSubmissions(ContractSamples::at('2026-11-10T08:00:00Z'), 10)));
    }

    public function testEveryStateIsCountedWithZeroForTheEmptyOnes(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 2, 2, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 3, 3, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 4, 4, ContractSamples::monthly(), ContractSamples::OTHER_TENANT));

        $counts = $this->repository->countByState(ContractSamples::tenant());

        static::assertEqualsCanonicalizing(
            array_map(static fn(DeclarationState $state): string => $state->value, DeclarationState::cases()),
            array_keys($counts),
        );
        static::assertSame(
            ['pending' => 0, 'submitting' => 0, 'declared' => 2, 'rejected' => 0, 'outcome_unknown' => 0, 'error' => 1, 'invalid_credentials' => 0],
            [
                'pending' => $counts['pending'],
                'submitting' => $counts['submitting'],
                'declared' => $counts['declared'],
                'rejected' => $counts['rejected'],
                'outcome_unknown' => $counts['outcome_unknown'],
                'error' => $counts['error'],
                'invalid_credentials' => $counts['invalid_credentials'],
            ],
        );
        static::assertSame(0, array_sum($this->repository->countByState(ContractSamples::tenant('tenant-9'))));
        static::assertCount(\count(DeclarationState::cases()), $this->repository->countByState(ContractSamples::tenant('tenant-9')));
    }

    public function testCountBySiretCountsEveryStateOfThatSiretOnly(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly(9)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 2, 1, ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 3, 1, ContractSamples::monthly(11)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 4, 2, ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 5, 1, ContractSamples::monthly(10), ContractSamples::OTHER_TENANT));

        $counts = $this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1));

        static::assertSame(
            array_map(static fn(DeclarationState $state): string => $state->value, DeclarationState::cases()),
            array_keys($counts),
        );
        static::assertSame(2, $counts['declared']);
        static::assertSame(1, $counts['error']);
        static::assertSame(3, array_sum($counts));
        static::assertSame(0, array_sum($this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(3))));
        static::assertCount(\count(DeclarationState::cases()), $this->repository->countBySiret(ContractSamples::tenant('tenant-9'), ContractSamples::siret(1)));
    }

    public function testTheTenantsWithAStateAreDistinctAndSorted(): void
    {
        static::assertSame([], $this->repository->tenantsWithState(DeclarationState::InvalidCredentials));

        $this->repository->save(ContractSamples::declarationIn(DeclarationState::InvalidCredentials, 1, 1, ContractSamples::monthly(), 'tenant-b'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::InvalidCredentials, 2, 2, ContractSamples::monthly(), 'tenant-a'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::InvalidCredentials, 3, 3, ContractSamples::monthly(), 'tenant-b'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 4, 4, ContractSamples::monthly(), 'tenant-c'));

        static::assertSame(
            ['tenant-a', 'tenant-b'],
            array_map(static fn(TenantId $tenant): string => $tenant->value(), $this->repository->tenantsWithState(DeclarationState::InvalidCredentials)),
        );
    }

    public function testAPendingRecordIsClaimedOnceWithOneMoreAttempt(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $now = ContractSamples::at('2026-11-16T10:00:00Z');
        $id = DeclarationRecordId::fromString('declaration-1');

        static::assertTrue($this->repository->claimForSubmission($id, $now));
        static::assertFalse($this->repository->claimForSubmission($id, $now));

        $read = $this->repository->get($id);
        static::assertSame(DeclarationState::Submitting, $read->state());
        static::assertSame(1, $read->attempts());
        static::assertSame('2026-11-16T10:00:00Z', $read->lastAttemptAt()?->toIso8601());
        static::assertSame('2026-11-16T10:00:00Z', $read->updatedAt()->toIso8601());
        static::assertSame([], $read->releaseEvents());
    }

    public function testTheClaimCountsTheAttemptsOfARequeuedRecord(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 1, 1, ContractSamples::monthly(), ContractSamples::TENANT, ContractSamples::AT, 2));
        $stored = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $stored->retry(ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($stored);

        static::assertTrue($this->repository->claimForSubmission($stored->id(), ContractSamples::at('2026-11-16T10:00:00Z')));
        static::assertSame(3, $this->repository->get($stored->id())->attempts());
    }

    public function testAMissingOrNotPendingRecordIsNotClaimed(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 1, 1, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 2, 2, ContractSamples::monthly()));
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        static::assertFalse($this->repository->claimForSubmission(DeclarationRecordId::fromString('missing'), $now));
        static::assertFalse($this->repository->claimForSubmission(DeclarationRecordId::fromString('declaration-1'), $now));
        static::assertFalse($this->repository->claimForSubmission(DeclarationRecordId::fromString('declaration-2'), $now));
        static::assertSame(1, $this->repository->get(DeclarationRecordId::fromString('declaration-1'))->attempts());
        static::assertSame('2026-11-15T10:00:00Z', $this->repository->get(DeclarationRecordId::fromString('declaration-1'))->lastAttemptAt()?->toIso8601());
    }

    public function testTheClaimRecordsNoEventOnTheCallerInstance(): void
    {
        $record = ContractSamples::declaration(1, 1, ContractSamples::monthly());
        $this->repository->save($record);

        $this->repository->claimForSubmission($record->id(), ContractSamples::at('2026-11-16T10:00:00Z'));

        static::assertSame([], $record->releaseEvents());
    }

    public function testARejectedRecordIsSupersededByANewOneSavedAfterItInOneTransaction(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));
        $old = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $new = $old->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));

        $this->transactions()->transactional(function () use ($old, $new): void {
            $this->repository->save($old);
            $this->repository->save($new);
        });

        $current = $this->repository->findCurrent(ContractSamples::tenant(), ContractSamples::siret(1), ContractSamples::monthly());
        static::assertNotNull($current);
        static::assertSame('declaration-2', $current->id()->value());
        static::assertSame('declaration-1', $current->supersedes()?->value());
        $kept = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        static::assertSame('declaration-2', $kept->supersededBy()?->value());
        static::assertFalse($kept->isCurrent());
        static::assertSame(DeclarationState::Rejected, $kept->state());
        static::assertSame(['declaration-2'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), DeclarationState::Pending, 10)));
        static::assertSame(['declaration-1'], self::recordIds($this->repository->findByState(ContractSamples::tenant(), DeclarationState::Rejected, 10)));
    }

    public function testTheNewRecordSavedBeforeTheSupersededOneIsRefused(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));
        $old = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $new = $old->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));

        try {
            $this->repository->save($new);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.duplicate_current', $exception->violationCode());
            static::assertSame([], $exception->params());
        }
        static::assertSame('declaration-1', $this->repository->findCurrent(ContractSamples::tenant(), ContractSamples::siret(1), ContractSamples::monthly())?->id()->value());
    }

    public function testASupersededPendingRecordIsNoLongerClaimable(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $old = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $new = $old->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($old);
        $this->repository->save($new);
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        static::assertFalse($this->repository->claimForSubmission($old->id(), $now));
        static::assertSame(DeclarationState::Pending, $this->repository->get($old->id())->state());
        static::assertTrue($this->repository->claimForSubmission($new->id(), $now));
    }

    public function testTheLoserOfTwoConcurrentSupersessionsIsRefused(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $first = $this->repository->get($id);
        $second = $this->anotherWriter()->get($id);
        $firstNew = $first->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));
        $loserNew = $second->supersede(DeclarationRecordId::fromString('declaration-3'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:01Z'));
        $this->repository->save($first);
        $this->repository->save($firstNew);

        $this->assertSaveIsRefusedAsConcurrent($second, $this->anotherWriter());

        static::assertSame('declaration-2', $this->repository->findCurrent(ContractSamples::tenant(), ContractSamples::siret(1), ContractSamples::monthly())?->id()->value());
        static::assertSame('declaration-2', $this->repository->get($id)->supersededBy()?->value());

        try {
            $this->anotherWriter()->save($loserNew);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.duplicate_current', $exception->violationCode());
        }
    }

    public function testALateAcceptanceOnASupersededRecordIsCounted(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::OutcomeUnknown, 1, 1, ContractSamples::monthly()));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 4, 2, ContractSamples::monthly()));
        $old = $this->repository->get(DeclarationRecordId::fromString('declaration-1'));
        $new = $old->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->repository->save($old);
        $this->repository->save($new);
        $tenant = ContractSamples::tenant();

        static::assertSame(0, $this->repository->countAcceptedAfterSupersession($tenant));

        $late = $this->repository->get($old->id());
        $late->markDeclared(DeclarationSamples::result(), ContractSamples::at('2026-11-16T10:00:00Z'));
        $this->repository->save($late);

        static::assertTrue($this->repository->get($old->id())->isAcceptedAfterSupersession());
        static::assertSame(1, $this->repository->countAcceptedAfterSupersession($tenant));
        static::assertSame(0, $this->repository->countAcceptedAfterSupersession(ContractSamples::tenant(ContractSamples::OTHER_TENANT)));
    }

    public function testDeleteBySiretDeletesTheRecordsInTheGivenStatesAndReturnsTheirIdsOldestFirst(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly(9), ContractSamples::TENANT, '2026-11-12T09:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Error, 4, 1, ContractSamples::monthly(10), ContractSamples::TENANT, '2026-11-11T09:00:00Z'));
        $this->repository->save(ContractSamples::declaration(3, 1, ContractSamples::monthly(11), ContractSamples::TENANT, '2026-11-11T09:00:00Z'));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Rejected, 2, 1, ContractSamples::monthly(12), ContractSamples::TENANT, '2026-11-10T09:00:00Z'));

        $deleted = $this->repository->deleteBySiret(
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            [DeclarationState::Declared, DeclarationState::Error, DeclarationState::Pending, DeclarationState::Rejected],
        );

        static::assertSame(['declaration-2', 'declaration-3', 'declaration-4', 'declaration-1'], self::idValues($deleted));
        static::assertSame(0, array_sum($this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1))));
    }

    public function testDeleteBySiretKeepsTheRecordsInOtherStatesAndOfOtherSiretsAndTenants(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly(9)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 2, 1, ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 3, 2, ContractSamples::monthly(10)));
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 4, 1, ContractSamples::monthly(10), ContractSamples::OTHER_TENANT));

        $deleted = $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Declared]);

        static::assertSame(['declaration-1'], self::idValues($deleted));
        static::assertSame(DeclarationState::Submitting, $this->repository->get(DeclarationRecordId::fromString('declaration-2'))->state());
        static::assertSame(DeclarationState::Declared, $this->repository->get(DeclarationRecordId::fromString('declaration-3'))->state());
        static::assertSame(DeclarationState::Declared, $this->repository->get(DeclarationRecordId::fromString('declaration-4'))->state());
        static::assertSame(1, $this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1))['submitting']);
        static::assertSame(0, $this->repository->countBySiret(ContractSamples::tenant(), ContractSamples::siret(1))['declared']);
    }

    public function testDeleteBySiretWithoutMatchReturnsNothing(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly()));

        static::assertSame([], $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(2), [DeclarationState::Declared]));
        static::assertSame([], $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Error]));
        static::assertSame('declaration-1', $this->repository->get(DeclarationRecordId::fromString('declaration-1'))->id()->value());
    }

    public function testARecordDeletedBySiretIsNotFoundEvenIfItWasReadBefore(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $this->repository->get($id);

        $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Declared]);

        $this->expectException(RecordNotFound::class);
        $this->repository->get($id);
    }

    public function testDeleteBySiretFreesTheCurrentKeyOfThePeriod(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly()));

        $this->repository->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Declared]);
        $this->repository->save(ContractSamples::declaration(2, 1, ContractSamples::monthly()));

        static::assertSame('declaration-2', $this->repository->findCurrent(ContractSamples::tenant(), ContractSamples::siret(1), ContractSamples::monthly())?->id()->value());
    }

    public function testACopyChangedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $first = $this->repository->get($id);
        $second = $this->anotherWriter()->get($id);
        $first->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-16T09:00:00Z'));
        $second->markFailed([SubmissionError::of('urssaf.unavailable', 'technical', 'Down.', null)], ContractSamples::at('2026-11-17T09:00:00Z'));
        $this->repository->save($first);

        $this->assertSaveIsRefusedAsConcurrent($second, $this->anotherWriter());
        static::assertSame(DeclarationState::OutcomeUnknown, $this->repository->get($id)->state());
    }

    public function testACopyReadBeforeAClaimIsRefused(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $stale = $this->repository->get($id);
        static::assertTrue($this->anotherWriter()->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));
        $stale->startSubmission(ContractSamples::at('2026-11-16T10:00:01Z'));

        $this->assertSaveIsRefusedAsConcurrent($stale, $this->repository);
        static::assertSame(DeclarationState::Submitting, $this->repository->get($id)->state());
        static::assertSame('2026-11-16T10:00:00Z', $this->repository->get($id)->lastAttemptAt()?->toIso8601());
    }

    public function testACopyReadAfterTheClaimCanBeSavedAndSavedAgain(): void
    {
        $this->repository->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        static::assertTrue($this->repository->claimForSubmission($id, ContractSamples::at('2026-11-16T10:00:00Z')));

        $claimed = $this->repository->get($id);
        $claimed->markFailed([SubmissionError::of('urssaf.unavailable', 'technical', 'Down.', null)], ContractSamples::at('2026-11-16T10:00:05Z'));
        $this->repository->save($claimed);
        $claimed->retry(ContractSamples::at('2026-11-16T10:00:06Z'));
        $this->repository->save($claimed);

        static::assertSame(DeclarationState::Pending, $this->repository->get($id)->state());
    }

    public function testACopyErasedByAnotherWriterSinceItWasReadIsRefused(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $stale = $this->repository->get($id);
        $stale->markDeclared(DeclarationSamples::result(), ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->deleteBySiret(ContractSamples::tenant(), ContractSamples::siret(1), [DeclarationState::Submitting]);

        $this->assertSaveIsRefusedAsConcurrent($stale, $this->repository);
        $this->expectException(RecordNotFound::class);
        $this->repository->get($id);
    }

    public function testACopyFoundByAQueryIsCheckedLikeACopyReadById(): void
    {
        $this->repository->save(ContractSamples::declarationIn(DeclarationState::Submitting, 1, 1, ContractSamples::monthly()));
        $tenant = ContractSamples::tenant();
        $found = [
            $this->repository->findCurrent($tenant, ContractSamples::siret(1), ContractSamples::monthly()),
            $this->repository->findByState($tenant, DeclarationState::Submitting, 10)[0] ?? null,
            $this->repository->findStaleSubmissions(ContractSamples::at('2026-12-01T00:00:00Z'), 10)[0] ?? null,
        ];
        $other = $this->anotherWriter()->get(DeclarationRecordId::fromString('declaration-1'));
        $other->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [], ContractSamples::at('2026-11-16T09:00:00Z'));
        $this->anotherWriter()->save($other);

        foreach ($found as $stale) {
            static::assertNotNull($stale);
            $this->assertSaveIsRefusedAsConcurrent($stale, $this->repository);
        }
    }

    private function assertSaveIsRefusedAsConcurrent(DeclarationRecord $stale, DeclarationRecordRepository $writer): void
    {
        try {
            $writer->save($stale);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.concurrent_modification', $exception->violationCode());
            static::assertSame(DeclarationRecordRepository::CONCURRENT_MODIFICATION, $exception->violationCode());
        }
    }
}
