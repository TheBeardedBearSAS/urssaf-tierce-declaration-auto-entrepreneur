<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;

final class SequenceIdGenerator implements IdGenerator
{
    private int $counter = 0;

    /**
     * @param non-empty-string $prefix
     */
    public function __construct(private readonly string $prefix = 'request-id-') {}

    /**
     * @return non-empty-string
     */
    public function generate(): string
    {
        ++$this->counter;

        return $this->prefix . $this->counter;
    }
}
