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
 * Result of DeclareTurnover: the declaration record as it stands and what the use case did (ADR 0003, ADR 0009).
 */
final readonly class DeclarationSubmission
{
    public function __construct(public DeclarationView $declaration, public ItemOutcome $outcome) {}
}
