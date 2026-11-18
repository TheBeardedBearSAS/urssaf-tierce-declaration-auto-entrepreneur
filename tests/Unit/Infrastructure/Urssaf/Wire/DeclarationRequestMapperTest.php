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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireBodyAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

final class DeclarationRequestMapperTest extends TestCase
{
    public function testTheQuarterlyBodyMatchesTheOfficialRequest(): void
    {
        WireBodyAssertions::assertMatchesFixture(
            'declare_request',
            DeclarationRequestMapper::body(WireSamples::declaration()),
        );
    }

    public function testTheMonthlyBodyUsesMois(): void
    {
        $declaration = TurnoverDeclaration::of(MonthlyPeriod::of(2020, 12), WireSamples::turnover());

        static::assertSame(
            '{"ca":{"caVente":1000,"caLocation":2000,"caPrestationService":3000,"caPrestationAutres":4000,"recettesCipav":0},"periode":{"annee":2020,"mois":12}}',
            JsonEncoder::encode(DeclarationRequestMapper::body($declaration)),
        );
    }
}
