<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Id;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\SecureRandomBytes;

final class SecureRandomBytesTest extends TestCase
{
    public function testReturnsTheRequestedNumberOfBytes(): void
    {
        $source = new SecureRandomBytes();

        static::assertSame(16, \strlen($source->bytes(16)));
        static::assertSame(3, \strlen($source->bytes(3)));
    }

    public function testBytesAreNotConstant(): void
    {
        $source = new SecureRandomBytes();

        static::assertNotSame($source->bytes(16), $source->bytes(16));
    }
}
