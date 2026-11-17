<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The fingerprint identifies the content of a declaration without readable personal data (ADR 0009, ADR 0010).
 *
 * One test per rule of the canonical form.
 *
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class DeclarationFingerprintTest extends TestCase
{
    private const GOLDEN_FULL = '848d7655a7660335c247d4c48ec8a924c7dfc9d6f54f2267ae187ab6d7ae07de';
    private const GOLDEN_EMPTY = '5c0c019cc8fc13a2a589acf53b6b0f41073f210ab913355886d5e81237d7bd44';
    private const GOLDEN_QUARTERLY = '3730d722217b9fc54a420c7ed49332159f009025f707b7f81fc0ac151d54d12f';

    private static function of(string $tenant, string $siret, DeclarationPeriod $period, Turnover $turnover): DeclarationFingerprint
    {
        return DeclarationFingerprint::of(TenantId::fromString($tenant), Siret::fromString($siret), $period, $turnover);
    }

    private static function reference(): DeclarationFingerprint
    {
        return self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 10), DeclarationSamples::turnover());
    }

    public function testGoldenFullDeclaration(): void
    {
        // sha256 of the length-prefixed parts, computed outside the code under test.
        static::assertSame(self::GOLDEN_FULL, self::reference()->value());
        static::assertSame(self::GOLDEN_FULL, DeclarationSamples::fingerprint()->value());
    }

    public function testGoldenEmptyTurnover(): void
    {
        $fingerprint = self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 10), Turnover::of(null, null, null, null, null));

        static::assertSame(self::GOLDEN_EMPTY, $fingerprint->value());
    }

    public function testGoldenQuarterlyWithAZeroLine(): void
    {
        $turnover = Turnover::of(Euros::of(0), null, null, null, Euros::of(7));

        static::assertSame(
            self::GOLDEN_QUARTERLY,
            self::of('tenant-1', '12345678901234', QuarterlyPeriod::of(2026, 3), $turnover)->value(),
        );
    }

    public function testEveryPartChangesTheFingerprint(): void
    {
        $reference = self::reference();
        $others = [
            self::of('tenant-2', '12345678901234', MonthlyPeriod::of(2026, 10), DeclarationSamples::turnover()),
            self::of('tenant-1', '12345678901235', MonthlyPeriod::of(2026, 10), DeclarationSamples::turnover()),
            self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 11), DeclarationSamples::turnover()),
            self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2025, 10), DeclarationSamples::turnover()),
            self::of('tenant-1', '12345678901234', QuarterlyPeriod::of(2026, 3), DeclarationSamples::turnover()),
            self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 10), Turnover::of(Euros::of(1001), Euros::of(200), Euros::of(300), Euros::of(400), Euros::of(500))),
            self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 10), Turnover::of(Euros::of(1000), Euros::of(201), Euros::of(300), Euros::of(400), Euros::of(500))),
            self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 10), Turnover::of(Euros::of(1000), Euros::of(200), Euros::of(301), Euros::of(400), Euros::of(500))),
            self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 10), Turnover::of(Euros::of(1000), Euros::of(200), Euros::of(300), Euros::of(401), Euros::of(500))),
            self::of('tenant-1', '12345678901234', MonthlyPeriod::of(2026, 10), Turnover::of(Euros::of(1000), Euros::of(200), Euros::of(300), Euros::of(400), Euros::of(501))),
        ];
        foreach ($others as $other) {
            static::assertFalse($reference->equals($other));
        }
        static::assertCount(\count($others), array_unique(array_map(static fn(DeclarationFingerprint $f): string => $f->value(), $others)));
    }

    public function testAnAbsentLineIsNotAZeroLineNorAnotherLine(): void
    {
        $period = MonthlyPeriod::of(2026, 10);
        $zero = self::of('tenant-1', '12345678901234', $period, Turnover::of(Euros::of(0), null, null, null, null));
        $absent = self::of('tenant-1', '12345678901234', $period, Turnover::of(null, null, null, null, null));
        $moved = self::of('tenant-1', '12345678901234', $period, Turnover::of(null, Euros::of(0), null, null, null));

        static::assertFalse($zero->equals($absent));
        static::assertFalse($zero->equals($moved));
        static::assertFalse($absent->equals($moved));
    }

    public function testTheSameContentGivesTheSameFingerprint(): void
    {
        static::assertTrue(self::reference()->equals(self::reference()));
        static::assertTrue(self::reference()->equals(DeclarationFingerprint::fromString(self::GOLDEN_FULL)));
    }

    public function testTheSiretIsNotReadable(): void
    {
        static::assertStringNotContainsString('12345678901234', self::reference()->value());
        static::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', self::reference()->value());
    }

    public function testLengthPrefixingPreventsForgedSeparators(): void
    {
        $period = MonthlyPeriod::of(2026, 10);
        $turnover = Turnover::of(null, null, null, null, null);

        $one = self::of('a:1;b', '12345678901234', $period, $turnover);
        $two = self::of('a', '12345678901234', $period, $turnover);

        static::assertFalse($one->equals($two));
    }

    public function testFromStringAcceptsExactly64LowercaseHexadecimalCharacters(): void
    {
        $value = str_repeat('a0', 32);

        static::assertSame($value, DeclarationFingerprint::fromString($value)->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFingerprints(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'not hexadecimal' => [str_repeat('g', 64)];
        yield 'trailing newline' => [str_repeat('a', 64) . "\n"];
        yield 'leading space' => [' ' . str_repeat('a', 63)];
    }

    #[DataProvider('invalidFingerprints')]
    public function testFromStringRejectsAnythingElse(string $value): void
    {
        DeclarationSamples::assertViolation(
            'declaration_fingerprint.invalid_format',
            static fn(): DeclarationFingerprint => DeclarationFingerprint::fromString($value),
        );
    }
}
