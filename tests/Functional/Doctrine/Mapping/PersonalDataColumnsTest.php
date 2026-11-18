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

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;

/**
 * ADR 0010: the IBAN, the holder and the address of a payment order are never persisted (a SEPA mandate holds them at
 * URSSAF only), and the only birth date of the database is the one of the account, in a column type of its own.
 */
final class PersonalDataColumnsTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
    }

    public function testNoColumnIsNamedAfterPersonalData(): void
    {
        $columns = array_merge(...array_values(SchemaReader::columnNames($this->entityManager->getConnection())));

        static::assertGreaterThan(60, \count($columns));
        static::assertSame(
            [],
            array_filter(
                $columns,
                static fn(string $name): bool => preg_match('/^(iban|bic|titulaire|holder|adresse|address|code_postal|postal_code|ville|city|pays|country|nir|sales|furnished_rental|commercial_services|other_services|cipav_receipts|turnover)$/', $name) === 1,
            ),
        );
    }

    public function testNoColumnNameContainsPersonalData(): void
    {
        $columns = array_merge(...array_values(SchemaReader::columnNames($this->entityManager->getConnection())));

        static::assertSame([], array_filter($columns, static fn(string $name): bool => preg_match('/(^|_)(iban|bic|holder|titulaire|address|adresse|postal|city|ville|country|pays|nir|email|phone|turnover|chiffre)($|_)/i', $name) === 1));
    }

    public function testTheBirthDateIsStoredInTheAccountsOnly(): void
    {
        $birthColumns = array_filter(array_map(
            static fn(array $names): array => array_values(array_filter($names, static fn(string $name): bool => str_contains($name, 'birth'))),
            SchemaReader::columnNames($this->entityManager->getConnection()),
        ));

        static::assertSame(['urssaf_tdae_accounts' => ['birth_date']], $birthColumns);
    }

    public function testNothingPersonalReachesAnyRowOfTheDatabase(): void
    {
        $this->entityManager->persist(ContractSamples::account(1));
        $this->entityManager->persist(ContractSamples::mandate(1, 1));
        $this->entityManager->persist(ContractSamples::declarationIn(DeclarationState::Declared, 1, 1, ContractSamples::monthly()));
        $this->entityManager->persist(ContractSamples::paymentIn(PaymentState::Paid, 1, 1, 'INV-1', ContractSamples::monthly()));
        $this->entityManager->flush();

        $stored = SchemaReader::storedValues($this->entityManager->getConnection());

        static::assertContains('73282932000075', $stored);
        static::assertContains('1975-10-01', $stored);
        foreach ($stored as $value) {
            foreach (['FR76', 'BNPAFRPP', 'FICTITIOUS', 'rue '] as $personal) {
                static::assertStringNotContainsString($personal, $value);
            }
        }
    }
}
