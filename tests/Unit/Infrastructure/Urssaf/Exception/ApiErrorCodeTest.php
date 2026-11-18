<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\ApiErrorCode;

final class ApiErrorCodeTest extends TestCase
{
    public function testNormaliseRemovesEveryWhitespaceAndKeepsTheCase(): void
    {
        static::assertSame('CPTE_INCONNU', ApiErrorCode::normalise(" CPTE_ INCONNU\n"));
        static::assertSame('AB_C', ApiErrorCode::normalise(" A B\t_\r\nC "));
        static::assertSame('cpte_inconnu', ApiErrorCode::normalise(' cpte_inconnu '));
        static::assertSame('', ApiErrorCode::normalise(" \n"));
    }

    /**
     * @return iterable<string, array{string, ApiErrorCode}>
     */
    public static function provideRawCodes(): iterable
    {
        yield 'exact' => ['CPTE_INCONNU', ApiErrorCode::AccountUnknown];
        yield 'trailing space' => ['MDT_INCONNU ', ApiErrorCode::MandateUnknown];
        yield 'leading space' => [' PARAM_INVALIDE', ApiErrorCode::InvalidParameter];
        yield 'surrounding newline and tab' => ["\tERR_TECHNIQUE\n", ApiErrorCode::TechnicalError];
        yield 'inner whitespace' => [" CPTE_ INCONNU\n", ApiErrorCode::AccountUnknown];
    }

    #[DataProvider('provideRawCodes')]
    public function testFromCodeToleratesWhitespace(string $raw, ApiErrorCode $expected): void
    {
        static::assertSame($expected, ApiErrorCode::fromCode($raw));
    }

    public function testFromCodeKeepsTheCase(): void
    {
        static::assertNull(ApiErrorCode::fromCode('cpte_inconnu'));
    }

    public function testAnUnknownCodeIsNull(): void
    {
        static::assertNull(ApiErrorCode::fromCode('NOT_A_CODE'));
        static::assertNull(ApiErrorCode::fromCode(''));
        static::assertNull(ApiErrorCode::fromCode(' '));
    }
}
