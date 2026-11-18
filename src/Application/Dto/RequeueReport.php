<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

/**
 * Number of records moved back from invalid_credentials to pending, by kind.
 */
final readonly class RequeueReport
{
    public function __construct(
        public int $declarations,
        public int $payments,
    ) {}
}
