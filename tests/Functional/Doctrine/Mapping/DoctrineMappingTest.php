<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Mapping;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;

/**
 * Mapping scenarios (validity, round trips through the entity manager only, UTC instants, stored codes, optimistic
 * lock): one method per scenario.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:kan-defect One method per mapping scenario.
 */
final class DoctrineMappingTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
    }

    public function testTheMappingIsValidAndInSyncWithTheSchema(): void
    {
        $validator = new SchemaValidator($this->entityManager);

        static::assertSame([], $validator->validateMapping());
        static::assertTrue($validator->schemaInSyncWithMetadata());
    }

    public function testTheVersionIsAnOptimisticLockColumnOfTheFourAggregates(): void
    {
        $account = ContractSamples::account(1);
        $mandate = ContractSamples::mandate(1, 1);
        $declaration = ContractSamples::declaration(1, 1, ContractSamples::monthly());
        $payment = ContractSamples::payment(1, 1, 'INV-1', ContractSamples::monthly());
        foreach ([$account, $mandate, $declaration, $payment] as $aggregate) {
            $this->entityManager->persist($aggregate);
        }
        $this->entityManager->flush();

        foreach (self::AGGREGATE_TABLES as $table) {
            static::assertSame(1, $this->version($table));
        }

        $now = ContractSamples::at('2026-11-16T10:00:00Z');
        $account->sync(ContractSamples::status(true), $now);
        $mandate->cancel($now);
        $declaration->startSubmission($now);
        $payment->startSubmission($now);
        $this->entityManager->flush();

        foreach (self::AGGREGATE_TABLES as $table) {
            static::assertSame(2, $this->version($table));
        }
    }

    public function testAStaleAggregateIsRefusedByTheOptimisticLock(): void
    {
        $account = ContractSamples::account(1);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $other = TestDatabase::anotherEntityManager($this->entityManager);
        $copy = $other->find(AeAccount::class, $account->id());
        static::assertInstanceOf(AeAccount::class, $copy);
        $copy->sync(ContractSamples::status(true), ContractSamples::at('2026-11-16T10:00:00Z'));
        $other->flush();

        $account->sync(ContractSamples::status(false), ContractSamples::at('2026-11-16T11:00:00Z'));
        $this->expectException(OptimisticLockException::class);
        $this->entityManager->flush();
    }

    #[DataProviderExternal(DoctrineMappingCases::class, 'accounts')]
    public function testAnAccountIsReadBackIdentically(string $case): void
    {
        $account = DoctrineMappingSamples::account($case);

        $read = $this->roundTrip([$account], AeAccount::class)[0];

        static::assertNotSame($account, $read);
        static::assertEquals($account, $read);
        static::assertSame([], $read->releaseEvents());
        static::assertSame($account->siret()->toString(), $read->siret()->toString());
        static::assertSame($account->birthDate()?->toString(), $read->birthDate()?->toString());
        static::assertSame($account->periodicity(), $read->periodicity());
        static::assertSame($account->status()->mandatePresent(), $read->status()->mandatePresent());
        static::assertSame($account->status()->acreExemption(), $read->status()->acreExemption());
        static::assertSame($account->status()->affiliationDate()?->toString(), $read->status()->affiliationDate()?->toString());
        static::assertTrue($account->lastSyncedAt()->equals($read->lastSyncedAt()));
    }

    #[DataProviderExternal(DoctrineMappingCases::class, 'mandates')]
    public function testAMandateIsReadBackIdentically(string $case): void
    {
        $mandate = DoctrineMappingSamples::mandate($case);

        $read = $this->roundTrip([$mandate], Mandate::class)[0];

        static::assertNotSame($mandate, $read);
        static::assertEquals($mandate, $read);
        static::assertSame([], $read->releaseEvents());
        static::assertSame($mandate->state(), $read->state());
        static::assertSame($mandate->origin(), $read->origin());
        static::assertSame($mandate->cancellationOrigin(), $read->cancellationOrigin());
        static::assertEquals($mandate->accountId(), $read->accountId());
        static::assertSame($mandate->siret()->toString(), $read->siret()->toString());
        static::assertTrue($mandate->timeline()->notifiedAt()->equals($read->timeline()->notifiedAt()));
        static::assertSame($mandate->cancelledAt()?->toIso8601(), $read->cancelledAt()?->toIso8601());
    }

    #[DataProviderExternal(DoctrineMappingCases::class, 'declarations')]
    public function testADeclarationRecordIsReadBackIdentically(string $case): void
    {
        $records = DoctrineMappingSamples::declarations($case);

        $reads = $this->roundTrip($records, DeclarationRecord::class);

        foreach ($records as $index => $record) {
            $read = $reads[$index];
            static::assertNotSame($record, $read);
            static::assertEquals($record, $read);
            static::assertSame([], $read->releaseEvents());
            static::assertSame($record->state(), $read->state());
            static::assertSame($record->attempts(), $read->attempts());
            static::assertSame($record->outcomeUnknownReason(), $read->outcomeUnknownReason());
            static::assertSame($record->isAcceptedAfterSupersession(), $read->isAcceptedAfterSupersession());
            static::assertTrue($record->period()->equals($read->period()));
            static::assertEquals($record->errors(), $read->errors());
            static::assertEquals($record->result(), $read->result());
            static::assertEquals($record->supersedes(), $read->supersedes());
            static::assertEquals($record->supersededBy(), $read->supersededBy());
            static::assertEquals($record->accountId(), $read->accountId());
        }
    }

    #[DataProviderExternal(DoctrineMappingCases::class, 'payments')]
    public function testAPaymentRecordIsReadBackIdentically(string $case): void
    {
        $records = DoctrineMappingSamples::payments($case);

        $reads = $this->roundTrip($records, PaymentRecord::class);

        foreach ($records as $index => $record) {
            $read = $reads[$index];
            static::assertNotSame($record, $read);
            static::assertEquals($record, $read);
            static::assertSame([], $read->releaseEvents());
            static::assertSame($record->state(), $read->state());
            static::assertSame($record->attempts(), $read->attempts());
            static::assertSame($record->outcomeUnknownReason(), $read->outcomeUnknownReason());
            static::assertSame($record->amount()->toInt(), $read->amount()->toInt());
            static::assertSame($record->rum()?->value(), $read->rum()?->value());
            static::assertSame($record->reference()->value(), $read->reference()->value());
            static::assertTrue($record->period()->equals($read->period()));
            static::assertEquals($record->errors(), $read->errors());
            static::assertEquals($record->result(), $read->result());
            static::assertEquals($record->supersedes(), $read->supersedes());
            static::assertEquals($record->supersededBy(), $read->supersededBy());
            static::assertEquals($record->accountId(), $read->accountId());
        }
    }

    public function testADeclaredRecordKeepsItsResultAndItsAmounts(): void
    {
        $record = ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly());
        $this->entityManager->persist($record);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $read = $this->entityManager->find(DeclarationRecord::class, $record->id());

        static::assertInstanceOf(DeclarationRecord::class, $read);
        $result = $read->result();
        static::assertNotNull($result);
        static::assertSame('12345678', $result->certificate()->value());
        static::assertNotNull($result->amounts());
        $stored = $this->entityManager->getConnection()->fetchOne('SELECT result FROM urssaf_tdae_declaration_records');
        static::assertIsString($stored);
        static::assertStringContainsString('"certificate":"12345678"', $stored);
    }

    public function testThePeriodAndTheStatusColumnsAreStoredWithTheirCodes(): void
    {
        $this->entityManager->persist(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));
        $this->entityManager->persist(ContractSamples::declaration(2, 2, ContractSamples::quarterly()));
        $this->entityManager->persist(ContractSamples::paymentIn(PaymentState::OutcomeUnknown, 1, 1, 'INV-1', ContractSamples::quarterly(2)));
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        static::assertSame(
            [
                ['id' => 'declaration-1', 'periodicity' => 'M', 'period_year' => 2026, 'period_number' => 10, 'state' => 'rejected', 'submission_attempts' => 1, 'outcome_unknown_reason' => null],
                ['id' => 'declaration-2', 'periodicity' => 'T', 'period_year' => 2026, 'period_number' => 3, 'state' => 'pending', 'submission_attempts' => 0, 'outcome_unknown_reason' => null],
            ],
            $connection->fetchAllAssociative('SELECT id, periodicity, period_year, period_number, state, submission_attempts, outcome_unknown_reason FROM urssaf_tdae_declaration_records ORDER BY id'),
        );
        static::assertSame(
            ['periodicity' => 'T', 'period_year' => 2026, 'period_number' => 2, 'state' => 'outcome_unknown', 'outcome_unknown_reason' => 'may_have_reached_urssaf', 'montant' => 1_500, 'payment_reference' => 'INV-1'],
            $connection->fetchAssociative('SELECT periodicity, period_year, period_number, state, outcome_unknown_reason, montant, payment_reference FROM urssaf_tdae_payment_records'),
        );
    }

    public function testTheAccountIsStoredWithItsStatusColumns(): void
    {
        $this->entityManager->persist(ContractSamples::account(1));
        $this->entityManager->flush();

        static::assertSame(
            ['id' => 'account-1', 'siret' => '73282932000075', 'birth_date' => '1975-10-01', 'mandate_present' => 0, 'periodicity' => 'M', 'affiliation_date' => '2019-12-01', 'acre_exemption' => 0],
            $this->entityManager->getConnection()->fetchAssociative('SELECT id, siret, birth_date, mandate_present, periodicity, affiliation_date, acre_exemption FROM urssaf_tdae_accounts'),
        );
    }

    public function testTheMandateIsStoredWithItsTimelineColumns(): void
    {
        $this->entityManager->persist(ContractSamples::cancelledMandate(1, 1));
        $this->entityManager->flush();

        static::assertSame(
            ['id' => 'mandate-1', 'account_id' => 'account-1', 'origin' => 'notified', 'state' => 'cancelled', 'cancellation_origin' => 'cancelled', 'notified_at' => '2026-11-15 10:00:00', 'cancelled_at' => '2026-11-15 11:00:00', 'updated_at' => '2026-11-15 11:00:00'],
            $this->entityManager->getConnection()->fetchAssociative('SELECT id, account_id, origin, state, cancellation_origin, notified_at, cancelled_at, updated_at FROM urssaf_tdae_mandates'),
        );
    }

    public function testAbsentGroupsAreStoredAsSqlNull(): void
    {
        $this->entityManager->persist(DoctrineMappingSamples::account('quarterly-bare'));
        $this->entityManager->persist(DoctrineMappingSamples::mandate('no-account'));
        $this->entityManager->persist(DoctrineMappingSamples::declarations('no-account')[0]);
        $this->entityManager->persist(DoctrineMappingSamples::payments('no-rum')[0]);
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        static::assertNull($connection->fetchOne('SELECT affiliation_date FROM urssaf_tdae_accounts'));
        static::assertNull($connection->fetchOne('SELECT acre_exemption FROM urssaf_tdae_accounts'));
        static::assertNull($connection->fetchOne('SELECT cancelled_at FROM urssaf_tdae_mandates'));
        static::assertNull($connection->fetchOne('SELECT cancellation_origin FROM urssaf_tdae_mandates'));
        static::assertNull($connection->fetchOne('SELECT account_id FROM urssaf_tdae_mandates'));
        foreach (['declaration', 'payment'] as $kind) {
            $table = 'urssaf_tdae_' . $kind . '_records';
            static::assertNull($connection->fetchOne('SELECT result FROM ' . $table));
            static::assertNull($connection->fetchOne('SELECT last_attempt_at FROM ' . $table));
            static::assertNull($connection->fetchOne('SELECT outcome_unknown_reason FROM ' . $table));
            static::assertNull($connection->fetchOne('SELECT supersedes FROM ' . $table));
            static::assertNull($connection->fetchOne('SELECT superseded_by FROM ' . $table));
            static::assertSame('[]', $connection->fetchOne('SELECT errors FROM ' . $table));
        }
        static::assertNull($connection->fetchOne('SELECT account_id FROM urssaf_tdae_declaration_records'));
        static::assertNull($connection->fetchOne('SELECT rum FROM urssaf_tdae_payment_records'));
    }

    public function testInstantsAreStoredInUtcAndDatesAsCalendarDatesWhateverTheDefaultTimeZone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $account = ContractSamples::account(1, ContractSamples::TENANT, '2026-11-10T23:30:00+01:00');
            $this->entityManager->persist($account);
            $this->entityManager->flush();
            $this->entityManager->clear();
            $read = $this->entityManager->find(AeAccount::class, $account->id());
            $stored = $this->entityManager->getConnection()->fetchAssociative('SELECT created_at, last_synced_at, updated_at, birth_date, affiliation_date FROM urssaf_tdae_accounts');
        } finally {
            date_default_timezone_set($previous);
        }

        static::assertEquals($account, $read);
        static::assertSame(
            ['created_at' => '2026-11-10 22:30:00', 'last_synced_at' => '2026-11-10 22:30:00', 'updated_at' => '2026-11-10 22:30:00', 'birth_date' => '1975-10-01', 'affiliation_date' => '2019-12-01'],
            $stored,
        );
    }

    public function testTheUniqueConstraintOfTheAccountIsEnforcedByTheSchema(): void
    {
        $this->entityManager->persist(ContractSamples::account(1));
        $this->entityManager->flush();

        $other = AeAccount::track(
            AccountId::fromString('account-2'),
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            ContractSamples::status(),
            null,
            ContractSamples::at(),
        );
        $this->entityManager->persist($other);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testTheSameSiretMayExistInAnotherTenant(): void
    {
        $this->entityManager->persist(ContractSamples::account(1));
        $this->entityManager->persist(AeAccount::track(
            AccountId::fromString('account-other'),
            ContractSamples::tenant(ContractSamples::OTHER_TENANT),
            ContractSamples::siret(1),
            ContractSamples::status(),
            null,
            ContractSamples::at(),
        ));
        $this->entityManager->flush();

        static::assertSame(2, $this->rowCount('urssaf_tdae_accounts'));
        static::assertSame(1, $this->tenantRowCount('urssaf_tdae_accounts', ContractSamples::OTHER_TENANT));
    }

    public function testNoUniqueConstraintLimitsTheMandatesNorTheRecords(): void
    {
        $this->entityManager->persist(ContractSamples::cancelledMandate(1, 1));
        $this->entityManager->persist(ContractSamples::mandate(2, 1));
        $this->entityManager->persist(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $this->entityManager->persist(ContractSamples::declaration(2, 1, ContractSamples::monthly()));
        $this->entityManager->persist(ContractSamples::payment(1, 1, 'INV-1', ContractSamples::monthly()));
        $this->entityManager->persist(ContractSamples::payment(2, 1, 'INV-1', ContractSamples::monthly()));
        $this->entityManager->flush();

        static::assertSame(2, $this->rowCount('urssaf_tdae_mandates'));
        static::assertSame(2, $this->rowCount('urssaf_tdae_declaration_records'));
        static::assertSame(2, $this->rowCount('urssaf_tdae_payment_records'));
        static::assertSame(Periodicity::Monthly->value, $this->entityManager->getConnection()->fetchOne('SELECT periodicity FROM urssaf_tdae_payment_records LIMIT 1'));
    }

    private const AGGREGATE_TABLES = [
        'urssaf_tdae_accounts',
        'urssaf_tdae_mandates',
        'urssaf_tdae_declaration_records',
        'urssaf_tdae_payment_records',
    ];

    private function rowCount(string $table): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ' . $table);
        static::assertIsNumeric($count);

        return (int) $count;
    }

    private function tenantRowCount(string $table, string $tenant): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ' . $table . ' WHERE tenant_id = ?', [$tenant]);
        static::assertIsNumeric($count);

        return (int) $count;
    }

    private function version(string $table): int
    {
        $version = $this->entityManager->getConnection()->fetchOne('SELECT version FROM ' . $table);
        static::assertIsNumeric($version);

        return (int) $version;
    }

    /**
     * @template T of AeAccount|Mandate|DeclarationRecord|PaymentRecord
     *
     * @param list<T>         $aggregates
     * @param class-string<T> $class
     *
     * @return list<T> the aggregates read back from a cleared entity manager, in the same order
     */
    private function roundTrip(array $aggregates, string $class): array
    {
        foreach ($aggregates as $aggregate) {
            $this->entityManager->persist($aggregate);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reads = [];
        foreach ($aggregates as $aggregate) {
            $read = $this->entityManager->find($class, $aggregate->id());
            static::assertInstanceOf($class, $read);
            $reads[] = $read;
        }

        return $reads;
    }
}
