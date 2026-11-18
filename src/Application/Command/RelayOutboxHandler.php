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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\RelayReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use Throwable;

/**
 * At-least-once delivery of the outbox: an event is marked as dispatched only once the sink accepted it. A failed event
 * stays pending, and the next events of the same aggregate are not delivered in this run, so that the host receives the
 * events of a record in order. The events without an aggregate (SEPA, an erasure without a tracked account) are ordered
 * per tenant: a failed one holds back the next aggregate-less events of the same tenant only, never an aggregate's
 * events (ADR 0014). Runs must not overlap (the Symfony bridge takes a lock).
 *
 * A failure is stored as the class and the code of the sink exception only, never its message: a host sink may put
 * personal data in it (ADR 0010).
 */
final readonly class RelayOutboxHandler
{
    public function __construct(
        private Outbox $outbox,
        private IntegrationEventSink $sink,
        private Clock $clock,
        private WorkflowPolicy $policy,
    ) {}

    public function __invoke(RelayOutbox $command): RelayReport
    {
        $dispatched = 0;
        $failed = 0;
        $deferred = 0;
        $blockedAggregates = [];
        $blockedTenants = [];
        foreach ($this->outbox->pending($this->policy->pageSize($command->limit)) as $entry) {
            $event = $entry->event;
            $aggregate = $event->aggregateId;
            if ($aggregate === null ? \array_key_exists($event->tenantId, $blockedTenants) : \array_key_exists($aggregate, $blockedAggregates)) {
                ++$deferred;

                continue;
            }

            try {
                $this->sink->dispatch($event);
            } catch (Throwable $failure) {
                $this->outbox->markFailed($entry, \sprintf('%s (code %s)', $failure::class, $failure->getCode()));
                if ($aggregate !== null) {
                    $blockedAggregates[$aggregate] = $entry->sequence;
                }
                if ($aggregate === null) {
                    $blockedTenants[$event->tenantId] = $entry->sequence;
                }
                ++$failed;

                continue;
            }
            $this->outbox->markDispatched($entry, Instant::fromDateTime($this->clock->now()));
            ++$dispatched;
        }

        return new RelayReport($dispatched, $failed, $deferred);
    }
}
