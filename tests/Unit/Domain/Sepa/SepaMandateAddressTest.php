<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandateAddress;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class SepaMandateAddressTest extends TestCase
{
    public function testAccessors(): void
    {
        $address = SepaMandateAddress::of('13 boulevard vauban', 'Bat A', '59000', 'LILLE', 'FRANCE');

        static::assertSame('13 boulevard vauban', $address->firstLine());
        static::assertSame('Bat A', $address->secondLine());
        static::assertSame('59000', $address->postalCode());
        static::assertSame('LILLE', $address->city());
        static::assertSame('FRANCE', $address->country());
        static::assertNull(SepaMandateAddress::of('a', null, 'b', 'c', 'd')->secondLine());
    }

    public function testMaximumLengthsAreAccepted(): void
    {
        $address = SepaMandateAddress::of(str_repeat('é', 32), str_repeat('é', 32), str_repeat('1', 5), str_repeat('é', 26), str_repeat('é', 80));

        static::assertSame(32, mb_strlen($address->firstLine()));
        static::assertSame(26, mb_strlen($address->city()));
        static::assertSame(80, mb_strlen($address->country()));
    }

    public function testEveryTooLongFieldIsReported(): void
    {
        static::assertSame(
            [
                'error firstLine address_line.too_long',
                'error secondLine address_line.too_long',
                'error postalCode postal_code.too_long',
                'error city city.too_long',
                'error country country.too_long',
            ],
            self::violations(str_repeat('é', 33), str_repeat('é', 33), '123456', str_repeat('é', 27), str_repeat('é', 81)),
        );
    }

    public function testEveryBlankFieldIsReported(): void
    {
        static::assertSame(
            [
                'error firstLine address_line.empty',
                'error secondLine address_line.empty',
                'error postalCode postal_code.empty',
                'error city city.empty',
                'error country country.empty',
            ],
            self::violations('', ' ', '', '  ', ''),
        );
    }

    public function testTheViolationParamsCarryTheBoundNeverTheValue(): void
    {
        try {
            SepaMandateAddress::of('SECRET-LINE-' . str_repeat('x', 40), null, '59000', 'LILLE', 'FRANCE');
            static::fail('A too long line must be refused.');
        } catch (ValidationFailed $failed) {
            $violation = $failed->result()->errors()[0];
            static::assertSame(['max_length' => 32], $violation->params());
            static::assertStringNotContainsString('SECRET', $violation->message());
        }
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $address = SepaMandateAddress::of('13 boulevard vauban', 'Bat A', '59000', 'LILLE', 'FRANCE');

        static::assertSame(
            ['firstLine' => '***', 'secondLine' => '***', 'postalCode' => '***', 'city' => '***', 'country' => '***'],
            $address->__debugInfo(),
        );
        static::assertNull(SepaMandateAddress::of('a', null, 'b', 'c', 'd')->__debugInfo()['secondLine']);
        static::assertStringNotContainsString('vauban', print_r($address, true));
    }

    public function testEveryParameterIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(SepaMandateAddress::class, 'of', ['firstLine', 'secondLine', 'postalCode', 'city', 'country']);
        DomainAssertions::assertSensitiveParameters(SepaMandateAddress::class, '__construct', ['firstLine', 'secondLine', 'postalCode', 'city', 'country']);
    }

    /**
     * @return list<string>
     */
    private static function violations(string $firstLine, ?string $secondLine, string $postalCode, string $city, string $country): array
    {
        try {
            SepaMandateAddress::of($firstLine, $secondLine, $postalCode, $city, $country);
        } catch (ValidationFailed $failed) {
            return DomainAssertions::summary($failed->result());
        }
        static::fail('The address must be refused.');
    }
}
