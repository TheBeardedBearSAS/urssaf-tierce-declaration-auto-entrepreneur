<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Credentials;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Credentials\InMemoryCredentialsProvider;

final class InMemoryCredentialsProviderTest extends TestCase
{
    public function testReturnsTheCredentialsOfTheTenant(): void
    {
        $credentials = Credentials::of('client-a', 'secret-a');
        $provider = new InMemoryCredentialsProvider([
            'tenant-a' => $credentials,
            'tenant-b' => Credentials::of('client-b', 'secret-b'),
        ]);

        static::assertSame($credentials, $provider->forTenant(TenantId::fromString('tenant-a')));
    }

    public function testUnknownTenantIsRecordNotFound(): void
    {
        $provider = new InMemoryCredentialsProvider([
            'tenant-a' => Credentials::of('client-a', 'secret-a'),
        ]);

        $this->expectExceptionObject(RecordNotFound::withIdentifier('credentials', 'tenant-z'));

        $provider->forTenant(TenantId::fromString('tenant-z'));
    }

    public function testNoCredentialsAtAllIsRecordNotFound(): void
    {
        $provider = new InMemoryCredentialsProvider([]);

        $this->expectExceptionObject(RecordNotFound::withIdentifier('credentials', 'tenant-a'));

        $provider->forTenant(TenantId::fromString('tenant-a'));
    }

    public function testUnknownTenantErrorCarriesTheDocumentedCode(): void
    {
        $provider = new InMemoryCredentialsProvider([]);

        try {
            $provider->forTenant(TenantId::fromString('tenant-a'));
            static::fail('The tenant has no credentials.');
        } catch (RecordNotFound $exception) {
            static::assertSame('credentials.not_found', $exception->violationCode());
            static::assertSame('credentials', $exception->recordType());
            static::assertSame('tenant-a', $exception->identifier());
        }
    }
}
