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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

/**
 * The `CaAe` object of methods 040 and 050 (ADR 0007): the lines in contract order, absent lines omitted and `0` kept.
 * A turnover with no line gives `{}`, which {@see JsonObject} keeps an object.
 */
final class TurnoverMapper
{
    public static function toWire(Turnover $turnover): JsonObject
    {
        $lines = [
            'caVente' => $turnover->sales(),
            'caLocation' => $turnover->furnishedRental(),
            'caPrestationService' => $turnover->commercialServices(),
            'caPrestationAutres' => $turnover->otherServices(),
            'recettesCipav' => $turnover->cipavReceipts(),
        ];

        $members = [];
        foreach ($lines as $key => $amount) {
            if (!$amount instanceof Euros) {
                continue;
            }

            $members[$key] = EurosEncoding::encode($amount);
        }

        return JsonObject::of($members);
    }
}
