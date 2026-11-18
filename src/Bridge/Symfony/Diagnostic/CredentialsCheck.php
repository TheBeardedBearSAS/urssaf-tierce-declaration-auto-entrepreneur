<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Every tenant has credentials in the configured provider (nothing is sent to URSSAF).
 */
final readonly class CredentialsCheck implements InstallationCheck
{
    private const NAME = 'credentials';

    public function __construct(private CredentialsProvider $credentials) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        return array_map($this->check(...), $context->tenants);
    }

    private function check(TenantId $tenantId): CheckResult
    {
        try {
            $this->credentials->forTenant($tenantId);
        } catch (UrssafException $failure) {
            return new CheckResult(self::NAME, $tenantId->value(), CheckStatus::Failure, $failure->getMessage());
        }

        return new CheckResult(self::NAME, $tenantId->value(), CheckStatus::Ok, 'Credentials found.');
    }
}
