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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Number of records of one tenant in one state (mandate, declaration or payment).
 */
final readonly class StateCount
{
    /**
     * @param string $state the value of a MandateState, a DeclarationState or a PaymentState
     */
    public function __construct(public TenantId $tenantId, public string $state, public int $count) {}
}
