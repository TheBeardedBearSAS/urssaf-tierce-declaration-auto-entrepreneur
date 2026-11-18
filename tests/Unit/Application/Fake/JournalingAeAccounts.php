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

use Closure;
use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;

/**
 * Decorator over the production in-memory repository: journals every save ("save:<id>") and every delete
 * ("delete:<id>").
 */
final class JournalingAeAccounts implements AeAccountRepository
{
    private readonly InMemoryAeAccountRepository $inner;

    /** @var (Closure(InMemoryAeAccountRepository): void)|null */
    private ?Closure $intrusion = null;

    public function __construct(private readonly Journal $journal)
    {
        $this->inner = new InMemoryAeAccountRepository();
    }

    public function inner(): InMemoryAeAccountRepository
    {
        return $this->inner;
    }

    /**
     * Stores without journaling (test setup).
     */
    public function add(AeAccount ...$accounts): void
    {
        foreach ($accounts as $account) {
            $this->inner->save($account);
        }
    }

    /**
     * Runs $intrusion once, just before the next save reaches the store: another process writing in between.
     *
     * @param Closure(InMemoryAeAccountRepository): void $intrusion
     */
    public function beforeNextSave(Closure $intrusion): void
    {
        $this->intrusion = $intrusion;
    }

    #[Override]
    public function save(AeAccount $account): void
    {
        $this->journal->add('save:' . $account->id()->value());
        if ($this->intrusion instanceof Closure) {
            $intrusion = $this->intrusion;
            $this->intrusion = null;
            $intrusion($this->inner);
        }
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
        $this->journal->add('delete:' . $id->value());
        $this->inner->delete($id);
    }
}
