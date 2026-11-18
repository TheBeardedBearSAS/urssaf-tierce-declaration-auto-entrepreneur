<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Resolves an {@see AccountSelector} to the tracked account of a tenant. The SIRET is never the identifier of a
 * {@see RecordNotFound} (ADR 0010 item 5).
 */
final readonly class AccountLocator
{
    private const RECORD_TYPE = 'ae_account';

    public function __construct(private AeAccountRepository $accounts) {}

    /**
     * @throws RecordNotFound "ae_account.not_found" (also for an account of another tenant)
     */
    public function locate(TenantId $tenantId, AccountSelector $selector): AeAccount
    {
        return $this->find($tenantId, $selector) ?? throw RecordNotFound::withIdentifier(self::RECORD_TYPE, '***');
    }

    /**
     * The account of a SIRET selector, or null when the SIRET is not tracked. An account id that matches nothing, or an
     * account of another tenant, is still an error.
     *
     * @throws RecordNotFound "ae_account.not_found" for an account id
     */
    public function find(TenantId $tenantId, AccountSelector $selector): ?AeAccount
    {
        $reference = $selector->reference;

        return $reference instanceof Siret
            ? $this->accounts->findBySiret($tenantId, $reference)
            : $this->byId($tenantId, $reference);
    }

    /**
     * The SIRET of the selector: itself, or the one of the account an id designates.
     *
     * @throws RecordNotFound "ae_account.not_found" for an account id
     */
    public function siretOf(TenantId $tenantId, AccountSelector $selector): Siret
    {
        $reference = $selector->reference;

        return $reference instanceof Siret ? $reference : $this->byId($tenantId, $reference)->siret();
    }

    private function byId(TenantId $tenantId, AccountId $id): AeAccount
    {
        $account = $this->accounts->get($id);
        if (!$account->tenantId()->equals($tenantId)) {
            throw RecordNotFound::withIdentifier(self::RECORD_TYPE, $id->value());
        }

        return $account;
    }
}
