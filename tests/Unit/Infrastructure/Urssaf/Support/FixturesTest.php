<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @mago-expect lint:too-many-methods
 */
final class FixturesTest extends TestCase
{
    public function testLoadReturnsTheRawFile(): void
    {
        static::assertStringContainsString('"PARAM_INVALIDE"', Fixtures::load('error_param_invalide'));
    }

    public function testJsonDecodesTheFile(): void
    {
        static::assertSame('PARAM_INVALIDE', Fixtures::json('error_param_invalide')['code']);
    }

    public function testDecodeReadsAnObjectOrAList(): void
    {
        static::assertSame(['a' => 1], Fixtures::decode('{"a":1}'));
        static::assertSame([1, 2], Fixtures::decode('[1,2]'));
    }

    public function testDecodeRefusesAScalar(): void
    {
        $this->expectException(RuntimeException::class);

        Fixtures::decode('12');
    }

    public function testSectionReturnsAMemberObject(): void
    {
        static::assertSame(['annee' => '2019', 'mois' => '12'], Fixtures::section('pay_request', 'periode'));
    }

    public function testSectionRefusesAMissingOrScalarMember(): void
    {
        foreach (['absent', 'montant'] as $key) {
            try {
                Fixtures::section('pay_request', $key);
                static::fail('Expected a RuntimeException for ' . $key);
            } catch (RuntimeException $exception) {
                static::assertSame('Not a JSON object or array', $exception->getMessage());
            }
        }
    }

    public function testNamesListsTheFixturesByPrefix(): void
    {
        $names = Fixtures::names('error_');

        static::assertCount(12, $names);
        static::assertContains('error_technique', $names);
        static::assertNotContains('declare_response', $names);
        static::assertSame($names, array_values(array_unique($names)));
        static::assertContains('declare_response', Fixtures::names());
        static::assertSame([], Fixtures::names('nothing_like_this'));
    }

    public function testTheFixtureSetIsKnown(): void
    {
        static::assertSame([
            'account_response',
            'declare_request',
            'declare_response',
            'error_abn_inconnu',
            'error_appel_inconnu',
            'error_cpte_inconnu',
            'error_mdt_hors_periode',
            'error_mdt_inconnu',
            'error_param_invalide',
            'error_param_multiples',
            'error_periodicite_invalide',
            'error_sepa_generic',
            'error_sepa_mandate_already_exists',
            'error_siret_deja_mandate',
            'error_technique',
            'estimate_request',
            'estimate_response',
            'pay_request',
            'pay_response',
            'sepa_mandate_add_request',
            'sepa_mandate_response',
            'sepa_mandates_search_response',
        ], Fixtures::names());
    }

    public function testWrappedPutsTheFixtureUnderTheWrapperKey(): void
    {
        $wrapped = Fixtures::wrapped('pay_response', 'OutputTelepaiement');

        static::assertSame(['OutputTelepaiement' => Fixtures::json('pay_response')], Fixtures::decode($wrapped));
        static::assertStringStartsWith('{"OutputTelepaiement":{', $wrapped);
    }

    public function testWithIntegerPeriodsCastsOnlyThePeriodDigitStrings(): void
    {
        $periods = Fixtures::decode(Fixtures::withIntegerPeriods('pay_request'));

        static::assertSame(['annee' => 2019, 'mois' => 12], $periods['periode']);
        static::assertSame(Fixtures::json('pay_request')['montant'], $periods['montant']);
        static::assertSame(['annee' => 2019, 'trimestre' => 1], Fixtures::decode(Fixtures::withIntegerPeriods('estimate_response'))['periode']);
    }

    public function testWithIntegerPeriodsKeepsEveryOtherValue(): void
    {
        $expected = Fixtures::json('declare_response');
        $actual = Fixtures::decode(Fixtures::withIntegerPeriods('declare_response'));
        unset($expected['periode'], $actual['periode']);

        static::assertSame($expected, $actual);
        static::assertSame(
            Fixtures::json('sepa_mandate_response'),
            Fixtures::decode(Fixtures::withIntegerPeriods('sepa_mandate_response')),
        );
        static::assertStringContainsString('"ville":"Rennes cedex 9 "', Fixtures::withIntegerPeriods('sepa_mandate_response'));
        static::assertStringContainsString('’', Fixtures::withIntegerPeriods('sepa_mandates_search_response'));
        static::assertStringNotContainsString('\\/', Fixtures::withIntegerPeriods('sepa_mandates_search_response'));
    }

    public function testLoadRefusesAMissingFixture(): void
    {
        $this->expectException(RuntimeException::class);

        Fixtures::load('does_not_exist');
    }
}
