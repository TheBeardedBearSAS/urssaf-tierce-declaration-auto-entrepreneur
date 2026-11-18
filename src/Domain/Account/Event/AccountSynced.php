<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event;

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * A method 020 snapshot was tracked for the first time or changed the mandate presence or the periodicity (ADR 0014).
 * The previous values are null on the first snapshot. Identifiers and states only: no SIRET, no birth date (ADR 0010).
 */
final readonly class AccountSynced implements DomainEvent
{
    public function __construct(
        private AccountId $accountId,
        private TenantId $tenantId,
        private ?bool $previousMandatePresent,
        private bool $mandatePresent,
        private ?Periodicity $previousPeriodicity,
        private Periodicity $periodicity,
        private Instant $occurredAt,
    ) {}

    public function accountId(): AccountId
    {
        return $this->accountId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function previousMandatePresent(): ?bool
    {
        return $this->previousMandatePresent;
    }

    public function mandatePresent(): bool
    {
        return $this->mandatePresent;
    }

    public function previousPeriodicity(): ?Periodicity
    {
        return $this->previousPeriodicity;
    }

    public function periodicity(): Periodicity
    {
        return $this->periodicity;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
