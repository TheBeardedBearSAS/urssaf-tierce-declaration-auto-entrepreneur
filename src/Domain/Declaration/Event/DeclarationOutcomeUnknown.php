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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The outcome of the submission is unknown (state outcome_unknown): the request may have reached URSSAF, so it is
 * never resent automatically (ADR 0003). Identifiers, periods, states and codes only: never the SIRET, an amount,
 * the birth date nor a URSSAF message (ADR 0010 item 8, ADR 0014 item 3).
 */
final readonly class DeclarationOutcomeUnknown implements DomainEvent
{
    public function __construct(
        private DeclarationRecordId $recordId,
        private TenantId $tenantId,
        private ?AccountId $accountId,
        private DeclarationPeriod $period,
        private OutcomeUnknownReason $reason,
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

    public function reason(): OutcomeUnknownReason
    {
        return $this->reason;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
