<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Estimates the contributions of a turnover (method 040). "skipMandateCheck" opts out of the local mandate guard for this
 * query (ADR 0011).
 */
final readonly class EstimateContributions
{
    public function __construct(
        public TenantId $tenantId,
        public Siret $siret,
        public Turnover $turnover,
        public bool $skipMandateCheck = false,
    ) {}
}
