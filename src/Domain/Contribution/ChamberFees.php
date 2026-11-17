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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;

/**
 * The four chamber taxes of a `MontantsAe` (CMA and CCI, sales and services), each optional.
 */
final readonly class ChamberFees
{
    private function __construct(
        private ?Euros $cmaSales,
        private ?Euros $cmaServices,
        private ?Euros $cciSales,
        private ?Euros $cciServices,
    ) {}

    public static function of(?Euros $cmaSales, ?Euros $cmaServices, ?Euros $cciSales, ?Euros $cciServices): self
    {
        return new self($cmaSales, $cmaServices, $cciSales, $cciServices);
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * `taxeCMAVente`.
     */
    public function cmaSales(): ?Euros
    {
        return $this->cmaSales;
    }

    /**
     * `taxeCMAService`.
     */
    public function cmaServices(): ?Euros
    {
        return $this->cmaServices;
    }

    /**
     * `taxeCCIVente`.
     */
    public function cciSales(): ?Euros
    {
        return $this->cciSales;
    }

    /**
     * `taxeCCIService`.
     */
    public function cciServices(): ?Euros
    {
        return $this->cciServices;
    }

    public function equals(self $other): bool
    {
        return Euros::same($this->cmaSales, $other->cmaSales)
            && Euros::same($this->cmaServices, $other->cmaServices)
            && Euros::same($this->cciSales, $other->cciSales)
            && Euros::same($this->cciServices, $other->cciServices);
    }
}
