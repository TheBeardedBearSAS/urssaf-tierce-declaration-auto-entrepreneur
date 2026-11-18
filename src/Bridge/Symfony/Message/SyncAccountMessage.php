<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message;

/**
 * Asks to refresh (method 020) one tracked account. Holds identifiers only (no personal data, ADR 0010); safe to
 * retry, it is a read (ADR 0003, ADR 0015 item 9).
 */
final readonly class SyncAccountMessage
{
    public function __construct(public string $tenantId, public string $accountId) {}
}
