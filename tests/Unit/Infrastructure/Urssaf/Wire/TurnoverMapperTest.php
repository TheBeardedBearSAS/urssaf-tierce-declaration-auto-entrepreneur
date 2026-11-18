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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\TurnoverMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

final class TurnoverMapperTest extends TestCase
{
    public function testEveryLineIsWrittenInContractOrder(): void
    {
        static::assertSame(
            '{"caVente":1000,"caLocation":2000,"caPrestationService":3000,"caPrestationAutres":4000,"recettesCipav":0}',
            JsonEncoder::encode(TurnoverMapper::toWire(WireSamples::turnover())),
        );
    }

    public function testAnAllNullTurnoverIsAnEmptyObject(): void
    {
        $turnover = Turnover::of(null, null, null, null, null);

        static::assertSame('{}', JsonEncoder::encode(TurnoverMapper::toWire($turnover)));
    }

    public function testAbsentLinesAreOmittedAndZeroIsKept(): void
    {
        $turnover = Turnover::of(null, Euros::of(0), null, Euros::of(5), null);

        static::assertSame(
            ['caLocation' => 0, 'caPrestationAutres' => 5],
            TurnoverMapper::toWire($turnover)->members(),
        );
    }

    public function testEachLineHasItsOwnKey(): void
    {
        $lines = [
            'caVente' => Turnover::of(Euros::of(1), null, null, null, null),
            'caLocation' => Turnover::of(null, Euros::of(1), null, null, null),
            'caPrestationService' => Turnover::of(null, null, Euros::of(1), null, null),
            'caPrestationAutres' => Turnover::of(null, null, null, Euros::of(1), null),
            'recettesCipav' => Turnover::of(null, null, null, null, Euros::of(1)),
        ];

        foreach ($lines as $key => $turnover) {
            static::assertSame([$key => 1], TurnoverMapper::toWire($turnover)->members());
        }
    }
}
