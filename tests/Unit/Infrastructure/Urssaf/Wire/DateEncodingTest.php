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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DateEncoding;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class DateEncodingTest extends TestCase
{
    public function testEncodeWritesADateOnlyString(): void
    {
        static::assertSame('2020-01-31', DateEncoding::encode(CalendarDate::fromString('2020-01-31')));
    }

    public function testDecodeIsoDate(): void
    {
        static::assertSame('2020-01-31', DateEncoding::decodeIsoDate('2020-01-31', 'dateExigibilite')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidIsoDates(): iterable
    {
        yield 'with time and zone' => ['2020-01-31T00:00:00Z'];
        yield 'slashes' => ['2020/01/31'];
        yield 'not a calendar day' => ['2023-02-29'];
        yield 'placeholder' => ['string'];
        yield 'empty' => [''];
        yield 'french format' => ['31/01/2020'];
        yield 'trailing newline' => ["2020-01-31\n"];
    }

    #[DataProvider('provideInvalidIsoDates')]
    public function testDecodeIsoDateRefusesAnythingElse(string $value): void
    {
        $exception = WireAssertions::caught(static fn(): CalendarDate => DateEncoding::decodeIsoDate($value, 'dateExigibilite'));

        static::assertSame('Unexpected URSSAF response: field "dateExigibilite" is not a Y-m-d date.', $exception->getMessage());
        static::assertInstanceOf(InvalidValue::class, $exception->getPrevious());
        static::assertStringNotContainsString('2023', $exception->getMessage());
    }

    public function testDecodeFrenchDate(): void
    {
        static::assertSame('2010-03-01', DateEncoding::decodeFrenchDate('01/03/2010', 'date_etat')->toString());
        static::assertSame('2024-02-29', DateEncoding::decodeFrenchDate('29/02/2024', 'date_etat')->toString());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideInvalidFrenchDates(): iterable
    {
        yield 'trailing newline' => ["01/03/2010\n", false];
        yield 'leading space' => [' 01/03/2010', false];
        yield 'single digits' => ['1/3/2010', false];
        yield 'iso format' => ['2010-03-01', false];
        yield 'two digit year' => ['01/03/10', false];
        yield 'five digit year' => ['01/03/20100', false];
        yield 'empty' => ['', false];
        yield 'placeholder' => ['string', false];
        yield 'not a calendar day' => ['31/02/2010', true];
        yield 'month 13' => ['01/13/2010', true];
        yield 'day 00' => ['00/03/2010', true];
        yield 'non leap year' => ['29/02/2023', true];
    }

    #[DataProvider('provideInvalidFrenchDates')]
    public function testDecodeFrenchDateRefusesAnythingElse(string $value, bool $wellFormed): void
    {
        $exception = WireAssertions::caught(static fn(): CalendarDate => DateEncoding::decodeFrenchDate($value, 'date_etat'));

        static::assertSame('Unexpected URSSAF response: field "date_etat" is not a DD/MM/YYYY date.', $exception->getMessage());
        static::assertSame($wellFormed, $exception->getPrevious() instanceof InvalidValue);
    }

    public function testTheValuesAreSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(DateEncoding::class, 'decodeIsoDate', ['value']);
        DomainAssertions::assertSensitiveParameters(DateEncoding::class, 'decodeFrenchDate', ['value']);
    }
}
