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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Input\SepaRevocationFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;

final class SepaRevocationFactoryTest extends TestCase
{
    public function testFromInput(): void
    {
        $input = ['siret' => '12345678901234', 'rum' => SepaSamples::RUM, 'effective_date' => '2020-02-01'];
        $revocation = (new SepaRevocationFactory())->fromInput($input);

        static::assertSame('12345678901234', $revocation->siret()->toString());
        static::assertSame(SepaSamples::RUM, $revocation->rum()->value());
        static::assertSame('2020-02-01', $revocation->effectiveDate()?->toString());
        static::assertSame([], DomainAssertions::summary((new SepaRevocationFactory())->validate($input)));
    }

    public function testTheEffectiveDateIsOptional(): void
    {
        $revocation = (new SepaRevocationFactory())->fromInput(['siret' => '12345678901234', 'rum' => SepaSamples::RUM]);

        static::assertNull($revocation->effectiveDate());
    }

    public function testEveryMissingFieldIsReported(): void
    {
        static::assertSame(
            ['error siret siret.required', 'error rum rum.required'],
            DomainAssertions::summary((new SepaRevocationFactory())->validate([])),
        );
    }

    public function testEveryInvalidFieldIsReported(): void
    {
        static::assertSame(
            ['error siret siret.invalid_format', 'error rum rum.too_long', 'error effective_date calendar_date.invalid_date'],
            DomainAssertions::summary((new SepaRevocationFactory())->validate([
                'siret' => '123',
                'rum' => str_repeat('1', 36),
                'effective_date' => '2020-02-30',
            ])),
        );
    }

    public function testAnInvalidEffectiveDateAloneIsEnoughToFail(): void
    {
        try {
            (new SepaRevocationFactory())->fromInput(['siret' => '12345678901234', 'rum' => SepaSamples::RUM, 'effective_date' => 'x']);
            static::fail('An invalid revocation must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error effective_date calendar_date.invalid_format'], DomainAssertions::summary($failed->result()));
        }
    }

    public function testAnInvalidRumAloneIsEnoughToFail(): void
    {
        try {
            (new SepaRevocationFactory())->fromInput(['siret' => '12345678901234', 'rum' => str_repeat('1', 36)]);
            static::fail('An invalid revocation must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame(['error rum rum.too_long'], DomainAssertions::summary($failed->result()));
        }
    }

    public function testNoRawValueAppearsInTheViolations(): void
    {
        $result = (new SepaRevocationFactory())->validate(['siret' => 'SECRET-SIRET', 'rum' => SepaSamples::RUM]);

        static::assertCount(1, $result->all());
        static::assertStringNotContainsString('SECRET', $result->all()[0]->message());
    }

    public function testRawInputIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(SepaRevocationFactory::class, 'validate', ['input']);
        DomainAssertions::assertSensitiveParameters(SepaRevocationFactory::class, 'fromInput', ['input']);
    }
}
