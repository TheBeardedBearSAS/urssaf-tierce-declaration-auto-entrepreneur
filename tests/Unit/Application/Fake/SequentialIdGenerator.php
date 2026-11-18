<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;

/**
 * Identifiers "id-1", "id-2"... (or with another prefix).
 */
final class SequentialIdGenerator implements IdGenerator
{
    private int $next = 1;

    /**
     * @param non-empty-string $prefix
     */
    public function __construct(private readonly string $prefix = 'id-') {}

    #[Override]
    public function generate(): string
    {
        return $this->prefix . $this->next++;
    }
}
