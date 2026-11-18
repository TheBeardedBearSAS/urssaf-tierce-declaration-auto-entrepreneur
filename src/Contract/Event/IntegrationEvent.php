<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event;

/**
 * A fact of the auto-entrepreneur workflow published to the host application through the outbox: scalar properties
 * only, so that any Messenger serializer handles it, and a stable contract versioned independently of the domain model
 * (ADR 0014).
 *
 * - eventId: stable identifier of the fact (the same fact always has the same id): deduplicate on it, the relay
 *   delivers at least once;
 * - occurredAt: ISO 8601 instant (UTC) at which the fact happened;
 * - tenantId: the tenant of the aggregate (ADR 0012);
 * - aggregateId: the local id of the account, mandate or record (declaration or payment); null for the SEPA mandate
 *   events and for an erasure without a tracked account;
 * - externalReference: the PaymentReference of a payment event, null for every other type.
 */
abstract readonly class IntegrationEvent
{
    /**
     * Name of the event type, for example "urssaf.tdae.declaration.declared" (ADR 0014).
     *
     * @var string
     */
    public const TYPE = 'urssaf.tdae.event';

    /**
     * Version of the payload of the type; incremented on a breaking change of its properties.
     *
     * @var positive-int
     */
    public const VERSION = 1;

    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $tenantId,
        public ?string $aggregateId,
        public ?string $externalReference,
    ) {}

    final public function type(): string
    {
        return static::TYPE;
    }

    final public function version(): int
    {
        return static::VERSION;
    }

    /**
     * Every property, by name (the named arguments of the constructor).
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        // @mago-expect lint:inline-variable-return The variable carries the key type that get_object_vars() loses.
        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($this);

        return $properties;
    }
}
