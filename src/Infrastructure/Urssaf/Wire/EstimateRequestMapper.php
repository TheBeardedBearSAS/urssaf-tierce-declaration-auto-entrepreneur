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

/**
 * Body of the estimate (040): the turnover under `ca`. The deprecated `date` is never sent (ADR 0006).
 */
final class EstimateRequestMapper
{
    /**
     * @return array{ca: JsonObject}
     */
    public static function body(Turnover $turnover): array
    {
        return ['ca' => TurnoverMapper::toWire($turnover)];
    }
}
