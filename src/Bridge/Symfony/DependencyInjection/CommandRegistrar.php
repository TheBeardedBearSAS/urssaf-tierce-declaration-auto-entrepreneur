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

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CancelMandateCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\DeclareTurnoverCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\EstimateContributionsCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\ForgetAccountCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\ImportMandateCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\ListSepaMandatesCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\NotifyMandateCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\PayContributionsCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\PurgeOutboxCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\ReconcileStaleDeclarationsCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\ReconcileStalePaymentsCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\RecordMandateCancellationCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\RegisterSepaMandateCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\RelayOutboxCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\RequeueInvalidCredentialsCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\ResolveDeclarationOutcomeCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\ResolvePaymentOutcomeCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\RevokeSepaMandateCommand;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\SyncAccountsCommand;

/**
 * The console commands (`urssaf:tdae:*`), tagged "console.command". The outbox commands exist only with the outbox, as
 * their handlers do; the commands that work for one tenant fall back on the configured tenants. The `check` command
 * belongs to the diagnostic registrar.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class CommandRegistrar
{
    /** Commands that need no tenant, or that fall back on nothing. */
    private const TENANTLESS_COMMANDS = [
        ReconcileStaleDeclarationsCommand::class,
        ReconcileStalePaymentsCommand::class,
    ];

    /** Commands that fall back on the configured tenants. */
    private const TENANT_COMMANDS = [
        NotifyMandateCommand::class,
        CancelMandateCommand::class,
        ImportMandateCommand::class,
        RecordMandateCancellationCommand::class,
        EstimateContributionsCommand::class,
        DeclareTurnoverCommand::class,
        PayContributionsCommand::class,
        RegisterSepaMandateCommand::class,
        RevokeSepaMandateCommand::class,
        ListSepaMandatesCommand::class,
        ResolveDeclarationOutcomeCommand::class,
        ResolvePaymentOutcomeCommand::class,
        RequeueInvalidCredentialsCommand::class,
        ForgetAccountCommand::class,
    ];

    private const OUTBOX_COMMANDS = [
        RelayOutboxCommand::class,
        PurgeOutboxCommand::class,
    ];

    /**
     * @param Config $config
     */
    public function register(ServicesConfigurator $services, array $config): void
    {
        $services->set(CommandLocks::class)->autowire();
        $commands = $config['outbox']['enabled'] ? [...self::TENANTLESS_COMMANDS, ...self::OUTBOX_COMMANDS] : self::TENANTLESS_COMMANDS;
        foreach ($commands as $command) {
            $services->set($command)->autowire()->tag('console.command');
        }
        foreach (self::TENANT_COMMANDS as $command) {
            $services->set($command)
                ->autowire()
                ->arg('$configuredTenants', '%' . ServiceIds::PARAMETER_TENANTS . '%')
                ->tag('console.command');
        }
        $services->set(SyncAccountsCommand::class)
            ->autowire()
            ->arg('$bus', $config['messenger']['enabled'] ? new Reference($config['messenger']['bus']) : null)
            ->arg('$configuredTenants', '%' . ServiceIds::PARAMETER_TENANTS . '%')
            ->tag('console.command');
    }
}
