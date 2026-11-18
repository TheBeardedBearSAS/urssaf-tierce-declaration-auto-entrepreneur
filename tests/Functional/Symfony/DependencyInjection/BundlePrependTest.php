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

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\UrssafTierceDeclarationAutoEntrepreneurBundle;

/**
 * How the bundle reads the raw configurations to select the persistence driver while the container is prepared (ADR 0015
 * item 4): the last configuration that sets the driver wins, whatever the other ones leave out. Nothing is prepended to
 * DoctrineBundle before the Doctrine bridge (lot 6).
 */
final class BundlePrependTest extends TestCase
{
    private const MESSAGE = 'The "doctrine" persistence driver requires DoctrineBundle (doctrine/doctrine-bundle): register it, or set "persistence.driver" to "in_memory" or "custom".';

    public function testTheLastConfigurationThatSetsTheDriverWins(): void
    {
        $container = self::withoutDoctrine([['persistence' => ['driver' => 'doctrine']], ['persistence' => ['driver' => 'in_memory']]]);

        self::prepend($container);

        static::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testTheDriverIsKeptByAConfigurationThatLeavesItOut(): void
    {
        $container = self::withoutDoctrine([['persistence' => ['driver' => 'doctrine']], ['persistence' => []], []]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::MESSAGE);

        self::prepend($container);
    }

    public function testAnotherDriverThanDoctrineNeedsNoDoctrineBundle(): void
    {
        foreach (['custom', 'in_memory'] as $driver) {
            $container = self::withoutDoctrine([['persistence' => ['driver' => $driver]]]);

            self::prepend($container);

            static::assertSame([], $container->getExtensionConfig('doctrine'));
        }
    }

    public function testTheDoctrineDriverFailsWithoutDoctrineBundle(): void
    {
        $container = self::withoutDoctrine([['persistence' => ['driver' => 'doctrine']]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::MESSAGE);

        self::prepend($container);
    }

    public function testTheDefaultDriverFailsWithoutDoctrineBundleWhenTheOrmIsInstalled(): void
    {
        $container = self::withoutDoctrine([[]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::MESSAGE);

        self::prepend($container);
    }

    public function testTheDriverIsDoctrineByDefaultWhenTheDriverIsNotAString(): void
    {
        $container = self::withoutDoctrine([['persistence' => null], ['persistence' => ['driver' => 42]]]);

        $this->expectException(LogicException::class);

        self::prepend($container);
    }

    public function testTheDoctrineDriverIsAcceptedWithDoctrineBundleAndPrependsNothingYet(): void
    {
        $container = self::withoutDoctrine([['persistence' => ['driver' => 'doctrine']]]);
        $doctrine = (new DoctrineBundle())->getContainerExtension();
        static::assertNotNull($doctrine);
        $container->registerExtension($doctrine);

        self::prepend($container);

        static::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    /**
     * @param list<array<string, mixed>> $configs raw configurations of the bundle, in merge order
     */
    private static function withoutDoctrine(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = (new UrssafTierceDeclarationAutoEntrepreneurBundle())->getContainerExtension();
        static::assertNotNull($extension);
        $container->registerExtension($extension);
        foreach ($configs as $config) {
            $container->loadFromExtension(UrssafTierceDeclarationAutoEntrepreneurBundle::ALIAS, $config);
        }

        return $container;
    }

    private static function prepend(ContainerBuilder $container): void
    {
        $instanceof = [];
        (new UrssafTierceDeclarationAutoEntrepreneurBundle())->prependExtension(
            new ContainerConfigurator($container, new PhpFileLoader($container, new FileLocator()), $instanceof, __FILE__, __FILE__),
            $container,
        );
    }
}
