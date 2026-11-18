<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\UrssafTierceDeclarationAutoEntrepreneurBundle;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * The tenants parameter and the collaborators the application provides (ADR 0015).
 */
final class BundleTenantsAndProvidersTest extends TestCase
{
    public function testTheTenantsDefaultToTheKeysOfTheCredentials(): void
    {
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        $harness = Harness::boot(['credentials' => ['tenant-2' => ['client_id' => 'client-2', 'client_secret' => 'secret-2']]]);

        static::assertSame(['tenant-1', 'tenant-2'], $harness->kernel->getContainer()->getParameter(UrssafTierceDeclarationAutoEntrepreneurBundle::PARAMETER_TENANTS));
    }

    public function testTenantsAreStringsEvenWhenTheyLookLikeNumbers(): void
    {
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        $harness = Harness::boot(['credentials' => ['42' => ['client_id' => 'client-42', 'client_secret' => 'secret-42']]]);

        static::assertSame(['tenant-1', '42'], $harness->kernel->getContainer()->getParameter(UrssafTierceDeclarationAutoEntrepreneurBundle::PARAMETER_TENANTS));
    }

    public function testTheConfiguredTenantsTakePrecedenceOverTheCredentials(): void
    {
        $harness = Harness::boot(['tenants' => ['tenant-2']]);

        static::assertSame(['tenant-2'], $harness->kernel->getContainer()->getParameter(UrssafTierceDeclarationAutoEntrepreneurBundle::PARAMETER_TENANTS));
    }

    public function testTheTokenStoreCanBeProvidedByTheApplication(): void
    {
        $harness = Harness::boot(['token_store' => ['service' => 'test.token_store']]);

        static::assertSame($harness->privateService('test.token_store', TokenStore::class), $harness->privateService(TokenStore::class, TokenStore::class));
    }

    public function testTheApplicationProvidesTheCredentials(): void
    {
        $harness = Harness::boot(['credentials' => null, 'credentials_provider' => 'test.credentials']);

        static::assertSame($harness->privateService('test.credentials', CredentialsProvider::class), $harness->privateService(CredentialsProvider::class, CredentialsProvider::class));
    }
}
