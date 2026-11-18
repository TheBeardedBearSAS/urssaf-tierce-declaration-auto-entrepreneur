<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event;

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * A turnover declaration is recorded (state pending). `supersedes` is the record this one replaces after a re-entry
 * (ADR 0009). Identifiers, periods, states and codes only: never the SIRET, an amount, the birth date nor a URSSAF
 * message (ADR 0010 item 8, ADR 0014 item 3).
 */
final readonly class DeclarationRecorded implements DomainEvent
{
    public function __construct(
        private DeclarationRecordId $recordId,
        private TenantId $tenantId,
        private ?AccountId $accountId,
        private DeclarationPeriod $period,
        private ?DeclarationRecordId $supersedes,
        private Instant $occurredAt,
    ) {}

    public function recordId(): DeclarationRecordId
    {
        return $this->recordId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function accountId(): ?AccountId
    {
        return $this->accountId;
    }

    public function period(): DeclarationPeriod
    {
        return $this->period;
    }

    public function supersedes(): ?DeclarationRecordId
    {
        return $this->supersedes;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
