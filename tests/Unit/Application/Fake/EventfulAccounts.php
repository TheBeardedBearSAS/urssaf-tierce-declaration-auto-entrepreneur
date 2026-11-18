<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Account repository whose lookup by SIRET returns an account that holds an unreleased event ("AccountSynced"), like an
 * aggregate that records an event while it changes: the use cases must record the events of every account they save.
 */
final readonly class EventfulAccounts implements AeAccountRepository
{
    public function __construct(private AeAccountRepository $inner) {}

    #[Override]
    public function save(AeAccount $account): void
    {
        $this->inner->save($account);
    }

    #[Override]
    public function get(AccountId $id): AeAccount
    {
        return $this->inner->get($id);
    }

    #[Override]
    public function findBySiret(TenantId $tenantId, Siret $siret): ?AeAccount
    {
        $account = $this->inner->findBySiret($tenantId, $siret);
        $account?->sync(AccountWorld::status(true), Instant::fromIso8601('2026-11-17T08:00:00Z'));

        return $account;
    }

    #[Override]
    public function findLeastRecentlySynced(TenantId $tenantId, int $limit): array
    {
        return $this->inner->findLeastRecentlySynced($tenantId, $limit);
    }

    #[Override]
    public function tenants(): array
    {
        return $this->inner->tenants();
    }

    #[Override]
    public function delete(AccountId $id): void
    {
        $this->inner->delete($id);
    }
}
