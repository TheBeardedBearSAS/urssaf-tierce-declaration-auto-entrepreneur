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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PeriodEncoding;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class PeriodEncodingTest extends TestCase
{
    public function testAMonthlyPeriodIsEncodedAsIntegers(): void
    {
        $encoded = PeriodEncoding::encode(MonthlyPeriod::of(2019, 12));

        static::assertSame(['annee' => 2019, 'mois' => 12], $encoded);
        static::assertSame('{"annee":2019,"mois":12}', JsonEncoder::encode($encoded));
    }

    public function testAQuarterlyPeriodIsEncodedAsIntegers(): void
    {
        $encoded = PeriodEncoding::encode(QuarterlyPeriod::of(2019, 3));

        static::assertSame(['annee' => 2019, 'trimestre' => 3], $encoded);
        static::assertSame('{"annee":2019,"trimestre":3}', JsonEncoder::encode($encoded));
    }

    /**
     * JSON, periodicity, year and month or quarter number of the expected period.
     *
     * @return iterable<string, array{string, Periodicity, int, int}>
     */
    public static function provideValidPeriods(): iterable
    {
        yield 'integers monthly' => ['{"annee":2019,"mois":12}', Periodicity::Monthly, 2019, 12];
        yield 'integers quarterly' => ['{"annee":2019,"trimestre":3}', Periodicity::Quarterly, 2019, 3];
        yield 'strings monthly' => ['{"annee":"2019","mois":"12"}', Periodicity::Monthly, 2019, 12];
        yield 'strings quarterly' => ['{"annee":"2019","trimestre":"1"}', Periodicity::Quarterly, 2019, 1];
        yield 'zero padded month' => ['{"annee":"2019","mois":"03"}', Periodicity::Monthly, 2019, 3];
        yield 'mixed types' => ['{"annee":2019,"trimestre":"4"}', Periodicity::Quarterly, 2019, 4];
        yield 'null quarter' => ['{"annee":2019,"mois":1,"trimestre":null}', Periodicity::Monthly, 2019, 1];
        yield 'null month' => ['{"annee":2019,"mois":null,"trimestre":2}', Periodicity::Quarterly, 2019, 2];
        yield 'extra field ignored' => ['{"annee":2019,"mois":6,"x":1}', Periodicity::Monthly, 2019, 6];
        yield 'first year' => ['{"annee":1,"mois":1}', Periodicity::Monthly, 1, 1];
        yield 'last year' => ['{"annee":9999,"trimestre":4}', Periodicity::Quarterly, 9999, 4];
    }

    /**
     * @throws InvalidValue
     */
    #[DataProvider('provideValidPeriods')]
    public function testDecode(string $json, Periodicity $periodicity, int $year, int $number): void
    {
        $period = PeriodEncoding::decode(JsonDecoder::decode($json));

        static::assertTrue(DeclarationPeriods::fromParts($periodicity, $year, $number)->equals($period));
        static::assertSame($periodicity, $period->periodicity());
        static::assertSame($year, $period->year());
        static::assertSame($number, DeclarationPeriods::numberOf($period));
    }

    public function testDecodeOfTheFixtures(): void
    {
        $quarter = JsonDecoder::decode(Fixtures::load('estimate_response'))->requireObject('periode');
        $month = JsonDecoder::decode(Fixtures::load('pay_request'))->requireObject('periode');

        static::assertSame('2019-Q1', PeriodEncoding::decode($quarter)->toString());
        static::assertSame('2019-12', PeriodEncoding::decode($month)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidPeriods(): iterable
    {
        yield 'both' => ['{"annee":2019,"mois":1,"trimestre":1}', 'field "periode" has both mois and trimestre'];
        yield 'neither' => ['{"annee":2019}', 'field "periode" has neither mois nor trimestre'];
        yield 'neither null' => ['{"annee":2019,"mois":null,"trimestre":null}', 'field "periode" has neither mois nor trimestre'];
        yield 'month 13' => ['{"annee":2019,"mois":13}', 'field "periode" is out of range'];
        yield 'month 0' => ['{"annee":2019,"mois":0}', 'field "periode" is out of range'];
        yield 'quarter 5' => ['{"annee":2019,"trimestre":5}', 'field "periode" is out of range'];
        yield 'quarter 0' => ['{"annee":2019,"trimestre":0}', 'field "periode" is out of range'];
        yield 'year 0' => ['{"annee":0,"mois":1}', 'field "periode" is out of range'];
        yield 'year 10000' => ['{"annee":10000,"mois":1}', 'field "periode" is out of range'];
        yield 'year missing' => ['{"mois":1}', 'field "annee" is missing'];
        yield 'year null' => ['{"annee":null,"mois":1}', 'field "annee" is missing'];
        yield 'year not digits' => ['{"annee":"20x9","mois":1}', 'field "annee" is not an integer'];
        yield 'year decimal' => ['{"annee":2019.0,"mois":1}', 'field "annee" is not an integer'];
        yield 'month signed' => ['{"annee":2019,"mois":"-1"}', 'field "mois" is not an integer'];
        yield 'month boolean' => ['{"annee":2019,"mois":true}', 'field "mois" is not an integer'];
        yield 'quarter decimal string' => ['{"annee":2019,"trimestre":"1.0"}', 'field "trimestre" is not an integer'];
        yield 'quarter with space' => ['{"annee":2019,"trimestre":" 1"}', 'field "trimestre" is not an integer'];
    }

    #[DataProvider('provideInvalidPeriods')]
    public function testDecodeRefusesAnInvalidPeriod(string $json, string $reason): void
    {
        $payload = JsonDecoder::decode($json);

        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: ' . $reason . '.',
            static fn(): DeclarationPeriod => PeriodEncoding::decode($payload),
        );
    }

    public function testAnOutOfRangePeriodKeepsTheDomainCause(): void
    {
        $exception = WireAssertions::caught(
            static fn(): DeclarationPeriod => PeriodEncoding::decode(JsonDecoder::decode('{"annee":2019,"mois":13}')),
        );

        static::assertInstanceOf(InvalidValue::class, $exception->getPrevious());
    }

    public function testTheOtherRefusalsCarryNoCause(): void
    {
        $exception = WireAssertions::caught(
            static fn(): DeclarationPeriod => PeriodEncoding::decode(JsonDecoder::decode('{"annee":2019}')),
        );

        static::assertNull($exception->getPrevious());
    }
}
