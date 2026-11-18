<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

/**
 * A JSON object for {@see JsonEncoder}: always encoded as an object, `{}` when empty (a plain empty PHP array is
 * encoded as the empty list `[]`, ADR 0002).
 */
final readonly class JsonObject
{
    /**
     * @param array<string, mixed> $members
     */
    private function __construct(private array $members) {}

    /**
     * @param array<string, mixed> $members
     */
    public static function of(array $members): self
    {
        return new self($members);
    }

    /**
     * @return array<string, mixed>
     */
    public function members(): array
    {
        return $this->members;
    }
}
