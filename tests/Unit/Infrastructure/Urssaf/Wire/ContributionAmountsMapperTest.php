<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ContributionAmountsMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\Payload;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

/**
 * @mago-expect lint:too-many-methods
 */
final class ContributionAmountsMapperTest extends TestCase
{
    private const ALL_FIELDS = [
        'montantTotal' => 1,
        'montantVente' => 2,
        'montantLocation' => 3,
        'montantPrestationService' => 4,
        'montantPrestationAutres' => 5,
        'montantCipav' => 6,
        'montantFormationProfessionnelle' => 7,
        'montantVenteVL' => 8,
        'montantLocationVL' => 9,
        'montantPrestationServiceVL' => 10,
        'montantPrestationAutresVL' => 11,
        'montantCipavVL' => 12,
        'taxeCMAVente' => 13,
        'taxeCMAService' => 14,
        'taxeCCIVente' => 15,
        'taxeCCIService' => 16,
        'montantRegulAcre' => 17,
        'montantDeduction' => 18,
    ];

    public function testTheOfficialAmountsAreMapped(): void
    {
        $amounts = ContributionAmountsMapper::fromWire(
            JsonDecoder::decode(Fixtures::load('declare_response'))->requireObject('montants'),
        );

        static::assertSame(1678, $amounts->total()->toInt());
        static::assertSame(128, $amounts->sales()?->toInt());
        static::assertSame(0, $amounts->furnishedRental()?->toInt());
        static::assertSame(660, $amounts->commercialServices()?->toInt());
        static::assertSame(880, $amounts->otherServices()?->toInt());
        static::assertSame(0, $amounts->cipav()?->toInt());
        static::assertSame(10, $amounts->vocationalTraining()?->toInt());
        static::assertSame(0, $amounts->chamberFees()->cmaSales()?->toInt());
        static::assertSame(0, $amounts->chamberFees()->cmaServices()?->toInt());
        static::assertSame(0, $amounts->chamberFees()->cciSales()?->toInt());
        static::assertSame(0, $amounts->chamberFees()->cciServices()?->toInt());
        static::assertNotInstanceOf(Euros::class, $amounts->liberatory()->sales());
        static::assertNotInstanceOf(Euros::class, $amounts->acreRegularisation());
        static::assertNotInstanceOf(Euros::class, $amounts->deduction());
    }

    public function testEveryFieldGoesToItsOwnAccessor(): void
    {
        $amounts = ContributionAmountsMapper::fromWire(self::payload(self::ALL_FIELDS));

        static::assertSame(
            range(1, 18),
            self::flatten($amounts),
        );
    }

    public function testOnlyTheTotalIsRequired(): void
    {
        $amounts = ContributionAmountsMapper::fromWire(self::payload(['montantTotal' => 0]));

        static::assertSame(0, $amounts->total()->toInt());
        static::assertSame(
            array_fill(0, 17, null),
            \array_slice(self::flatten($amounts), 1),
        );
    }

    public function testAnAbsentAmountIsNullNeverZero(): void
    {
        $amounts = ContributionAmountsMapper::fromWire(self::payload(['montantTotal' => 5, 'montantVente' => 0]));

        static::assertSame(0, $amounts->sales()?->toInt());
        static::assertNotInstanceOf(Euros::class, $amounts->furnishedRental());
        static::assertNotInstanceOf(Euros::class, $amounts->deduction());
    }

    public function testAnExplicitNullIsAbsent(): void
    {
        $amounts = ContributionAmountsMapper::fromWire(self::payload(['montantTotal' => 5, 'montantDeduction' => null]));

        static::assertNotInstanceOf(Euros::class, $amounts->deduction());
    }

    public function testAmountsAreSigned(): void
    {
        $amounts = ContributionAmountsMapper::fromWire(
            self::payload(['montantTotal' => -50, 'montantRegulAcre' => -3, 'montantDeduction' => -1, 'taxeCMAVente' => -2]),
        );

        static::assertSame(-50, $amounts->total()->toInt());
        static::assertSame(-3, $amounts->acreRegularisation()?->toInt());
        static::assertSame(-1, $amounts->deduction()?->toInt());
        static::assertSame(-2, $amounts->chamberFees()->cmaSales()?->toInt());
    }

    public function testIntegralFloatsAreAccepted(): void
    {
        $amounts = ContributionAmountsMapper::fromWire(JsonDecoder::decode('{"montantTotal":1678.0,"montantVente":12e1}'));

        static::assertSame(1678, $amounts->total()->toInt());
        static::assertSame(120, $amounts->sales()?->toInt());
    }

    public function testAMissingTotalIsRefused(): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "montantTotal" is missing.',
            static fn(): ContributionAmounts => ContributionAmountsMapper::fromWire(self::payload(['montantVente' => 1])),
        );
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "montantTotal" is missing.',
            static fn(): ContributionAmounts => ContributionAmountsMapper::fromWire(self::payload(['montantTotal' => null])),
        );
    }

    public function testAFractionalTotalIsRefused(): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "montantTotal" is not a whole euro amount.',
            static fn(): ContributionAmounts => ContributionAmountsMapper::fromWire(JsonDecoder::decode('{"montantTotal":1678.5}')),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOptionalFields(): iterable
    {
        foreach (array_keys(\array_slice(self::ALL_FIELDS, 1)) as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('provideOptionalFields')]
    public function testEveryOptionalAmountIsValidated(string $field): void
    {
        WireAssertions::assertUnexpected(
            \sprintf('Unexpected URSSAF response: field "%s" is not a number.', $field),
            static fn(): ContributionAmounts => ContributionAmountsMapper::fromWire(self::payload(['montantTotal' => 1, $field => '12'])),
        );
        WireAssertions::assertUnexpected(
            \sprintf('Unexpected URSSAF response: field "%s" is not a whole euro amount.', $field),
            static fn(): ContributionAmounts => ContributionAmountsMapper::fromWire(self::payload(['montantTotal' => 1, $field => 1.5])),
        );
    }

    public function testBooleansAndStringsAreRefusedForTheTotal(): void
    {
        foreach ([true, '1678', [], ['a' => 1]] as $value) {
            WireAssertions::assertUnexpected(
                'Unexpected URSSAF response: field "montantTotal" is not a number.',
                static fn(): ContributionAmounts => ContributionAmountsMapper::fromWire(self::payload(['montantTotal' => $value])),
            );
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function payload(array $fields): Payload
    {
        return JsonDecoder::decode(json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<int|null> the 18 amounts in the order of ALL_FIELDS
     */
    private static function flatten(ContributionAmounts $amounts): array
    {
        $values = [
            $amounts->total(),
            $amounts->sales(),
            $amounts->furnishedRental(),
            $amounts->commercialServices(),
            $amounts->otherServices(),
            $amounts->cipav(),
            $amounts->vocationalTraining(),
            $amounts->liberatory()->sales(),
            $amounts->liberatory()->furnishedRental(),
            $amounts->liberatory()->commercialServices(),
            $amounts->liberatory()->otherServices(),
            $amounts->liberatory()->cipav(),
            $amounts->chamberFees()->cmaSales(),
            $amounts->chamberFees()->cmaServices(),
            $amounts->chamberFees()->cciSales(),
            $amounts->chamberFees()->cciServices(),
            $amounts->acreRegularisation(),
            $amounts->deduction(),
        ];

        return array_map(static fn(?Euros $euros): ?int => $euros?->toInt(), $values);
    }
}
