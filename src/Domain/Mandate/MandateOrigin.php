<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate;

/**
 * How a {@see Mandate} came to be tracked (ADR 0009).
 */
enum MandateOrigin: string
{
    /** Notified by method 030 through this package (NotifyMandate). */
    case Notified = 'notified';

    /** Notified outside the package and adopted without any URSSAF call (ImportMandate). */
    case Imported = 'imported';
}
