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

/**
 * Creditor of a SEPA mandate as URSSAF answers it (`Creancier`, every field optional). Read model: no validation,
 * the response is kept as received (ADR 0006). Not personal data.
 *
 * The public constructor is the factory (more than 5 parts).
 */
final readonly class Creditor
{
    public function __construct(
        private ?string $name,
        private ?string $ics,
        private ?string $firstLine,
        private ?string $secondLine,
        private ?string $postalCode,
        private ?string $country,
        private ?string $city,
    ) {}

    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * SEPA creditor identifier.
     */
    public function ics(): ?string
    {
        return $this->ics;
    }

    public function firstLine(): ?string
    {
        return $this->firstLine;
    }

    public function secondLine(): ?string
    {
        return $this->secondLine;
    }

    public function postalCode(): ?string
    {
        return $this->postalCode;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function city(): ?string
    {
        return $this->city;
    }
}
