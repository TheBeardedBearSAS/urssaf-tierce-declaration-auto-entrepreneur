<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The fingerprint identifies the content of a payment order, never its IBAN (ADR 0009, ADR 0010 item 2).
 *
 * One test per rule of the canonical form.
 *
 * @mago-expect lint:too-many-methods One test per rule or transition of the class under test.
 */
final class PaymentFingerprintTest extends TestCase
{
    private const GOLDEN_FULL = '830776a9208577694eb1279b0cee04831373e1f048348b13948344c4d986a599';
    private const GOLDEN_MINIMAL = '7bda6386068efcb510fd1066be898c987e5045cffb01c5fb9e4c1f95d223bf37';

    private static function order(DeclarationPeriod $period, int $amount, ?string $iban, ?string $rum): PaymentOrder
    {
        return PaymentOrder::of(
            $period,
            Euros::of($amount),
            $iban === null ? null : Iban::fromString($iban),
            $rum === null ? null : Rum::fromString($rum),
        );
    }

    private static function of(string $tenant, string $siret, PaymentOrder $order): PaymentFingerprint
    {
        return PaymentFingerprint::of(TenantId::fromString($tenant), Siret::fromString($siret), $order);
    }

    private static function reference(): PaymentFingerprint
    {
        return self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2026, 10), 1500, 'FR7630001007941234567890185', 'T1234567891234567891234568912345678'));
    }

    public function testGoldenFullOrder(): void
    {
        // sha256 of the length-prefixed parts, computed outside the code under test.
        static::assertSame(self::GOLDEN_FULL, self::reference()->value());
        static::assertSame(self::GOLDEN_FULL, PaymentSamples::fingerprint()->value());
    }

    public function testGoldenMinimalOrder(): void
    {
        $fingerprint = self::of('tenant-1', '12345678901234', self::order(QuarterlyPeriod::of(2026, 3), 0, null, null));

        static::assertSame(self::GOLDEN_MINIMAL, $fingerprint->value());
    }

    public function testTheIbanItselfNeverChangesTheFingerprint(): void
    {
        $other = self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2026, 10), 1500, 'FR7612345678901234567890123', 'T1234567891234567891234568912345678'));

        static::assertTrue(self::reference()->equals($other));
    }

    public function testThePresenceOfAnIbanChangesTheFingerprint(): void
    {
        $without = self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2026, 10), 1500, null, 'T1234567891234567891234568912345678'));

        static::assertFalse(self::reference()->equals($without));
    }

    public function testEveryOtherPartChangesTheFingerprint(): void
    {
        $iban = 'FR7630001007941234567890185';
        $rum = 'T1234567891234567891234568912345678';
        $others = [
            self::of('tenant-2', '12345678901234', self::order(MonthlyPeriod::of(2026, 10), 1500, $iban, $rum)),
            self::of('tenant-1', '12345678901235', self::order(MonthlyPeriod::of(2026, 10), 1500, $iban, $rum)),
            self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2026, 11), 1500, $iban, $rum)),
            self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2025, 10), 1500, $iban, $rum)),
            self::of('tenant-1', '12345678901234', self::order(QuarterlyPeriod::of(2026, 3), 1500, $iban, $rum)),
            self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2026, 10), 1501, $iban, $rum)),
            self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2026, 10), 1500, $iban, 'T1234567891234567891234568912345679')),
            self::of('tenant-1', '12345678901234', self::order(MonthlyPeriod::of(2026, 10), 1500, $iban, null)),
        ];
        foreach ($others as $other) {
            static::assertFalse(self::reference()->equals($other));
        }
        static::assertCount(\count($others), array_unique(array_map(static fn(PaymentFingerprint $f): string => $f->value(), $others)));
    }

    public function testTheSameContentGivesTheSameFingerprint(): void
    {
        static::assertTrue(self::reference()->equals(self::reference()));
        static::assertTrue(self::reference()->equals(PaymentFingerprint::fromString(self::GOLDEN_FULL)));
    }

    public function testNothingReadableIsKept(): void
    {
        $value = self::reference()->value();

        static::assertStringNotContainsString('12345678901234', $value);
        static::assertStringNotContainsString('FR76', $value);
        static::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $value);
    }

    public function testFromStringAcceptsExactly64LowercaseHexadecimalCharacters(): void
    {
        $value = str_repeat('0f', 32);

        static::assertSame($value, PaymentFingerprint::fromString($value)->value());
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
        PaymentSamples::assertViolation(
            'payment_fingerprint.invalid_format',
            static fn(): PaymentFingerprint => PaymentFingerprint::fromString($value),
        );
    }
}
