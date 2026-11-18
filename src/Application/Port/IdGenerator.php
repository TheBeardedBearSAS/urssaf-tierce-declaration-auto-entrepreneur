<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port;

/**
 * Generates unique identifiers (UUID v4 recommended) for local records, events and request correlation.
 */
interface IdGenerator
{
    /**
     * @return non-empty-string
     */
    public function generate(): string;
}
