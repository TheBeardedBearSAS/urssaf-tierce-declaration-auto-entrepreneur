<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Account;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class SiretTest extends TestCase
{
    public function testItKeepsTheValueVerbatim(): void
    {
        static::assertSame('12345678901234', Siret::fromString('12345678901234')->toString());
        static::assertSame('00000000000000', Siret::fromString('00000000000000')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'thirteen digits' => ['1234567890123'];
        yield 'fifteen digits' => ['123456789012345'];
        yield 'leading space' => [' 12345678901234'];
        yield 'trailing space' => ['12345678901234 '];
        yield 'trailing newline' => ["12345678901234\n"];
        yield 'leading newline' => ["\n12345678901234"];
        yield 'inner space' => ['123 456 789 01234'];
        yield 'letters' => ['1234567890123A'];
        yield 'unicode digits' => ['١٢٣٤٥٦٧٨٩٠١٢٣٤'];
    }

    #[DataProvider('provideInvalidValues')]
    public function testInvalidFormatIsRefused(string $value): void
    {
        $exception = DomainAssertions::assertViolationCode('siret.invalid_format', static fn(): Siret => Siret::fromString($value));

        static::assertInstanceOf(InvalidValue::class, $exception);
        static::assertSame(['expected_format' => '14 digits'], $exception->params());
        static::assertStringNotContainsString('1234567890', $exception->getMessage());
    }

    public function testEquality(): void
    {
        static::assertTrue(Siret::fromString('12345678901234')->equals(Siret::fromString('12345678901234')));
        static::assertFalse(Siret::fromString('12345678901234')->equals(Siret::fromString('12345678901235')));
    }

    public function testItIsMaskedWhenDumped(): void
    {
        $siret = Siret::fromString('12345678901234');

        static::assertSame(['value' => '**********1234'], $siret->__debugInfo());
    }

    public function testItIsNotStringable(): void
    {
        static::assertFalse((new ReflectionClass(Siret::class))->hasMethod('__toString'));
    }

    public function testTheValueIsSensitive(): void
    {
        DomainAssertions::assertSensitiveParameters(Siret::class, 'fromString', ['value']);
    }
}
