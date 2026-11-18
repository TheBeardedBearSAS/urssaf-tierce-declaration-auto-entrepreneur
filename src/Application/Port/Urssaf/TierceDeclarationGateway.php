<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf;

/**
 * Every method of the URSSAF "API Tierce Déclaration auto-entrepreneur" (020 to 075), split in sub-ports (ADR 0000).
 * Depend on the narrowest sub-port a use case needs.
 */
interface TierceDeclarationGateway extends
    AccountReader,
    MandateNotifier,
    ContributionEstimator,
    TurnoverDeclarer,
    ContributionPayer,
    SepaMandateRegistrar,
    SepaMandateRevoker,
    SepaMandateFinder {}
