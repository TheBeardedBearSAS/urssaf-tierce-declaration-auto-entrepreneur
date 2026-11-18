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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity\ActiveMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity\CurrentDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity\CurrentPayment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Entity\OutboxMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEventCodec;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;

/**
 * The entities the bridge maps for its own tables: rows written with DBAL, never through the ORM (ADR 0016).
 *
 * @mago-expect lint:too-many-methods One method per scenario of the five bridge entities.
 */
final class BridgeEntitiesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = TestDatabase::entityManager()->getConnection();
    }

    public function testAnActiveMandateIsTheKeyRowOfItsMandate(): void
    {
        $mandate = ContractSamples::mandate(1, 1);

        $row = ActiveMandate::of($mandate)->row();

        static::assertSame(['tenant_id', 'siret', 'mandate_id'], array_keys($row));
        static::assertSame($mandate->tenantId(), $row['tenant_id']);
        static::assertSame($mandate->siret(), $row['siret']);
        static::assertSame($mandate->id(), $row['mandate_id']);
        static::assertSame(array_keys($row), array_keys(ActiveMandate::TYPES));
    }

    public function testTheKeyRowOfAMandateIsWrittenWithDbalAndTheSiretHasOneKeyRowPerTenant(): void
    {
        $this->connection->insert('urssaf_tdae_active_mandates', ActiveMandate::of(ContractSamples::mandate(1, 1))->row(), ActiveMandate::TYPES);

        static::assertSame(
            ['tenant_id' => 'tenant-1', 'siret' => '73282932000075', 'mandate_id' => 'mandate-1'],
            $this->connection->fetchAssociative('SELECT tenant_id, siret, mandate_id FROM urssaf_tdae_active_mandates'),
        );
        $this->connection->insert('urssaf_tdae_active_mandates', ActiveMandate::of(ContractSamples::mandate(2, 1, ContractSamples::OTHER_TENANT))->row(), ActiveMandate::TYPES);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('urssaf_tdae_active_mandates', ActiveMandate::of(ContractSamples::mandate(3, 1))->row(), ActiveMandate::TYPES);
    }

    public function testAMandateHasAtMostOneKeyRow(): void
    {
        $this->connection->insert('urssaf_tdae_active_mandates', ActiveMandate::of(ContractSamples::mandate(1, 1))->row(), ActiveMandate::TYPES);
        $other = ActiveMandate::of(ContractSamples::mandate(2, 2))->row();
        $other['mandate_id'] = ContractSamples::mandate(1, 1)->id();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('urssaf_tdae_active_mandates', $other, ActiveMandate::TYPES);
    }

    public function testACurrentDeclarationIsTheKeyRowOfItsRecord(): void
    {
        $record = ContractSamples::declaration(1, 1, ContractSamples::quarterly(2));

        $row = CurrentDeclaration::of($record)->row();

        static::assertSame(['tenant_id', 'siret', 'periodicity', 'period_year', 'period_number', 'declaration_record_id'], array_keys($row));
        static::assertSame($record->tenantId(), $row['tenant_id']);
        static::assertSame($record->siret(), $row['siret']);
        static::assertSame('T', $row['periodicity']);
        static::assertSame(2026, $row['period_year']);
        static::assertSame(2, $row['period_number']);
        static::assertSame($record->id(), $row['declaration_record_id']);
        static::assertSame(array_keys($row), array_keys(CurrentDeclaration::TYPES));
    }

    public function testACurrentDeclarationOfAMonthHoldsTheMonthNumber(): void
    {
        $row = CurrentDeclaration::of(ContractSamples::declaration(1, 1, ContractSamples::monthly(10)))->row();

        static::assertSame('M', $row['periodicity']);
        static::assertSame(10, $row['period_number']);
    }

    public function testTheKeyRowOfADeclarationIsWrittenWithDbalAndThePeriodHasOneKeyRow(): void
    {
        $record = ContractSamples::declaration(1, 1, ContractSamples::monthly());
        $this->connection->insert('urssaf_tdae_current_declarations', CurrentDeclaration::of($record)->row(), CurrentDeclaration::TYPES);

        static::assertSame(
            ['tenant_id' => 'tenant-1', 'siret' => '73282932000075', 'periodicity' => 'M', 'period_year' => 2026, 'period_number' => 10, 'declaration_record_id' => 'declaration-1'],
            $this->connection->fetchAssociative('SELECT tenant_id, siret, periodicity, period_year, period_number, declaration_record_id FROM urssaf_tdae_current_declarations'),
        );
        $this->connection->insert('urssaf_tdae_current_declarations', CurrentDeclaration::of(ContractSamples::declaration(2, 1, ContractSamples::monthly(11)))->row(), CurrentDeclaration::TYPES);
        $this->connection->insert('urssaf_tdae_current_declarations', CurrentDeclaration::of(ContractSamples::declaration(3, 1, ContractSamples::quarterly(3)))->row(), CurrentDeclaration::TYPES);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('urssaf_tdae_current_declarations', CurrentDeclaration::of(ContractSamples::declaration(4, 1, ContractSamples::monthly()))->row(), CurrentDeclaration::TYPES);
    }

    public function testACurrentPaymentIsTheKeyRowOfItsRecord(): void
    {
        $record = ContractSamples::payment(1, 1, 'INV-1', ContractSamples::monthly());

        $row = CurrentPayment::of($record)->row();

        static::assertSame(['tenant_id', 'payment_reference', 'payment_record_id'], array_keys($row));
        static::assertSame($record->tenantId(), $row['tenant_id']);
        static::assertSame($record->reference(), $row['payment_reference']);
        static::assertSame($record->id(), $row['payment_record_id']);
        static::assertSame(array_keys($row), array_keys(CurrentPayment::TYPES));
    }

    public function testTheKeyRowOfAPaymentIsWrittenWithDbalAndTheReferenceHasOneKeyRowPerTenant(): void
    {
        $this->connection->insert('urssaf_tdae_current_payments', CurrentPayment::of(ContractSamples::payment(1, 1, 'INV-1', ContractSamples::monthly()))->row(), CurrentPayment::TYPES);

        static::assertSame(
            ['tenant_id' => 'tenant-1', 'payment_reference' => 'INV-1', 'payment_record_id' => 'payment-1'],
            $this->connection->fetchAssociative('SELECT tenant_id, payment_reference, payment_record_id FROM urssaf_tdae_current_payments'),
        );
        $this->connection->insert('urssaf_tdae_current_payments', CurrentPayment::of(ContractSamples::payment(2, 1, 'INV-1', ContractSamples::monthly(), ContractSamples::OTHER_TENANT))->row(), CurrentPayment::TYPES);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('urssaf_tdae_current_payments', CurrentPayment::of(ContractSamples::payment(3, 1, 'INV-1', ContractSamples::monthly()))->row(), CurrentPayment::TYPES);
    }

    public function testAPaymentRecordHasAtMostOneKeyRow(): void
    {
        $this->connection->insert('urssaf_tdae_current_payments', CurrentPayment::of(ContractSamples::payment(1, 1, 'INV-1', ContractSamples::monthly()))->row(), CurrentPayment::TYPES);
        $other = CurrentPayment::of(ContractSamples::payment(2, 1, 'INV-2', ContractSamples::monthly()))->row();
        $other['payment_record_id'] = ContractSamples::payment(1, 1, 'INV-1', ContractSamples::monthly())->id();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('urssaf_tdae_current_payments', $other, CurrentPayment::TYPES);
    }

    public function testAnOutboxMessageHoldsEveryColumnOfTheTableButTheSequence(): void
    {
        $event = new MandateCancelled('8f7e9b0e-5f0a-4c53-9a53-1d2b0c3d4e5f', '2026-11-10T09:00:00Z', 'tenant-1', 'mandate-1', null, 'account-1', 'cancelled');

        $row = OutboxMessage::of($event)->row();

        $columns = SchemaReader::columnNames($this->connection)['urssaf_tdae_outbox'];
        sort($columns);
        $names = array_keys($row);
        sort($names);
        static::assertSame(array_values(array_diff($columns, ['sequence'])), $names);
        static::assertSame(
            [
                'event_id' => '8f7e9b0e-5f0a-4c53-9a53-1d2b0c3d4e5f',
                'type' => 'urssaf.tdae.mandate.cancelled',
                'version' => 1,
                'tenant_id' => 'tenant-1',
                'aggregate_id' => 'mandate-1',
                'external_reference' => null,
                'occurred_at' => '2026-11-10T09:00:00Z',
                'payload' => $event->toArray(),
                'data' => IntegrationEventCodec::encode($event),
                'dispatched_at' => null,
                'attempts' => 0,
                'last_error' => null,
            ],
            $row,
        );
    }

    public function testAnOutboxMessageKeepsTheOptionalCommonProperties(): void
    {
        $sepa = new SepaMandateRegistered('9a7e9b0e-5f0a-4c53-9a53-1d2b0c3d4e5f', '2026-11-10T09:00:00Z', 'tenant-1', null, null, 'RUM-0001');
        $paid = new PaymentOrdered('7a7e9b0e-5f0a-4c53-9a53-1d2b0c3d4e5f', '2026-11-10T09:00:00Z', 'tenant-1', 'payment-1', 'INV-1', 'account-1', 'M', 2026, 10, '12345678', null, 'RUM-0001');

        $sepaRow = OutboxMessage::of($sepa)->row();
        $paidRow = OutboxMessage::of($paid)->row();

        static::assertNull($sepaRow['aggregate_id']);
        static::assertNull($sepaRow['external_reference']);
        static::assertSame('payment-1', $paidRow['aggregate_id']);
        static::assertSame('INV-1', $paidRow['external_reference']);
    }

    public function testAnOutboxMessageIsWrittenWithDbalAsAPendingEntry(): void
    {
        $event = new MandateCancelled('8f7e9b0e-5f0a-4c53-9a53-1d2b0c3d4e5f', '2026-11-10T09:00:00Z', 'tenant-1', 'mandate-1', null, 'account-1', 'cancelled');

        $this->connection->insert('urssaf_tdae_outbox', OutboxMessage::of($event)->row(), OutboxMessage::TYPES);

        static::assertSame(
            ['sequence' => 1, 'event_id' => '8f7e9b0e-5f0a-4c53-9a53-1d2b0c3d4e5f', 'dispatched_at' => null, 'attempts' => 0, 'external_reference' => null],
            $this->connection->fetchAssociative('SELECT sequence, event_id, dispatched_at, attempts, external_reference FROM urssaf_tdae_outbox'),
        );
        $payload = $this->connection->fetchOne('SELECT payload FROM urssaf_tdae_outbox');
        static::assertIsString($payload);
        static::assertSame($event->toArray(), json_decode($payload, true, 8, \JSON_THROW_ON_ERROR));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('urssaf_tdae_outbox', OutboxMessage::of($event)->row(), OutboxMessage::TYPES);
    }
}
