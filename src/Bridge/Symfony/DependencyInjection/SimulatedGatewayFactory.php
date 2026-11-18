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

use LogicException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;

/**
 * Container factory of the simulated gateway: the runtime guard of the "gateway.simulated" rule for an environment that
 * is a placeholder at build time (the build-time rule only sees a literal "production"), ADR 0015.
 */
final class SimulatedGatewayFactory
{
    /**
     * @param string $environment the resolved "environment" option
     * @param Clock  $clock       the clock of the package (the simulator stamps its results with it)
     *
     * @throws LogicException when the environment is "production"
     */
    public static function create(string $environment, Clock $clock): SimulatedAeGateway
    {
        if (Environment::from($environment) === Environment::Production) {
            throw new LogicException('"gateway.simulated" is meant for tests and cannot be used with the production environment.');
        }

        return new SimulatedAeGateway($clock);
    }
}
