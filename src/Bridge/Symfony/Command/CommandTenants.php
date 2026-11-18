<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command;

use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Reading of the tenants of the commands: --tenant, else the configured tenants.
 */
final class CommandTenants
{
    /**
     * The tenant given with --tenant, else the only configured tenant.
     *
     * @param list<string> $configured
     *
     * @throws InvalidOptionException when --tenant is missing and there is not exactly one configured tenant
     */
    public static function tenantOrSingleConfigured(InputInterface $input, array $configured): TenantId
    {
        $tenant = CommandOptions::tenant($input);
        if ($tenant instanceof TenantId) {
            return $tenant;
        }
        if (\count($configured) !== 1) {
            throw new InvalidOptionException(\sprintf('The --tenant option is required (configured tenants: %d).', \count($configured)));
        }

        return TenantId::fromString($configured[0]);
    }

    /**
     * The tenant given with --tenant, else every configured tenant (the diagnostic commands).
     *
     * @param list<string> $configured
     *
     * @return non-empty-list<TenantId>
     *
     * @throws InvalidOptionException when no tenant can be determined
     */
    public static function tenantsOrConfigured(InputInterface $input, array $configured): array
    {
        $tenant = CommandOptions::tenant($input);
        if ($tenant instanceof TenantId) {
            return [$tenant];
        }
        $tenants = array_map(TenantId::fromString(...), $configured);

        return $tenants === [] ? throw new InvalidOptionException('No tenant to process: use --tenant=<id> or configure "urssaf_tierce_declaration_auto_entrepreneur.tenants".') : $tenants;
    }
}
