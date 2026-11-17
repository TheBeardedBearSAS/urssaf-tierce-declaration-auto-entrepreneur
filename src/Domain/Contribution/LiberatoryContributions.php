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
 * The five liberatory-payment lines of a `MontantsAe` (`*VL`, ADR 0006). Signed values, each optional.
 */
final readonly class LiberatoryContributions
{
    private function __construct(
        private ?Euros $sales,
        private ?Euros $furnishedRental,
        private ?Euros $commercialServices,
        private ?Euros $otherServices,
        private ?Euros $cipav,
    ) {}

    public static function of(
        ?Euros $sales,
        ?Euros $furnishedRental,
        ?Euros $commercialServices,
        ?Euros $otherServices,
        ?Euros $cipav,
    ): self {
        return new self($sales, $furnishedRental, $commercialServices, $otherServices, $cipav);
    }

    public static function none(): self
    {
        return new self(null, null, null, null, null);
    }

    /**
     * `montantVenteVL`.
     */
    public function sales(): ?Euros
    {
        return $this->sales;
    }

    /**
     * `montantLocationVL`.
     */
    public function furnishedRental(): ?Euros
    {
        return $this->furnishedRental;
    }

    /**
     * `montantPrestationServiceVL`.
     */
    public function commercialServices(): ?Euros
    {
        return $this->commercialServices;
    }

    /**
     * `montantPrestationAutresVL`.
     */
    public function otherServices(): ?Euros
    {
        return $this->otherServices;
    }

    /**
     * `montantCipavVL`.
     */
    public function cipav(): ?Euros
    {
        return $this->cipav;
    }

    public function equals(self $other): bool
    {
        return Euros::same($this->sales, $other->sales)
            && Euros::same($this->furnishedRental, $other->furnishedRental)
            && Euros::same($this->commercialServices, $other->commercialServices)
            && Euros::same($this->otherServices, $other->otherServices)
            && Euros::same($this->cipav, $other->cipav);
    }
}
