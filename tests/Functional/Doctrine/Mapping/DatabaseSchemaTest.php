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

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;

/**
 * The schema the mapping builds on SQLite (the platform of the CI, ADR 0016): tables, exact columns, indexes and unique
 * constraints. A change of the schema is a change of the migrations of the host applications: it must be deliberate.
 */
final class DatabaseSchemaTest extends TestCase
{
    /**
     * Columns by table: name => nullable.
     */
    private const COLUMNS = [
        'urssaf_tdae_accounts' => [
            'acre_exemption' => true,
            'affiliation_date' => true,
            'birth_date' => true,
            'created_at' => false,
            'id' => false,
            'last_synced_at' => false,
            'mandate_present' => false,
            'periodicity' => false,
            'siret' => false,
            'tenant_id' => false,
            'updated_at' => false,
            'version' => false,
        ],
        'urssaf_tdae_active_mandates' => [
            'mandate_id' => false,
            'siret' => false,
            'tenant_id' => false,
        ],
        'urssaf_tdae_current_declarations' => [
            'declaration_record_id' => false,
            'period_number' => false,
            'period_year' => false,
            'periodicity' => false,
            'siret' => false,
            'tenant_id' => false,
        ],
        'urssaf_tdae_current_payments' => [
            'payment_record_id' => false,
            'payment_reference' => false,
            'tenant_id' => false,
        ],
        'urssaf_tdae_declaration_records' => [
            'accepted_after_supersession' => false,
            'account_id' => true,
            'created_at' => false,
            'errors' => false,
            'fingerprint' => false,
            'id' => false,
            'last_attempt_at' => true,
            'outcome_unknown_reason' => true,
            'period_number' => false,
            'period_year' => false,
            'periodicity' => false,
            'result' => true,
            'siret' => false,
            'state' => false,
            'submission_attempts' => false,
            'superseded_by' => true,
            'supersedes' => true,
            'tenant_id' => false,
            'updated_at' => false,
            'version' => false,
        ],
        'urssaf_tdae_mandates' => [
            'account_id' => true,
            'cancellation_origin' => true,
            'cancelled_at' => true,
            'id' => false,
            'notified_at' => false,
            'origin' => false,
            'siret' => false,
            'state' => false,
            'tenant_id' => false,
            'updated_at' => false,
            'version' => false,
        ],
        'urssaf_tdae_outbox' => [
            'aggregate_id' => true,
            'attempts' => false,
            'data' => false,
            'dispatched_at' => true,
            'event_id' => false,
            'external_reference' => true,
            'last_error' => true,
            'occurred_at' => false,
            'payload' => false,
            'sequence' => false,
            'tenant_id' => false,
            'type' => false,
            'version' => false,
        ],
        'urssaf_tdae_payment_records' => [
            'account_id' => true,
            'created_at' => false,
            'errors' => false,
            'fingerprint' => false,
            'id' => false,
            'last_attempt_at' => true,
            'montant' => false,
            'outcome_unknown_reason' => true,
            'payment_reference' => false,
            'period_number' => false,
            'period_year' => false,
            'periodicity' => false,
            'result' => true,
            'rum' => true,
            'siret' => false,
            'state' => false,
            'submission_attempts' => false,
            'superseded_by' => true,
            'supersedes' => true,
            'tenant_id' => false,
            'updated_at' => false,
            'version' => false,
        ],
    ];

