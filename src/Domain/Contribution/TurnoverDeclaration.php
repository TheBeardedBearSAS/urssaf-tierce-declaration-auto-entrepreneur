<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;

/**
 * Content of a turnover declaration (method 050): the period and its turnover.
 */
final readonly class TurnoverDeclaration
{
    private function __construct(
        private DeclarationPeriod $period,
        private Turnover $turnover,
    ) {}

    public static function of(DeclarationPeriod $period, Turnover $turnover): self
    {
        return new self($period, $turnover);
    }

    public function period(): DeclarationPeriod
    {
        return $this->period;
    }

    public function turnover(): Turnover
    {
        return $this->turnover;
    }
}
