<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\Input;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\NewSepaMandateFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;

final class NewSepaMandateFactoryTest extends TestCase
{
    public function testFromInput(): void
    {
        $mandate = (new NewSepaMandateFactory())->fromInput(self::input());

        static::assertSame(SepaSamples::BIC, $mandate->bic()->value());
        static::assertSame(SepaSamples::IBAN, $mandate->iban()->value());
        static::assertSame(SepaSamples::HOLDER, $mandate->holder()->value());
        static::assertSame('13 boulevard vauban', $mandate->address()->firstLine());
        static::assertSame('Bat A', $mandate->address()->secondLine());
        static::assertSame('59000', $mandate->address()->postalCode());
        static::assertSame('LILLE', $mandate->address()->city());
        static::assertSame('FRANCE', $mandate->address()->country());
        static::assertSame([], DomainAssertions::summary((new NewSepaMandateFactory())->validate(self::input())));
    }

    public function testTheSecondLineIsOptional(): void
    {
        $input = self::input();
        unset($input['address']['second_line']);

        static::assertNull((new NewSepaMandateFactory())->fromInput($input)->address()->secondLine());
    }

    public function testEveryMissingFieldIsReported(): void
    {
        static::assertSame(
            [
                'error bic bic.required',
                'error iban iban.required',
                'error account_holder account_holder.required',
                'error address.first_line first_line.required',
                'error address.postal_code postal_code.required',
                'error address.city city.required',
                'error address.country country.required',
            ],
            DomainAssertions::summary((new NewSepaMandateFactory())->validate([])),
        );
    }

    public function testEveryInvalidFieldIsReported(): void
    {
        $input = self::input();
        $input['bic'] = str_repeat('A', 12);
        $input['iban'] = str_repeat('1', 35);
        $input['account_holder'] = str_repeat('A', 81);
        $input['address'] = [
            'first_line' => str_repeat('A', 33),
            'second_line' => str_repeat('A', 33),
            'postal_code' => '123456',
            'city' => str_repeat('A', 27),
            'country' => str_repeat('A', 81),
        ];

        static::assertSame(
            [
                'error bic bic.too_long',
                'error iban iban.too_long',
                'error account_holder account_holder.too_long',
                'error address.first_line address_line.too_long',
                'error address.second_line address_line.too_long',
                'error address.postal_code postal_code.too_long',
                'error address.city city.too_long',
                'error address.country country.too_long',
            ],
            DomainAssertions::summary((new NewSepaMandateFactory())->validate($input)),
        );
    }

    public function testAMissingAddressFieldDoesNotHideAnotherViolation(): void
    {
        $input = self::input();
        $input['address'] = ['postal_code' => '123456', 'city' => 'LILLE', 'country' => 'FRANCE'];

        static::assertSame(
            ['error address.first_line first_line.required', 'error address.postal_code postal_code.too_long'],
            DomainAssertions::summary((new NewSepaMandateFactory())->validate($input)),
        );
    }

    public function testAnInvalidTypeIsReported(): void
    {
        $input = self::input();
        $input['address'] = 'x';

        static::assertSame(
            ['error address address.invalid_type'],
            DomainAssertions::summary((new NewSepaMandateFactory())->validate($input)),
        );
    }

    public function testAnInvalidPartIsEnoughToFail(): void
    {
        $input = self::input();
        $input['iban'] = '';

        try {
            (new NewSepaMandateFactory())->fromInput($input);
            static::fail('An invalid mandate must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error iban iban.required'], DomainAssertions::summary($failed->result()));
        }
    }

    public function testNoRawValueAppearsInTheViolations(): void
    {
        $input = self::input();
        $input['iban'] = 'SECRET' . str_repeat('1', 40);
        $input['address']['city'] = 'SECRET' . str_repeat('1', 40);
        $result = (new NewSepaMandateFactory())->validate($input);

        static::assertCount(2, $result->all());
        foreach ($result->all() as $violation) {
            static::assertStringNotContainsString('SECRET', $violation->message());
        }
    }

    public function testRawInputIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(NewSepaMandateFactory::class, 'validate', ['input']);
        DomainAssertions::assertSensitiveParameters(NewSepaMandateFactory::class, 'fromInput', ['input']);
    }

    /**
     * @return array{bic: string, iban: string, account_holder: string, address: array<string, string>}
     */
    private static function input(): array
    {
        return [
            'bic' => SepaSamples::BIC,
            'iban' => SepaSamples::IBAN,
            'account_holder' => SepaSamples::HOLDER,
            'address' => [
                'first_line' => '13 boulevard vauban',
                'second_line' => 'Bat A',
                'postal_code' => '59000',
                'city' => 'LILLE',
                'country' => 'FRANCE',
            ],
        ];
    }
}
