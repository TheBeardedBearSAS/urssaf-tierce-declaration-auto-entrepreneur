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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class SepaRevocationTest extends TestCase
{
    public function testAccessors(): void
    {
        $revocation = self::revocation('2020-02-01');

        static::assertSame('12345678901234', $revocation->siret()->toString());
        static::assertSame(SepaSamples::RUM, $revocation->rum()->value());
        static::assertSame('2020-02-01', $revocation->effectiveDate()?->toString());
        static::assertNull(self::revocation(null)->effectiveDate());
    }

    public function testAnyDateIsAcceptedByTheValueObject(): void
    {
        static::assertSame('1999-01-01', self::revocation('1999-01-01')->effectiveDate()?->toString());
    }

    public function testNoDateIsValid(): void
    {
        static::assertTrue(self::revocation(null)->effectiveDateViolations(CalendarDate::fromString('2020-01-31'))->isValid());
    }

    public function testADateAfterTodayIsValid(): void
    {
        static::assertTrue(self::revocation('2020-02-01')->effectiveDateViolations(CalendarDate::fromString('2020-01-31'))->isValid());
    }

    public function testTodayAndThePastAreTooEarly(): void
    {
        foreach (['2020-01-31', '2020-01-30'] as $date) {
            $result = self::revocation($date)->effectiveDateViolations(CalendarDate::fromString('2020-01-31'));

            static::assertSame(['error effective_date sepa_revocation.effective_date_too_early'], DomainAssertions::summary($result));
        }
    }

    private static function revocation(?string $effectiveDate): SepaRevocation
    {
        return SepaRevocation::of(
            Siret::fromString('12345678901234'),
            Rum::fromString(SepaSamples::RUM),
            $effectiveDate === null ? null : CalendarDate::fromString($effectiveDate),
        );
    }
}
