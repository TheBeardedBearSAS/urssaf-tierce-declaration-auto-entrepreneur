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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;

/**
 * Statistics answering the counts it was built with.
 */
final readonly class FixedWorkflowStatistics implements WorkflowStatistics
{
    /**
     * @param list<StateCount> $mandates
     * @param list<StateCount> $declarations
     * @param list<StateCount> $payments
     */
    public function __construct(private array $mandates, private array $declarations, private array $payments) {}

    #[Override]
    public function mandateStates(): array
    {
        return $this->mandates;
    }

    #[Override]
    public function declarationStates(): array
    {
        return $this->declarations;
    }

    #[Override]
    public function paymentStates(): array
    {
        return $this->payments;
    }
}
