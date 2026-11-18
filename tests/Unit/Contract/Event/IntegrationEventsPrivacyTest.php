<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Contract\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEventCodec;

/**
 * ADR 0014 (Consequences): no catalogue class has a property named after the SIRET, a birth date, bank data or an amount.
 */
final class IntegrationEventsPrivacyTest extends TestCase
{
    /**
     * What ADR 0014 (Consequences) keeps out of every payload: SIRET, birth date, bank data, amounts.
     */
    private const FORBIDDEN_PROPERTY = '/siret|birth|naissance|iban|bic|titulaire|holder|adresse|address|montant|amount|turnover|chiffre|contribution|due|exigibilite/i';

    public function testNoPropertyCarriesAPersonalDataName(): void
    {
        foreach (IntegrationEventCodec::CLASSES as $class) {
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                static::assertDoesNotMatchRegularExpression(self::FORBIDDEN_PROPERTY, $property->getName(), $class . '::$' . $property->getName());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAllowedPropertyNames(): iterable
    {
        yield 'rum' => ['rum'];
        yield 'certificat' => ['certificat'];
        yield 'period number' => ['periodNumber'];
        yield 'id declaration' => ['idDeclaration'];
        yield 'id telepaiement' => ['idTelepaiement'];
        yield 'account id' => ['accountId'];
    }

    #[DataProvider('provideAllowedPropertyNames')]
    public function testTheForbiddenListDoesNotTripOnTheTechnicalIdentifiers(string $name): void
    {
        static::assertDoesNotMatchRegularExpression(self::FORBIDDEN_PROPERTY, $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideForbiddenPropertyNames(): iterable
    {
        foreach (['siret', 'birthDate', 'dateNaissance', 'iban', 'bic', 'titulaireCompte', 'accountHolder', 'adresse1', 'address', 'montant', 'amount', 'turnover', 'chiffreAffaires', 'contributions', 'dueDate', 'exigibilite'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('provideForbiddenPropertyNames')]
    public function testTheForbiddenListCatchesPersonalDataNames(string $name): void
    {
        static::assertMatchesRegularExpression(self::FORBIDDEN_PROPERTY, $name);
    }
}
