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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Scheduler\UrssafScheduleProvider;

/**
 * The Scheduler schedule provider running the safety crons of the package (no write, ADR 0015).
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class SchedulerRegistrar
{
    /**
     * @param Config $config
     */
    public function register(ServicesConfigurator $services, array $config): void
    {
        $frequencies = $config['scheduler']['frequencies'];
        // Without the outbox, its commands do not exist.
        $services->set(UrssafScheduleProvider::class)
            ->args([$config['outbox']['enabled'] ? $frequencies : [...$frequencies, 'relay_outbox' => null, 'purge_outbox' => null]])
            ->tag('scheduler.schedule_provider', ['name' => $config['scheduler']['name']]);
    }
}
