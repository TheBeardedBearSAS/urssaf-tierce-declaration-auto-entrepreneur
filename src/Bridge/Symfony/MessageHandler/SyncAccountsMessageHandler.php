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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Refreshes the least recently synced accounts of a tenant (method 020), under the lock of the operation. The batch
 * report is discarded: a failed account is left untouched by the use case and retried by the next run.
 */
final readonly class SyncAccountsMessageHandler
{
    public function __construct(private SyncAccountsHandler $sync, private OperationLocks $locks) {}

    public function __invoke(SyncAccountsMessage $message): void
    {
        MessageFailures::guard(function () use ($message): void {
            $tenant = TenantId::fromString($message->tenantId);
            $this->locks->exclusively(
                OperationLocks::ACCOUNTS_SYNC,
                OperationLocks::tenantScope($tenant),
                fn(): mixed => ($this->sync)(new SyncAccounts($tenant, null)),
                static fn(string $resource): never => throw MessageFailures::busy($resource),
            );
        });
    }
}