    /**
     * Indexes by table: name => [unique, columns].
     */
    private const INDEXES = [
        'urssaf_tdae_accounts' => [
            'idx_urssaf_tdae_account_sync' => [false, ['tenant_id', 'last_synced_at']],
            'uniq_urssaf_tdae_account_siret' => [true, ['tenant_id', 'siret']],
        ],
        'urssaf_tdae_active_mandates' => [
            'uniq_urssaf_tdae_active_mandate_id' => [true, ['mandate_id']],
        ],
        'urssaf_tdae_current_declarations' => [
            'uniq_urssaf_tdae_current_declaration_id' => [true, ['declaration_record_id']],
        ],
        'urssaf_tdae_current_payments' => [
            'uniq_urssaf_tdae_current_payment_id' => [true, ['payment_record_id']],
        ],
        'urssaf_tdae_declaration_records' => [
            'idx_urssaf_tdae_declaration_period' => [false, ['tenant_id', 'siret', 'periodicity', 'period_year', 'period_number']],
            'idx_urssaf_tdae_declaration_siret' => [false, ['tenant_id', 'siret', 'state']],
            'idx_urssaf_tdae_declaration_stale' => [false, ['state', 'last_attempt_at']],
            'idx_urssaf_tdae_declaration_state' => [false, ['tenant_id', 'state', 'updated_at']],
        ],
        'urssaf_tdae_mandates' => [
            'idx_urssaf_tdae_mandate_siret' => [false, ['tenant_id', 'siret', 'notified_at']],
            'idx_urssaf_tdae_mandate_state' => [false, ['tenant_id', 'state']],
        ],
        'urssaf_tdae_outbox' => [
            'idx_urssaf_tdae_outbox_pending' => [false, ['dispatched_at', 'sequence']],
            'uniq_urssaf_tdae_outbox_event_id' => [true, ['event_id']],
        ],
        'urssaf_tdae_payment_records' => [
            'idx_urssaf_tdae_payment_period' => [false, ['tenant_id', 'siret', 'periodicity', 'period_year', 'period_number']],
            'idx_urssaf_tdae_payment_reference' => [false, ['tenant_id', 'payment_reference']],
            'idx_urssaf_tdae_payment_siret' => [false, ['tenant_id', 'siret', 'state']],
            'idx_urssaf_tdae_payment_stale' => [false, ['state', 'last_attempt_at']],
            'idx_urssaf_tdae_payment_state' => [false, ['tenant_id', 'state', 'updated_at']],
        ],
    ];

    /**
     * @var list<Table>
     */
    private array $tables;

    protected function setUp(): void
    {
        $this->tables = SchemaReader::tables(TestDatabase::entityManager()->getConnection());
    }

    /**
     * @return iterable<string, array{string}> the expected tables
     */
    public static function tables(): iterable
    {
        foreach (array_keys(self::COLUMNS) as $name) {
            yield $name => [$name];
        }
    }

    public function testTheTablesAreExactlyTheEightExpectedOnesAndPrefixed(): void
    {
        $names = array_map(SchemaReader::tableName(...), $this->tables);
        sort($names);

        static::assertCount(8, $names);
        static::assertSame(array_keys(self::COLUMNS), $names);
        static::assertSame([], array_filter($names, static fn(string $name): bool => !str_starts_with($name, DoctrineIntegration::TABLE_PREFIX)));
    }

    public function testThereIsNoProgressTable(): void
    {
        static::assertSame([], array_filter(array_map(SchemaReader::tableName(...), $this->tables), static fn(string $name): bool => str_contains($name, 'progress')));
    }

    #[DataProvider('tables')]
    public function testTheTableHoldsExactlyTheExpectedColumns(string $name): void
    {
        $table = $this->table($name);
        $columns = array_combine(
            array_map(SchemaReader::columnName(...), array_values($table->getColumns())),
            array_map(static fn(Column $column): bool => !$column->getNotnull(), array_values($table->getColumns())),
        );
        ksort($columns);

        static::assertSame(self::COLUMNS[$name], $columns);
    }

    #[DataProvider('tables')]
    public function testTheTableHoldsExactlyTheExpectedIndexesAndUniqueConstraints(string $name): void
    {
        static::assertSame(self::INDEXES[$name], SchemaReader::indexes($this->table($name)));
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('primaryKeys')]
    public function testTheTableHasTheExpectedPrimaryKey(string $name, array $columns): void
    {
        static::assertSame($columns, SchemaReader::primaryKey($this->table($name)));
    }

    /**
     * @return iterable<string, array{string, list<string>}> table, columns of the primary key
     */
    public static function primaryKeys(): iterable
    {
        yield 'accounts' => ['urssaf_tdae_accounts', ['id']];
        yield 'mandates' => ['urssaf_tdae_mandates', ['id']];
        yield 'declaration records' => ['urssaf_tdae_declaration_records', ['id']];
        yield 'payment records' => ['urssaf_tdae_payment_records', ['id']];
        yield 'outbox' => ['urssaf_tdae_outbox', ['sequence']];
        yield 'active mandates: one per tenant and SIRET' => ['urssaf_tdae_active_mandates', ['tenant_id', 'siret']];
        yield 'current declarations: one per tenant, SIRET and period' => ['urssaf_tdae_current_declarations', ['tenant_id', 'siret', 'periodicity', 'period_year', 'period_number']];
        yield 'current payments: one per tenant and payment reference' => ['urssaf_tdae_current_payments', ['tenant_id', 'payment_reference']];
    }

    private function table(string $name): Table
    {
        foreach ($this->tables as $table) {
            if (SchemaReader::tableName($table) === $name) {
                return $table;
            }
        }

        static::fail('Unknown table ' . $name);
    }
}
