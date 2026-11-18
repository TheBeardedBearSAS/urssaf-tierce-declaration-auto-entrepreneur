<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony;

use LogicException;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\BundleConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ConfigurationRules;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\InstallationCheck;

/**
 * Symfony integration of the URSSAF "API Tierce Déclaration auto-entrepreneur" client (Symfony 6.4 and 7.x), ADR 0015.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final class UrssafTierceDeclarationAutoEntrepreneurBundle extends AbstractBundle
{
    public const ALIAS = 'urssaf_tierce_declaration_auto_entrepreneur';
    public const PARAMETER_TENANTS = ServiceIds::PARAMETER_TENANTS;

    protected string $extensionAlias = self::ALIAS;

    /**
     * The bundle directory ({@see AbstractBundle} assumes a class one level below it), so that the framework finds the
     * shipped translations in Resources/translations.
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        BundleConfiguration::define($definition);
    }

    /**
     * Fails fast when the "doctrine" persistence driver is selected (the default when the ORM is installed) and
     * DoctrineBundle is not registered: this method receives the real container, whereas {@see loadExtension()}
     * receives a per-extension container where no other extension is visible (ADR 0015 item 4). The Doctrine mapping
     * is prepended by the Doctrine bridge (lot 6): nothing is prepended yet.
     *
     * @throws LogicException the "doctrine" driver is selected and DoctrineBundle is not registered
     */
    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $driver = self::selectedDriver($container->getExtensionConfig(self::ALIAS));
        if (ConfigurationRules::doctrineDriverWithoutBundle($driver, $container->hasExtension('doctrine'))) {
            throw new LogicException('The "doctrine" persistence driver requires DoctrineBundle (doctrine/doctrine-bundle): register it, or set "persistence.driver" to "in_memory" or "custom".');
        }
    }

    /**
     * @param array<array-key, mixed> $config the configuration validated by {@see BundleConfiguration}
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        /** @var Config $validated */
        $validated = $config;
        $tenants = $validated['tenants'] !== [] ? $validated['tenants'] : array_map(strval(...), array_keys($validated['credentials']));
        $configurator->parameters()->set(ServiceIds::PARAMETER_TENANTS, $tenants);
        (new ServiceRegistrar($configurator->services()))->register($validated);
        $container->registerForAutoconfiguration(InstallationCheck::class)->addTag(ServiceIds::TAG_INSTALLATION_CHECK);
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $configs raw configurations of the bundle, in merge order
     */
    private static function selectedDriver(array $configs): string
    {
        $driver = BundleConfiguration::defaultDriver();
        foreach ($configs as $config) {
            $persistence = \is_array($config['persistence'] ?? null) ? $config['persistence'] : [];
            $driver = \is_string($persistence['driver'] ?? null) ? $persistence['driver'] : $driver;
        }

        return $driver;
    }
}
