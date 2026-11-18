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
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CheckInstallationCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\AuthenticationCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\ConfigurationCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CredentialsCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\InstallationCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\LockCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\PersistenceCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\RateLimiterCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\SimulatedGatewayCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\TokenStoreCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;

/**
 * The check command and the checks it runs, as declared by the bundle.
 */
final class DiagnosticRegistrationTest extends TestCase
{
    public function testTheChecksAreRegisteredInReportOrderWithTheTag(): void
    {
        $container = (new TestKernel())->definitions();

        $tagged = array_keys($container->findTaggedServiceIds(ServiceIds::TAG_INSTALLATION_CHECK));

        static::assertSame(
            [ConfigurationCheck::class, CredentialsCheck::class, AuthenticationCheck::class, TokenStoreCheck::class, PersistenceCheck::class, LockCheck::class, RateLimiterCheck::class],
            $tagged,
        );
    }

    public function testTheChecksReceiveTheirCollaborators(): void
    {
        $container = (new TestKernel([
            'token_store' => ['service' => 'test.token_store', 'cache_pool' => 'cache.system'],
            'rate_limiter' => ['enabled' => true, 'limit' => 120, 'window' => 'PT2M', 'service' => 'test.limiter'],
        ]))->definitions();

        static::assertEquals([new ServiceClosureArgument(new Reference(ServiceIds::API_CONFIGURATION))], $container->getDefinition(ConfigurationCheck::class)->getArguments());
        static::assertEquals([new Reference(CredentialsProvider::class)], $container->getDefinition(CredentialsCheck::class)->getArguments());
        static::assertEquals(
            [new Reference(CredentialsProvider::class), new ServiceClosureArgument(new Reference(ServiceIds::PROBE_TOKEN_PROVIDER))],
            $container->getDefinition(AuthenticationCheck::class)->getArguments(),
        );
        static::assertSame(['test.token_store', 'cache.system'], $container->getDefinition(TokenStoreCheck::class)->getArguments());
        static::assertSame(['in_memory'], $container->getDefinition(PersistenceCheck::class)->getArguments());
        static::assertEquals([new Reference(OperationLocks::class)], $container->getDefinition(LockCheck::class)->getArguments());
        static::assertSame([true, 120, 'test.limiter', 120], $container->getDefinition(RateLimiterCheck::class)->getArguments());
    }

    public function testTheSimulatedGatewayReplacesTheAuthenticationCheck(): void
    {
        $container = (new TestKernel(['gateway' => ['simulated' => true]]))->definitions();

        static::assertTrue($container->hasDefinition(SimulatedGatewayCheck::class));
        static::assertTrue($container->getDefinition(SimulatedGatewayCheck::class)->hasTag(ServiceIds::TAG_INSTALLATION_CHECK));
        static::assertFalse($container->hasDefinition(AuthenticationCheck::class));
    }

    public function testTheCommandIsAConsoleCommandOverTheTaggedChecksAndTheTenants(): void
    {
        $container = (new TestKernel())->definitions();

        $definition = $container->getDefinition(CheckInstallationCommand::class);

        static::assertTrue($definition->hasTag('console.command'));
        static::assertEquals([new TaggedIteratorArgument(InstallationCheck::TAG), '%' . ServiceIds::PARAMETER_TENANTS . '%'], $definition->getArguments());
    }
}
