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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceIds;

/**
 * A check run by `urssaf:tdae:check`. The services implementing it are tagged with {@see ServiceIds::TAG_INSTALLATION_CHECK}
 * (autoconfigured) and run after the checks of the package, unless their tag has a positive priority.
 */
interface InstallationCheck
{
    public const TAG = ServiceIds::TAG_INSTALLATION_CHECK;

    /**
     * @return list<CheckResult>
     */
    public function run(CheckContext $context): array;
}
