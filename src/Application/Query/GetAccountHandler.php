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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\ShownMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;

/**
 * The mandate shown is the active one, else the most recent one (a cancelled mandate), else none.
 */
final readonly class GetAccountHandler
{
    public function __construct(
        private AccountLocator $locator,
        private MandateRepository $mandates,
    ) {}

    /**
     * @throws RecordNotFound "ae_account.not_found" (also for an account of another tenant)
     */
    public function __invoke(GetAccount $query): AccountView
    {
        $account = $this->locator->locate($query->tenantId, $query->account);

        return AccountView::fromAccount($account, ShownMandate::of($this->mandates, $account->tenantId(), $account->siret()));
    }
}
