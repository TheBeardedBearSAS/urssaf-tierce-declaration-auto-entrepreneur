<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Tenant;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

final class TenantIdTest extends TestCase
{
    public function testValuesAreKeptVerbatim(): void
    {
        foreach (['a', ' padded value ', str_repeat('x', 64), str_repeat('é', 64)] as $value) {
            $identifier = TenantId::fromString($value);
            static::assertSame($value, $identifier->value());
            static::assertSame($value, (string) $identifier);
        }
    }

    public function testBlankValuesAreRejected(): void
    {
        foreach (['', ' ', "\t\n"] as $value) {
            try {
                TenantId::fromString($value);
                static::fail('Expected exception');
            } catch (InvalidValue $exception) {
                static::assertSame('tenant_id.empty', $exception->violationCode());
            }
        }
    }

    public function testTooLongValuesAreRejected(): void
    {
        try {
            TenantId::fromString(str_repeat('x', 65));
            static::fail('Expected exception');
        } catch (InvalidValue $exception) {
            static::assertSame('tenant_id.too_long', $exception->violationCode());
            static::assertSame(['max_length' => 64], $exception->params());
        }
    }

    public function testMultibyteLengthIsCountedInCharacters(): void
    {
        static::assertSame(str_repeat('é', 64), TenantId::fromString(str_repeat('é', 64))->value());
    }

    public function testEquality(): void
    {
        static::assertTrue(TenantId::fromString('acme')->equals(TenantId::fromString('acme')));
        static::assertFalse(TenantId::fromString('acme')->equals(TenantId::fromString('Acme')));
    }
}
