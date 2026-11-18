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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Starts tracking the auto-entrepreneur of a SIRET: method 020 reads its snapshot, then the account is created. The
 * optional birth date is stored on the account for methods 030 and 031 (ADR 0010).
 */
final readonly class TrackAccount
{
    public function __construct(
        public TenantId $tenantId,
        public Siret $siret,
        #[SensitiveParameter]
        public ?CalendarDate $birthDate,
    ) {}
}
