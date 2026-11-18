<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\ApiErrorCode;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;

final class ApiErrorCodeCatalogueTest extends TestCase
{
    private const ALL_BUT_SEPA = [
        Operation::FindAccount,
        Operation::NotifyMandate,
        Operation::CancelMandate,
        Operation::Estimate,
        Operation::Declare,
        Operation::Pay,
    ];

    /**
     * Every code of api-reference §3.4, verbatim, with the operations that document it.
     *
     * @return iterable<string, array{string, ApiErrorCode, list<Operation>}>
     */
    public static function provideCatalogue(): iterable
    {
        yield 'PARAM_INVALIDE' => ['PARAM_INVALIDE', ApiErrorCode::InvalidParameter, self::ALL_BUT_SEPA];
        yield 'ERR_PARAM_MULTIPLES' => ['ERR_PARAM_MULTIPLES', ApiErrorCode::MultipleIdentifiers, [Operation::Estimate, Operation::Declare]];
        yield 'CPTE_INCONNU' => ['CPTE_INCONNU', ApiErrorCode::AccountUnknown, self::ALL_BUT_SEPA];
        yield 'ABN_INCONNU' => ['ABN_INCONNU', ApiErrorCode::NoActiveUser, [Operation::FindAccount]];
        yield 'SIRET_DEJA_MANDATE' => ['SIRET_DEJA_MANDATE', ApiErrorCode::SiretAlreadyMandated, [Operation::NotifyMandate]];
        yield 'MDT_INCONNU' => ['MDT_INCONNU', ApiErrorCode::MandateUnknown, [Operation::Estimate, Operation::Declare, Operation::Pay]];
        yield 'MDT_HORS_PERIODE' => ['MDT_HORS_PERIODE', ApiErrorCode::MandateOutOfPeriod, [Operation::Estimate, Operation::Declare]];
        yield 'APPEL_INCONNU' => ['APPEL_INCONNU', ApiErrorCode::PeriodNotOpen, [Operation::Declare]];
        yield 'PERIODICITE_INVALIDE' => ['PERIODICITE_INVALIDE', ApiErrorCode::PeriodicityInvalid, [Operation::Declare]];
        yield 'ERREUR_MANDAT_ALREADYEXIST' => ['ERREUR_MANDAT_ALREADYEXIST', ApiErrorCode::SepaMandateAlreadyExists, [Operation::RegisterSepaMandate]];
        yield 'ERREUR_IBAN_INVALIDE' => ['ERREUR_IBAN_INVALIDE', ApiErrorCode::IbanInvalid, [Operation::RegisterSepaMandate]];
        yield 'ERREUR_RIB_INVALIDE' => ['ERREUR_RIB_INVALIDE', ApiErrorCode::RibInvalid, [Operation::RegisterSepaMandate]];
        yield 'BANQUE_INCONNUE' => ['BANQUE_INCONNUE', ApiErrorCode::BankUnknown, [Operation::RegisterSepaMandate]];
        yield 'ERR_TECH' => ['ERR_TECH', ApiErrorCode::SepaTechnicalError, [Operation::RegisterSepaMandate, Operation::RevokeSepaMandate, Operation::ListSepaMandates]];
        yield 'ERR_TECHNIQUE' => ['ERR_TECHNIQUE', ApiErrorCode::TechnicalError, Operation::cases()];
    }

    /**
     * @param list<Operation> $operations
     */
    #[DataProvider('provideCatalogue')]
    public function testEveryCodeHasItsWireValueAndOperations(string $wire, ApiErrorCode $case, array $operations): void
    {
        static::assertSame($wire, $case->value);
        static::assertSame($case, ApiErrorCode::fromCode($wire));
        static::assertSame($operations, $case->operations());
    }

    public function testTheCatalogueHasNoOtherCase(): void
    {
        static::assertCount(\count(iterator_to_array(self::provideCatalogue(), false)), ApiErrorCode::cases());
        static::assertCount(15, ApiErrorCode::cases());
    }

    public function testEveryOperationIsDocumentedByAtLeastOneCode(): void
    {
        foreach (Operation::cases() as $operation) {
            $codes = array_filter(
                ApiErrorCode::cases(),
                static fn(ApiErrorCode $code): bool => \in_array($operation, $code->operations(), true),
            );
            static::assertNotSame([], $codes, $operation->value);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideErrorFixtures(): iterable
    {
        foreach (Fixtures::names('error_') as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('provideErrorFixtures')]
    public function testTheCodeOfEveryErrorFixtureIsKnown(string $name): void
    {
        static::assertInstanceOf(ApiErrorCode::class, ApiErrorCode::fromCode(Fixtures::string($name, 'code')));
    }
}
