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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountRefresher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\ShownMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;

/**
 * Method 020 for one tracked account. Every failure of the call propagates and changes nothing.
 */
final readonly class SyncAccountHandler
{
    public function __construct(
        private AccountLocator $locator,
        private AccountRefresher $refresher,
        private AeAccountRepository $accounts,
        private MandateRepository $mandates,
    ) {}

    /**
     * @throws RecordNotFound
     * @throws UrssafRequestRejected
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable
     * @throws RateLimitExceeded
     * @throws InvariantViolation         "ae_account.concurrent_modification": the account changed since it was read, the answer was discarded
     */
    public function __invoke(SyncAccount $command): AccountView
    {
        $account = $this->locator->locate($command->tenantId, $command->account);
        $this->refresher->refresh($account);

        $synced = $this->accounts->get($account->id());

        return AccountView::fromAccount($synced, ShownMandate::of($this->mandates, $synced->tenantId(), $synced->siret()));
    }
}
