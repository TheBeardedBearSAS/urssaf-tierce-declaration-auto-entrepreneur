<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event;

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateCancellationOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The mandate is cancelled (method 031 succeeded, or the cancellation was recorded without a call). Identifiers only,
 * never the SIRET nor the birth date (ADR 0010 item 8, ADR 0014 item 3).
 */
final readonly class MandateCancelled implements DomainEvent
{
    public function __construct(
        private MandateId $mandateId,
        private TenantId $tenantId,
        private ?AccountId $accountId,
        private MandateCancellationOrigin $origin,
        private Instant $occurredAt,
    ) {}

    public function mandateId(): MandateId
    {
        return $this->mandateId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function accountId(): ?AccountId
    {
        return $this->accountId;
    }

    public function origin(): MandateCancellationOrigin
    {
        return $this->origin;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
