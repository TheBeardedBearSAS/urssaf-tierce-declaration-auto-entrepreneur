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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ConfigurationRules;

/**
 * The predicates of the configuration tree about the environment, the optional components and the scalar formats
 * (each one is true when the value is invalid).
 */
final class ConfigurationRulesEnvironmentTest extends TestCase
{
    public function testTheSimulatorIsRefusedWithTheProductionEnvironmentOnly(): void
    {
        static::assertTrue(ConfigurationRules::simulatedGatewayInProduction(['environment' => 'production', 'gateway' => ['simulated' => true]]));
        static::assertFalse(ConfigurationRules::simulatedGatewayInProduction(['environment' => 'sandbox', 'gateway' => ['simulated' => true]]));
        static::assertFalse(ConfigurationRules::simulatedGatewayInProduction(['environment' => 'production', 'gateway' => ['simulated' => false]]));
    }

    public function testTheLockComponentIsRequiredOnlyWhenTheLockIsExplicitlyEnabled(): void
    {
        static::assertTrue(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => true]], false));
        static::assertFalse(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => true]], true));
        static::assertFalse(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => 'auto']], false));
        static::assertFalse(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => false]], false));
        static::assertFalse(ConfigurationRules::lockEnabledWithoutComponent(['lock' => ['enabled' => true]]));
    }

    public function testTheSchedulerComponentIsRequiredOnlyWhenTheSchedulerIsEnabled(): void
    {
        static::assertTrue(ConfigurationRules::schedulerRequiredButMissing(['scheduler' => ['enabled' => true]], false));
        static::assertFalse(ConfigurationRules::schedulerRequiredButMissing(['scheduler' => ['enabled' => true]], true));
        static::assertFalse(ConfigurationRules::schedulerRequiredButMissing(['scheduler' => ['enabled' => false]], false));
        static::assertFalse(ConfigurationRules::schedulerEnabledWithoutComponent(['scheduler' => ['enabled' => true]]));
    }

    public function testAnEnvironmentPlaceholderIsAcceptedWhileTheContainerIsValidated(): void
    {
        static::assertFalse(ConfigurationRules::isUnknownEnvironment(''));
        static::assertFalse(ConfigurationRules::isUnknownEnvironment('sandbox'));
        static::assertFalse(ConfigurationRules::isUnknownEnvironment('production'));
        static::assertTrue(ConfigurationRules::isUnknownEnvironment('staging'));
        static::assertTrue(ConfigurationRules::isUnknownEnvironment(null));
    }

    public function testTheTimeZoneMustBeAKnownIdentifier(): void
    {
        static::assertFalse(ConfigurationRules::isUnknownTimeZone('Europe/Paris'));
        static::assertTrue(ConfigurationRules::isUnknownTimeZone('Mars/Phobos'));
        static::assertTrue(ConfigurationRules::isUnknownTimeZone(1));
    }

    public function testTheIntervals(): void
    {
        static::assertFalse(ConfigurationRules::isInvalidInterval('PT15M'));
        static::assertTrue(ConfigurationRules::isInvalidInterval('15 minutes'));
        static::assertTrue(ConfigurationRules::isInvalidInterval(15));
        static::assertFalse(ConfigurationRules::isInvalidOptionalInterval(null));
        static::assertFalse(ConfigurationRules::isInvalidOptionalInterval('P1D'));
        static::assertTrue(ConfigurationRules::isInvalidOptionalInterval('daily'));
    }

    public function testOnlyTheWritesOfUnspecifiedIdempotencyCanBeDeclaredSafeToRetry(): void
    {
        foreach (['030', '031', '050', '070', '071'] as $operation) {
            static::assertFalse(ConfigurationRules::isNotRetrySafeWriteCandidate($operation), $operation);
        }
        foreach (['020', '040', '060', '075', '999', 30, null] as $operation) {
            static::assertTrue(ConfigurationRules::isNotRetrySafeWriteCandidate($operation), (string) json_encode($operation));
        }
    }

    public function testThePaymentOrderIsNeverResent(): void
    {
        static::assertTrue(ConfigurationRules::isNeverResentOperation('060'));
        foreach (['020', '050', '999', 60, null] as $operation) {
            static::assertFalse(ConfigurationRules::isNeverResentOperation($operation), (string) json_encode($operation));
        }
    }

    public function testTheDoctrineDriverNeedsDoctrineBundle(): void
    {
        static::assertTrue(ConfigurationRules::doctrineDriverWithoutBundle('doctrine', false));
        static::assertFalse(ConfigurationRules::doctrineDriverWithoutBundle('doctrine', true));
        static::assertFalse(ConfigurationRules::doctrineDriverWithoutBundle('in_memory', false));
        static::assertFalse(ConfigurationRules::doctrineDriverWithoutBundle('custom', false));
    }
}
