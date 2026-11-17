<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Exception;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Severity;

final class InvalidValueTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): InvalidValue, string, string, array<string, scalar>}>
     */
    public static function provideNamedConstructors(): iterable
    {
        yield 'because' => [
            static fn(): InvalidValue => InvalidValue::because('foo.bar', 'Custom message.', ['limit' => 3]),
            'foo.bar',
            'Custom message.',
            ['limit' => 3],
        ];
        yield 'because without params' => [static fn(): InvalidValue => InvalidValue::because('foo.bar', 'Custom message.'), 'foo.bar', 'Custom message.', []];
        yield 'empty' => [static fn(): InvalidValue => InvalidValue::empty('tenant_id'), 'tenant_id.empty', 'Invalid tenant_id: the value must not be empty.', []];
        yield 'too long' => [
            static fn(): InvalidValue => InvalidValue::tooLong('tenant_id', 64),
            'tenant_id.too_long',
            'Invalid tenant_id: the value must not exceed 64 characters.',
            ['max_length' => 64],
        ];
        yield 'invalid format' => [
            static fn(): InvalidValue => InvalidValue::invalidFormat('siret', '14 digits'),
            'siret.invalid_format',
            'Invalid siret: the value does not match the expected format (14 digits).',
            ['expected_format' => '14 digits'],
        ];
        yield 'negative' => [static fn(): InvalidValue => InvalidValue::negative('money'), 'money.negative', 'Invalid money: the value must not be negative.', []];
        yield 'out of range with integers' => [
            static fn(): InvalidValue => InvalidValue::outOfRange('period_year', 1, 9999),
            'period_year.out_of_range',
            'Invalid period_year: the value must be between 1 and 9999.',
            ['min' => 1, 'max' => 9999],
        ];
        yield 'out of range with decimal strings' => [
            static fn(): InvalidValue => InvalidValue::outOfRange('rate', '0.00', '999.99'),
            'rate.out_of_range',
            'Invalid rate: the value must be between 0.00 and 999.99.',
            ['min' => '0.00', 'max' => '999.99'],
        ];
    }

    /**
     * @param Closure(): InvalidValue $factory
     * @param array<string, scalar>   $params
     */
    #[DataProvider('provideNamedConstructors')]
    public function testNamedConstructors(Closure $factory, string $code, string $message, array $params): void
    {
        $exception = $factory();

        static::assertSame($code, $exception->violationCode());
        static::assertSame($message, $exception->getMessage());
        static::assertSame($params, $exception->params());
        static::assertSame(0, $exception->getCode());
        static::assertNull($exception->getPrevious());
    }

    public function testItConvertsToAnErrorViolation(): void
    {
        $violation = InvalidValue::tooLong('declaration_id', 50)->toViolation('lines[1].declarationId');

        static::assertSame('lines[1].declarationId', $violation->path());
        static::assertSame('declaration_id.too_long', $violation->code());
        static::assertSame('Invalid declaration_id: the value must not exceed 50 characters.', $violation->message());
        static::assertSame(Severity::Error, $violation->severity());
        static::assertSame(['max_length' => 50], $violation->params());
    }
}
