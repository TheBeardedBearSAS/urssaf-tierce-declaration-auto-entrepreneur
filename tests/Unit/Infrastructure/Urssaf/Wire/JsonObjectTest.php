<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonObject;

final class JsonObjectTest extends TestCase
{
    public function testItExposesItsMembers(): void
    {
        static::assertSame(['a' => 1, 'b' => [2]], JsonObject::of(['a' => 1, 'b' => [2]])->members());
        static::assertSame([], JsonObject::of([])->members());
    }
}
