<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\Violation;

/**
 * Revocation of a SEPA mandate (method 071): the SIRET, the RUM and an optional effective date.
 *
 * Any date is accepted by the value object: the "today + 1" guard has an opt-out (ADR 0011 item 4), so it is checked
 * by {@see self::effectiveDateViolations()}, not by the constructor.
 */
final readonly class SepaRevocation
{
    public const EFFECTIVE_DATE_TOO_EARLY = 'sepa_revocation.effective_date_too_early';

    private function __construct(
        private Siret $siret,
        private Rum $rum,
        private ?CalendarDate $effectiveDate,
    ) {}

    public static function of(Siret $siret, Rum $rum, ?CalendarDate $effectiveDate): self
    {
        return new self($siret, $rum, $effectiveDate);
    }

    public function siret(): Siret
    {
        return $this->siret;
    }

    public function rum(): Rum
    {
        return $this->rum;
    }

    public function effectiveDate(): ?CalendarDate
    {
        return $this->effectiveDate;
    }

    /**
     * Valid when there is no effective date or when it is strictly after today.
     */
    public function effectiveDateViolations(CalendarDate $today): ValidationResult
    {
        if (!$this->effectiveDate instanceof CalendarDate || $this->effectiveDate->isAfter($today)) {
            return ValidationResult::valid();
        }

        return ValidationResult::of(Violation::error(
            'effective_date',
            self::EFFECTIVE_DATE_TOO_EARLY,
            'The effective date of a SEPA mandate revocation must be after today.',
        ));
    }
}
