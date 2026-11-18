<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Refreshes one tracked account (method 020), under the lock of its tenant and SIRET (the SIRET is read from the
 * account: the message only holds identifiers). An unknown account or an invalid identifier is unrecoverable.
 */
final readonly class SyncAccountMessageHandler
{
    public function __construct(
        private SyncAccountHandler $sync,
        private GetAccountHandler $accounts,
        private OperationLocks $locks,
    ) {}

    public function __invoke(SyncAccountMessage $message): void
    {
        MessageFailures::guard(function () use ($message): void {
            $tenant = TenantId::fromString($message->tenantId);
            $selector = AccountSelector::byAccountId(AccountId::fromString($message->accountId));
            $siret = Siret::fromString(($this->accounts)(new GetAccount($tenant, $selector))->siret);
            $this->locks->exclusively(
                OperationLocks::ACCOUNT_SYNC,
                OperationLocks::accountScope($tenant, $siret),
                fn(): mixed => ($this->sync)(new SyncAccount($tenant, $selector)),
                static fn(string $resource): never => throw MessageFailures::busy($resource),
            );
        });
    }
}
