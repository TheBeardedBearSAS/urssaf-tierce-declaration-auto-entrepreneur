<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Validation\ValidationResult;

/**
 * Turnover of a period as sent to methods 040 and 050 (`CaAe`): five optional non-negative lines (ADR 0007).
 *
 * An absent line is not sent; `0` is sent as `0`. All lines absent is allowed (ADR 0011 item 5).
 */
final readonly class Turnover
{
    private function __construct(
        private ?Euros $sales,
        private ?Euros $furnishedRental,
        private ?Euros $commercialServices,
        private ?Euros $otherServices,
        private ?Euros $cipavReceipts,
    ) {}

    /**
     * @throws ValidationFailed "turnover.negative" for every negative line
     */
    public static function of(
        ?Euros $sales,
        ?Euros $furnishedRental,
        ?Euros $commercialServices,
        ?Euros $otherServices,
        ?Euros $cipavReceipts,
    ): self {
        $result = ValidationResult::valid();
        foreach ([
            'sales' => $sales,
            'furnishedRental' => $furnishedRental,
            'commercialServices' => $commercialServices,
            'otherServices' => $otherServices,
            'cipavReceipts' => $cipavReceipts,
        ] as $path => $line) {
            if ($line?->isNegative() !== true) {
                continue;
            }

            $result = $result->with(InvalidValue::negative('turnover')->toViolation($path));
        }
        $result->throwIfInvalid();

        return new self($sales, $furnishedRental, $commercialServices, $otherServices, $cipavReceipts);
    }

    /**
     * `caVente`.
     */
    public function sales(): ?Euros
    {
        return $this->sales;
    }

    /**
     * `caLocation`.
     */
    public function furnishedRental(): ?Euros
    {
        return $this->furnishedRental;
    }

    /**
     * `caPrestationService`.
     */
    public function commercialServices(): ?Euros
    {
        return $this->commercialServices;
    }

    /**
     * `caPrestationAutres`.
     */
    public function otherServices(): ?Euros
    {
        return $this->otherServices;
    }

    /**
     * `recettesCipav`.
     */
    public function cipavReceipts(): ?Euros
    {
        return $this->cipavReceipts;
    }

    public function equals(self $other): bool
    {
        return Euros::same($this->sales, $other->sales)
            && Euros::same($this->furnishedRental, $other->furnishedRental)
            && Euros::same($this->commercialServices, $other->commercialServices)
            && Euros::same($this->otherServices, $other->otherServices)
            && Euros::same($this->cipavReceipts, $other->cipavReceipts);
    }
}
