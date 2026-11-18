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
 * The amounts URSSAF answers on methods 040 and 050 (`MontantsAe`, union of the prd and sb variants, ADR 0006).
 *
 * Exposed raw, signed, never recomputed: there is deliberately no "amount due" helper (ADR 0007).
 */
final readonly class ContributionAmounts
{
    private function __construct(
        private Euros $total,
        private ?Euros $sales,
        private ?Euros $furnishedRental,
        private ?Euros $commercialServices,
        private ?Euros $otherServices,
        private ?Euros $cipav,
        private ?Euros $vocationalTraining,
        private LiberatoryContributions $liberatory,
        private ChamberFees $chamberFees,
        private ?Euros $acreRegularisation,
        private ?Euros $deduction,
    ) {}

    /**
     * @mago-expect lint:excessive-parameter-list One parameter per field of the URSSAF `MontantsAe` object.
     */
    public static function of(
        Euros $total,
        ?Euros $sales,
        ?Euros $furnishedRental,
        ?Euros $commercialServices,
        ?Euros $otherServices,
        ?Euros $cipav,
        ?Euros $vocationalTraining,
        LiberatoryContributions $liberatory,
        ChamberFees $chamberFees,
        ?Euros $acreRegularisation,
        ?Euros $deduction,
    ): self {
        return new self(
            $total,
            $sales,
            $furnishedRental,
            $commercialServices,
            $otherServices,
            $cipav,
            $vocationalTraining,
            $liberatory,
            $chamberFees,
            $acreRegularisation,
            $deduction,
        );
    }

    /**
     * Equality of two optional amounts: an absent one equals only an absent one.
     */
    public static function same(?self $left, ?self $right): bool
    {
        if (!$left instanceof self || !$right instanceof self) {
            return $left === $right;
        }

        return $left->equals($right);
    }

    /**
     * `montantTotal`.
     */
    public function total(): Euros
    {
        return $this->total;
    }

    /**
     * `montantVente`.
     */
    public function sales(): ?Euros
    {
        return $this->sales;
    }

    /**
     * `montantLocation`.
     */
    public function furnishedRental(): ?Euros
    {
        return $this->furnishedRental;
    }

    /**
     * `montantPrestationService`.
     */
    public function commercialServices(): ?Euros
    {
        return $this->commercialServices;
    }

    /**
     * `montantPrestationAutres`.
     */
    public function otherServices(): ?Euros
    {
        return $this->otherServices;
    }

    /**
     * `montantCipav`.
     */
    public function cipav(): ?Euros
    {
        return $this->cipav;
    }

    /**
     * `montantFormationProfessionnelle`.
     */
    public function vocationalTraining(): ?Euros
    {
        return $this->vocationalTraining;
    }

    public function liberatory(): LiberatoryContributions
    {
        return $this->liberatory;
    }

    public function chamberFees(): ChamberFees
    {
        return $this->chamberFees;
    }

    /**
     * `montantRegulAcre`.
     */
    public function acreRegularisation(): ?Euros
    {
        return $this->acreRegularisation;
    }

    /**
     * `montantDeduction`.
     */
    public function deduction(): ?Euros
    {
        return $this->deduction;
    }

    public function equals(self $other): bool
    {
        return $this->total->equals($other->total)
            && Euros::same($this->sales, $other->sales)
            && Euros::same($this->furnishedRental, $other->furnishedRental)
            && Euros::same($this->commercialServices, $other->commercialServices)
            && Euros::same($this->otherServices, $other->otherServices)
            && Euros::same($this->cipav, $other->cipav)
            && Euros::same($this->vocationalTraining, $other->vocationalTraining)
            && $this->liberatory->equals($other->liberatory)
            && $this->chamberFees->equals($other->chamberFees)
            && Euros::same($this->acreRegularisation, $other->acreRegularisation)
            && Euros::same($this->deduction, $other->deduction);
    }
}
