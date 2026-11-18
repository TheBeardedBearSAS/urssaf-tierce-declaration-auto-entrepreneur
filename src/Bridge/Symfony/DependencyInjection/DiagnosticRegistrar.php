<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CheckInstallationCommand;
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

/**
 * "urssaf:tdae:check" and its checks, in report order (registration order, host checks afterwards unless they have a
 * positive tag priority).
 *
 * The configuration and the token provider reach their checks through closures, so that an invalid configuration is
 * reported by the "configuration" check instead of preventing the command from running.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class DiagnosticRegistrar
{
    /**
     * @param Config $config
     */
    public function register(ServicesConfigurator $services, array $config): void
    {
        self::check($services, ConfigurationCheck::class, [new ServiceClosureArgument(new Reference(ServiceIds::API_CONFIGURATION))]);
        self::check($services, CredentialsCheck::class, [new Reference(CredentialsProvider::class)]);
        [$gatewayCheck, $gatewayArguments] = $config['gateway']['simulated']
            ? [SimulatedGatewayCheck::class, []]
            : [AuthenticationCheck::class, [new Reference(CredentialsProvider::class), new ServiceClosureArgument(new Reference(ServiceIds::PROBE_TOKEN_PROVIDER))]];
        self::check($services, $gatewayCheck, $gatewayArguments);
        self::check($services, TokenStoreCheck::class, [$config['token_store']['service'], $config['token_store']['cache_pool']]);
        self::check($services, PersistenceCheck::class, [$config['persistence']['driver']]);
        self::check($services, LockCheck::class, [new Reference(OperationLocks::class)]);
        $limiter = $config['rate_limiter'];
        self::check($services, RateLimiterCheck::class, [$limiter['enabled'], $limiter['limit'], $limiter['service'], ConfigurationRules::seconds($limiter['window'])]);

        $services->set(CheckInstallationCommand::class)
            ->args([new TaggedIteratorArgument(ServiceIds::TAG_INSTALLATION_CHECK), '%' . ServiceIds::PARAMETER_TENANTS . '%'])
            ->tag('console.command');
    }

    /**
     * @param class-string<InstallationCheck> $class
     * @param list<mixed>                     $arguments
     */
    private static function check(ServicesConfigurator $services, string $class, array $arguments): void
    {
        $services->set($class)->args($arguments)->tag(ServiceIds::TAG_INSTALLATION_CHECK);
    }
}
