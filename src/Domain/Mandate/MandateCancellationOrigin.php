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
 * How a {@see Mandate} came to be cancelled (ADR 0009, ADR 0014 `MandateCancelled.origin`).
 */
enum MandateCancellationOrigin: string
{
    /** Cancelled by method 031 through this package (CancelMandate). */
    case Cancelled = 'cancelled';

    /** Cancelled outside the package and recorded without any URSSAF call (RecordMandateCancellation). */
    case Recorded = 'recorded';
}
