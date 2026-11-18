<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Persistence\InMemory;

/**
 * Where a fictitious aggregate sits: the SIRET and the tenant it belongs to, the month of its period, the instant of
 * its creation (in minutes after 2026-11-15T10:00:00Z) and, for a payment, its reference (the record id by default).
 */
final readonly class Slot
{
    public function __construct(
        public int $siret,
        public string $tenant,
        public int $month,
        public int $minutes,
        public ?string $reference,
    ) {}

    public static function new(): self
    {
        return new self(1, 'tenant-1', 10, 0, null);
    }

    public function siret(int $siret): self
    {
        return new self($siret, $this->tenant, $this->month, $this->minutes, $this->reference);
    }

    public function tenant(string $tenant): self
    {
        return new self($this->siret, $tenant, $this->month, $this->minutes, $this->reference);
    }

    public function month(int $month): self
    {
        return new self($this->siret, $this->tenant, $month, $this->minutes, $this->reference);
    }

    public function minutes(int $minutes): self
    {
        return new self($this->siret, $this->tenant, $this->month, $minutes, $this->reference);
    }

    public function reference(string $reference): self
    {
        return new self($this->siret, $this->tenant, $this->month, $this->minutes, $reference);
    }
}
