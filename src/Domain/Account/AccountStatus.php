<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Snapshot of the account status published by method 020 (ADR 0006): mandate presence, periodicity, affiliation date
 * ("date_etat") and ACRE exemption ("exoAccrePresent", absent in some answers).
 */
final readonly class AccountStatus
{
    private function __construct(
        private bool $mandatePresent,
        private Periodicity $periodicity,
        private ?CalendarDate $affiliationDate,
        private ?bool $acreExemption,
    ) {}

    public static function of(bool $mandatePresent, Periodicity $periodicity, ?CalendarDate $affiliationDate, ?bool $acreExemption): self
    {
        return new self($mandatePresent, $periodicity, $affiliationDate, $acreExemption);
    }

    public function mandatePresent(): bool
    {
        return $this->mandatePresent;
    }

    public function periodicity(): Periodicity
    {
        return $this->periodicity;
    }

    public function affiliationDate(): ?CalendarDate
    {
        return $this->affiliationDate;
    }

    public function acreExemption(): ?bool
    {
        return $this->acreExemption;
    }

    public function equals(self $other): bool
    {
        return $this->mandatePresent === $other->mandatePresent
            && $this->periodicity === $other->periodicity
            && $this->acreExemption === $other->acreExemption
            && $this->sameAffiliationDate($other);
    }

    /**
     * Whether the mandate presence or the periodicity changed: the trigger of the AccountSynced event (ADR 0014).
     */
    public function differsInTrackedFieldsFrom(self $other): bool
    {
        return $this->mandatePresent !== $other->mandatePresent || $this->periodicity !== $other->periodicity;
    }

    private function sameAffiliationDate(self $other): bool
    {
        if (!$this->affiliationDate instanceof CalendarDate || !$other->affiliationDate instanceof CalendarDate) {
            return $this->affiliationDate === $other->affiliationDate;
        }

        return $this->affiliationDate->equals($other->affiliationDate);
    }
}
