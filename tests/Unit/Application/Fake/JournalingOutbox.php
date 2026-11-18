<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Outbox that writes each appended event type in the journal (to check that it happens inside the transaction) and
 * keeps the appended events.
 */
final class JournalingOutbox implements Outbox
{
    /** @var list<IntegrationEvent> */
    private array $appended = [];

    public function __construct(private readonly Journal $journal) {}

    #[Override]
    public function append(IntegrationEvent ...$events): void
    {
        $this->appended = [...$this->appended, ...array_values($events)];
        $this->journal->add('append:' . implode(',', array_map(static fn(IntegrationEvent $event): string => $event->type(), $events)));
    }

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

    /**
     * @return list<IntegrationEvent>
     */
    public function appended(): array
    {
        return $this->appended;
    }
}
