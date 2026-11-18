<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use DateInterval;

/**
 * Deletes the outbox entries dispatched for longer than the retention (the policy "outboxRetention" when null).
 */
final readonly class PurgeOutbox
{
    public function __construct(public ?DateInterval $retention) {}
}
