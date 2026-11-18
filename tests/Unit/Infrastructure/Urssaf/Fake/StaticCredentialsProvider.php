<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

final class StaticCredentialsProvider implements CredentialsProvider
{
    public const CLIENT_ID = 'fictitious-client-id';
    public const SHARED_VALUE = 'fictitious-client-secret-value';

    public function forTenant(TenantId $tenantId): Credentials
    {
        return Credentials::of(self::CLIENT_ID, self::SHARED_VALUE);
    }
}
