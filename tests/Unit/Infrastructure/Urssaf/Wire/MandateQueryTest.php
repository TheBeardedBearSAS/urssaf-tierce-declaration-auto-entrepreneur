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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\MandateQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\QueryEncoding;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

final class MandateQueryTest extends TestCase
{
    public function testItCarriesTheSiretAndTheBirthDateAsADateOnlyString(): void
    {
        $query = MandateQuery::of(WireSamples::accountReference());

        static::assertSame(['siret' => '12345678901234', 'dateNaissance' => '1975-10-01'], $query);
        static::assertSame(['siret', 'dateNaissance'], array_keys($query));
        static::assertSame('siret=12345678901234&dateNaissance=1975-10-01', QueryEncoding::build($query));
    }
}
