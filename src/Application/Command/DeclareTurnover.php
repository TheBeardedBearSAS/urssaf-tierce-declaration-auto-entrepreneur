<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Declares the turnover of a SIRET and a period to URSSAF (method 050), once per tenant, SIRET and period (ADR 0003,
 * ADR 0009).
 *
 * "skipMandateCheck" opts out of the local mandate guard and "skipPeriodicityCheck" out of the periodicity guard of the
 * tracked account, for this command (ADR 0011).
 */
final readonly class DeclareTurnover
{
    public function __construct(
        public TenantId $tenantId,
        public Siret $siret,
        public TurnoverDeclaration $declaration,
        public bool $skipMandateCheck = false,
        public bool $skipPeriodicityCheck = false,
    ) {}
}
