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
 * URSSAF refused the declaration (state rejected). Carries the error codes only. Identifiers, periods, states and
 * codes only: never the SIRET, an amount, the birth date nor a URSSAF message (ADR 0010 item 8, ADR 0014 item 3).
 */
final readonly class DeclarationRejected implements DomainEvent
{
    /**
     * @param list<string> $errorCodes
     */
    public function __construct(
        private DeclarationRecordId $recordId,
        private TenantId $tenantId,
        private ?AccountId $accountId,
        private DeclarationPeriod $period,
        private array $errorCodes,
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

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return $this->errorCodes;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
