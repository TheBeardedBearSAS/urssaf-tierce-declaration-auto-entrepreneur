<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandViews;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;

final class CommandViewsTest extends TestCase
{
    #[DataProvider('ibans')]
    public function testAnIbanIsMasked(?string $iban, string $expected): void
    {
        static::assertSame($expected, CommandViews::maskedIban($iban));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function ibans(): iterable
    {
        yield 'a long IBAN' => [SepaSamples::IBAN, 'FR76…0185'];
        yield 'a missing IBAN' => [null, '***'];
        yield 'an empty IBAN' => ['', '***'];
        yield 'a blank IBAN' => ['   ', '***'];
        yield 'a short IBAN' => ['FR76', '***'];
        yield 'the shortest IBAN with something to hide' => ['FR7630001', 'FR76…0001'];
        yield 'an IBAN of eight characters' => ['FR763000', '***'];
    }

    public function testAPeriodIsWrittenLikeTheOptionThatSelectsIt(): void
    {
        static::assertSame('2026-08', CommandViews::period('M', 2026, 8));
        static::assertSame('0999-12', CommandViews::period('M', 999, 12));
        static::assertSame('2026-T3', CommandViews::period('T', 2026, 3));
    }

    public function testASepaMandateShowsItsRumAndItsMaskedIbanOnly(): void
    {
        $mandate = new SepaMandate(Rum::fromString(SepaSamples::RUM), 'A bank', SepaSamples::IBAN, SepaSamples::HOLDER, null, null);

        static::assertSame([SepaSamples::RUM, 'FR76…0185'], CommandViews::sepaMandate($mandate));
    }

    public function testASepaMandateWithoutRumOrIbanIsShownWithDashes(): void
    {
        static::assertSame(['-', '***'], CommandViews::sepaMandate(new SepaMandate(null, null, null, null, null, null)));
    }
}
