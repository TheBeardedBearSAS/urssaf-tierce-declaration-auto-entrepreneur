<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Diagnostic;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CheckContext;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CheckResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CheckStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\InstallationCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;

/**
 * The value types of the installation report and the autoconfiguration of the checks of the host.
 */
final class CheckTypesTest extends TestCase
{
    public function testAContextWithRemoteCallsAllowsThem(): void
    {
        $tenant = TenantId::fromString('tenant-1');

        $context = CheckContext::withRemoteCalls([$tenant]);

        static::assertTrue($context->remoteCallsAllowed);
        static::assertSame([$tenant], $context->tenants);
    }

    public function testAContextWithoutRemoteCallsForbidsThem(): void
    {
        $tenants = [TenantId::fromString('tenant-1'), TenantId::fromString('tenant-2')];

        $context = CheckContext::withoutRemoteCalls($tenants);

        static::assertFalse($context->remoteCallsAllowed);
        static::assertSame($tenants, $context->tenants);
    }

    public function testAResultCarriesWhatWasChecked(): void
    {
        $result = new CheckResult('credentials', 'default', CheckStatus::Warning, 'Something to look at.');

        static::assertSame('credentials', $result->check);
        static::assertSame('default', $result->subject);
        static::assertSame(CheckStatus::Warning, $result->status);
        static::assertSame('Something to look at.', $result->detail);
    }

    public function testTheStatusesAreTheOnesOfTheReport(): void
    {
        static::assertSame(
            ['ok', 'warning', 'failure', 'skipped'],
            array_map(static fn(CheckStatus $status): string => $status->value, CheckStatus::cases()),
        );
    }

    public function testTheChecksOfTheHostAreAutoconfiguredWithTheTag(): void
    {
        $autoconfigured = (new TestKernel())->definitions()->getAutoconfiguredInstanceof();

        static::assertArrayHasKey(InstallationCheck::class, $autoconfigured);
        static::assertSame(['urssaf_tierce_declaration_auto_entrepreneur.installation_check' => [[]]], $autoconfigured[InstallationCheck::class]->getTags());
    }
}
