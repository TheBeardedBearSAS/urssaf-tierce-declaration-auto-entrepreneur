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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaRevocationQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

final class SepaRevocationQueryTest extends TestCase
{
    public function testTheEffectiveDateIsAParameter(): void
    {
        static::assertSame(['dateEffet' => '2020-07-20'], SepaRevocationQuery::of(WireSamples::revocation()));
    }

    public function testNoEffectiveDateGivesNoParameter(): void
    {
        $revocation = SepaRevocation::of(WireSamples::siret(), Rum::fromString(WireSamples::RUM), null);

        static::assertSame([], SepaRevocationQuery::of($revocation));
    }
}
