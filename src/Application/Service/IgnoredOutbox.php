<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Default outbox of the {@see UnitOfWork}: integration events are not recorded.
 */
final readonly class IgnoredOutbox implements Outbox
{
    #[Override]
    public function append(IntegrationEvent ...$events): void {}

    #[Override]
    public function pending(int $limit): array
    {
        return [];
    }

    #[Override]
    public function markDispatched(OutboxEntry $entry, Instant $at): void {}

    #[Override]
    public function markFailed(OutboxEntry $entry, string $error): void {}

    #[Override]
    public function purgeDispatchedBefore(Instant $before): int
    {
        return 0;
    }
}
