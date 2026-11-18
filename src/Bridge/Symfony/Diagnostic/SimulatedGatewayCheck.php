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

/**
 * Replaces the authentication check when the gateway is simulated: nothing is sent to URSSAF, which is worth a warning
 * on a real installation.
 */
final readonly class SimulatedGatewayCheck implements InstallationCheck
{
    #[Override]
    public function run(CheckContext $context): array
    {
        return [new CheckResult('simulated gateway', '', CheckStatus::Warning, 'Simulated gateway ("gateway.simulated"): nothing is sent to URSSAF.')];
    }
}
