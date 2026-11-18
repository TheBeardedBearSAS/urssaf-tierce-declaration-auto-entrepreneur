<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\BundleConfiguration;

/**
 * The persistence driver is fit for production: the "in_memory" driver loses every record when the process ends. The
 * database schema of the "doctrine" driver has its own check ({@see DatabaseSchemaCheck}).
 */
final readonly class PersistenceCheck implements InstallationCheck
{
    private const NAME = 'persistence';

    /**
     * @param string $driver the "persistence.driver" option ("in_memory", "doctrine" or "custom")
     */
    public function __construct(private string $driver) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        if ($this->driver === BundleConfiguration::DRIVER_IN_MEMORY) {
            return [new CheckResult(self::NAME, '', CheckStatus::Warning, 'In-memory persistence: every record is lost when the process ends (tests and prototypes only).')];
        }

        if ($this->driver === BundleConfiguration::DRIVER_DOCTRINE) {
            return [new CheckResult(self::NAME, '', CheckStatus::Ok, 'Doctrine repositories (see the database schema check).')];
        }

        return [new CheckResult(self::NAME, '', CheckStatus::Ok, 'Repositories of the application ("custom" driver).')];
    }
}
