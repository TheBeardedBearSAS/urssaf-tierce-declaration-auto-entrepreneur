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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateFinder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;

/**
 * Lists the SEPA mandates (method 075). Pass-through: every failure propagates, and no guard applies (ADR 0011 lists
 * methods 040, 050 and 060 only). The mandates are the Domain read model, masked and never persisted (ADR 0010 item 2).
 */
final readonly class ListSepaMandatesHandler
{
    public function __construct(
        private SepaMandateFinder $finder,
    ) {}

    /**
     * @return list<SepaMandate>
     */
    public function __invoke(ListSepaMandates $query): array
    {
        return $this->finder->listSepaMandates($query->tenantId, $query->siret);
    }
}
