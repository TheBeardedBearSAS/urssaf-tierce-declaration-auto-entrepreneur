<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use DateTimeZone;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;

final class Stacks
{
    public static function tenant(): TenantId
    {
        return TenantId::fromString('tenant-1');
    }

    public static function sandbox(): Stack
    {
        return new Stack(ApiConfiguration::forEnvironment(Environment::Sandbox), 0);
    }

    public static function production(): Stack
    {
        return new Stack(ApiConfiguration::forEnvironment(Environment::Production), 0);
    }

    /**
     * Configuration that adds the SIRET to the call log lines (ADR 0010).
     */
    public static function configurationLoggingSiret(): ApiConfiguration
    {
        return new ApiConfiguration(
            Environment::Sandbox,
            null,
            null,
            null,
            ApiConfiguration::DEFAULT_TOKEN_SAFETY_MARGIN_SECONDS,
            ApiConfiguration::DEFAULT_USER_AGENT,
            new DateTimeZone('UTC'),
            [],
            true,
        );
    }

    public static function configured(ApiConfiguration $configuration): Stack
    {
        return new Stack($configuration, 0);
    }

    /**
     * Sandbox stack whose clock advances by the given number of microseconds at every now() call.
     */
    public static function stepping(int $stepMicroseconds): Stack
    {
        return new Stack(ApiConfiguration::forEnvironment(Environment::Sandbox), $stepMicroseconds);
    }
}
