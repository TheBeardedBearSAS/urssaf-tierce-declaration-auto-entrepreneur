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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Registers the SEPA mandate of an auto-entrepreneur (method 070, ADR 0009). The mandate (IBAN, holder, address) is
 * passed to URSSAF and never stored.
 */
final readonly class RegisterSepaMandate
{
    public function __construct(
        public TenantId $tenantId,
        public Siret $siret,
        #[SensitiveParameter]
        public NewSepaMandate $mandate,
    ) {}
}
