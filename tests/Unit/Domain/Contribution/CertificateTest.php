<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Contribution;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class CertificateTest extends TestCase
{
    public function testStrictFormat(): void
    {
        $certificate = Certificate::fromString('12345678');

        static::assertSame('12345678', $certificate->value());
        static::assertSame('12345678', (string) $certificate);
    }

    public function testStrictFormatRefusesEverythingButEightDigits(): void
    {
        foreach (['', ' ', '1234567', '123456789', '1234567a', ' 12345678', "12345678\n", 'abcdefgh'] as $value) {
            static::assertSame('certificate.invalid_format', DomainAssertions::assertViolationCode('certificate.invalid_format', static fn(): Certificate => Certificate::fromString($value))->violationCode());
        }
    }

    public function testResponseKeepsAnyTextVerbatim(): void
    {
        foreach (['12345678', 'ABC', ' 12345678 ', '123456789012'] as $value) {
            static::assertSame($value, Certificate::fromResponse($value)->value());
        }
    }

    public function testResponseRefusesABlankValue(): void
    {
        static::assertSame('certificate.empty', DomainAssertions::assertViolationCode('certificate.empty', static fn(): Certificate => Certificate::fromResponse(''))->violationCode());
        static::assertSame('certificate.empty', DomainAssertions::assertViolationCode('certificate.empty', static fn(): Certificate => Certificate::fromResponse("  \t"))->violationCode());
    }

    public function testEquality(): void
    {
        static::assertTrue(Certificate::fromString('12345678')->equals(Certificate::fromResponse('12345678')));
        static::assertFalse(Certificate::fromString('12345678')->equals(Certificate::fromString('12345679')));
    }
}
