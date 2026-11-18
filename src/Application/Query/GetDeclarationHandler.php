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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;

final readonly class GetDeclarationHandler
{
    public function __construct(private DeclarationLocator $locator) {}

    /**
     * @throws RecordNotFound "declaration_record.not_found"
     */
    public function __invoke(GetDeclaration $query): DeclarationView
    {
        return DeclarationView::fromRecord($this->locator->locate($query->tenantId, $query->declaration));
    }
}
