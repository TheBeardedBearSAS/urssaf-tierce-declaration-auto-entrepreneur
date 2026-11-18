<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;

/**
 * Designates an auto-entrepreneur account of a tenant: by account id or by SIRET.
 */
final readonly class AccountSelector
{
    private function __construct(
        #[SensitiveParameter]
        public AccountId|Siret $reference,
    ) {}

    public static function byAccountId(AccountId $accountId): self
    {
        return new self($accountId);
    }

    public static function bySiret(Siret $siret): self
    {
        return new self($siret);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['reference' => '***'];
    }
}
