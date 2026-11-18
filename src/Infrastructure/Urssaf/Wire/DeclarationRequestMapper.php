<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;

/**
 * Body of the turnover declaration (050): the turnover under `ca` and the period under `periode`, as integers
 * (ADR 0006).
 */
final class DeclarationRequestMapper
{
    /**
     * @return array{ca: JsonObject, periode: array<string, int>}
     */
    public static function body(TurnoverDeclaration $declaration): array
    {
        return [
            'ca' => TurnoverMapper::toWire($declaration->turnover()),
            'periode' => PeriodEncoding::encode($declaration->period()),
        ];
    }
}
