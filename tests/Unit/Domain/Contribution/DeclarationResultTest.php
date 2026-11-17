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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

final class DeclarationResultTest extends TestCase
{
    public function testAcceptedDeclaration(): void
    {
        $result = self::accepted('12345678', 'W1dl9W2Oa4R6ab3UlQAqxF3', '2020-01-31', 1678);

        static::assertSame('12345678', $result->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $result->declarationId());
        static::assertSame('2020-01-31', $result->dueDate()?->toString());
        static::assertSame(1678, $result->amounts()?->total()->toInt());
        static::assertFalse($result->isResolution());
    }

    public function testAnUnexpectedCertificateIsKeptAsReceived(): void
    {
        $result = DeclarationResult::of(
            Certificate::fromResponse('not-eight-digits'),
            'W1dl9W2Oa4R6ab3UlQAqxF3',
            CalendarDate::fromString('2020-01-31'),
            ContributionSamples::amounts(1),
        );

        static::assertSame('not-eight-digits', $result->certificate()->value());
    }

    public function testOperatorResolution(): void
    {
        $result = DeclarationResult::resolved(Certificate::fromString('12345678'));

        static::assertSame('12345678', $result->certificate()->value());
        static::assertNull($result->declarationId());
        static::assertNull($result->dueDate());
        static::assertNull($result->amounts());
        static::assertTrue($result->isResolution());
    }

    public function testEquality(): void
    {
        $result = self::accepted('12345678', 'W1', '2020-01-31', 1678);

        static::assertTrue($result->equals(self::accepted('12345678', 'W1', '2020-01-31', 1678)));
        static::assertFalse($result->equals(self::accepted('12345679', 'W1', '2020-01-31', 1678)));
        static::assertFalse($result->equals(self::accepted('12345678', 'W2', '2020-01-31', 1678)));
        static::assertFalse($result->equals(self::accepted('12345678', 'W1', '2020-02-29', 1678)));
        static::assertFalse($result->equals(self::accepted('12345678', 'W1', '2020-01-31', 1679)));
        static::assertFalse($result->equals(DeclarationResult::resolved(Certificate::fromString('12345678'))));
        static::assertFalse(DeclarationResult::resolved(Certificate::fromString('12345678'))->equals($result));
        static::assertTrue(DeclarationResult::resolved(Certificate::fromString('12345678'))->equals(DeclarationResult::resolved(Certificate::fromString('12345678'))));
        static::assertFalse(DeclarationResult::resolved(Certificate::fromString('12345678'))->equals(DeclarationResult::resolved(Certificate::fromString('12345679'))));
    }

    private static function accepted(string $certificate, string $id, string $dueDate, int $total): DeclarationResult
    {
        return DeclarationResult::of(
            Certificate::fromString($certificate),
            $id,
            CalendarDate::fromString($dueDate),
            ContributionSamples::amounts($total),
        );
    }
}
