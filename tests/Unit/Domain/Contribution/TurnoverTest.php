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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class TurnoverTest extends TestCase
{
    public function testAccessors(): void
    {
        $turnover = Turnover::of(
            ContributionSamples::euros(1),
            ContributionSamples::euros(2),
            ContributionSamples::euros(3),
            ContributionSamples::euros(4),
            ContributionSamples::euros(5),
        );

        static::assertSame(1, $turnover->sales()?->toInt());
        static::assertSame(2, $turnover->furnishedRental()?->toInt());
        static::assertSame(3, $turnover->commercialServices()?->toInt());
        static::assertSame(4, $turnover->otherServices()?->toInt());
        static::assertSame(5, $turnover->cipavReceipts()?->toInt());
    }

    public function testEveryLineMayBeAbsentAndZeroIsAccepted(): void
    {
        $empty = Turnover::of(null, null, null, null, null);
        $zero = Turnover::of(ContributionSamples::euros(0), null, null, null, null);

        static::assertNull($empty->sales());
        static::assertNull($empty->cipavReceipts());
        static::assertSame(0, $zero->sales()?->toInt());
    }

    public function testEveryNegativeLineIsReported(): void
    {
        $negative = ContributionSamples::euros(-1);

        try {
            Turnover::of($negative, $negative, $negative, $negative, $negative);
            static::fail('A negative turnover must be refused.');
        } catch (ValidationFailed $failed) {
            static::assertSame([
                'error sales turnover.negative',
                'error furnishedRental turnover.negative',
                'error commercialServices turnover.negative',
                'error otherServices turnover.negative',
                'error cipavReceipts turnover.negative',
            ], DomainAssertions::summary($failed->result()));
        }
    }

    public function testASingleNegativeLineIsReportedAtItsPath(): void
    {
        $zero = ContributionSamples::euros(0);
        $negative = ContributionSamples::euros(-1);

        foreach (['sales', 'furnishedRental', 'commercialServices', 'otherServices', 'cipavReceipts'] as $index => $path) {
            $lines = [$zero, $zero, $zero, $zero, $zero];
            $lines[$index] = $negative;
            try {
                Turnover::of(...$lines);
                static::fail('A negative turnover must be refused.');
            } catch (ValidationFailed $failed) {
                static::assertSame(['error ' . $path . ' turnover.negative'], DomainAssertions::summary($failed->result()));
            }
        }
    }

    public function testEquality(): void
    {
        $base = [1, 2, 3, 4, 5];
        $turnover = self::of($base);

        static::assertTrue($turnover->equals(self::of($base)));
        foreach (array_keys($base) as $index) {
            $other = $base;
            $other[$index] = 9;
            static::assertFalse($turnover->equals(self::of($other)), 'line ' . $index);
            $absent = $base;
            $absent[$index] = null;
            static::assertFalse($turnover->equals(self::of($absent)), 'absent line ' . $index);
        }
        static::assertFalse(self::of([0, null, null, null, null])->equals(self::of([null, null, null, null, null])));
    }

    /**
     * @param list<int|null> $lines
     */
    private static function of(array $lines): Turnover
    {
        $euros = static fn(?int $amount): ?Euros => $amount === null ? null : ContributionSamples::euros($amount);

        return Turnover::of($euros($lines[0]), $euros($lines[1]), $euros($lines[2]), $euros($lines[3]), $euros($lines[4]));
    }
}
