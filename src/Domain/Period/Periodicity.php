<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period;

/**
 * Declaration periodicity of an auto-entrepreneur, as published by method 020 (ADR 0004).
 */
enum Periodicity: string
{
    case Monthly = 'M';
    case Quarterly = 'T';

    /**
     * The URSSAF code ("M" or "T") as received, no trimming; null for any other value.
     */
    public static function fromCode(string $code): ?self
    {
        return self::tryFrom($code);
    }
}
