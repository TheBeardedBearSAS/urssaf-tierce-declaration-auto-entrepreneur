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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Account repository that does not see the account of a SIRET the first time it is looked up, like another process that
 * tracks the SIRET right after the lookup: the following lookups see it.
 */
final class LateAccounts implements AeAccountRepository
{
    private bool $hidden = true;

    public function __construct(private readonly AeAccountRepository $inner) {}

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
        if ($this->hidden) {
            $this->hidden = false;

            return null;
        }

        return $this->inner->findBySiret($tenantId, $siret);
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
