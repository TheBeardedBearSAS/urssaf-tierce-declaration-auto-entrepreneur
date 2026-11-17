<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;

/**
 * Whole euros, signed, within the int32 range of the URSSAF contract (ADR 0007). No arithmetic, no float, no cents.
 */
final readonly class Euros
{
    public const MIN = -2_147_483_648;
    public const MAX = 2_147_483_647;

    private function __construct(private int $amount) {}

    /**
     * @throws InvalidValue "euros.out_of_range"
     */
    public static function of(int $amount): self
    {
        if ($amount < self::MIN || $amount > self::MAX) {
            throw InvalidValue::outOfRange('euros', self::MIN, self::MAX);
        }

        return new self($amount);
    }

    public function toInt(): int
    {
        return $this->amount;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    /**
     * Equality of two optional amounts: an absent amount equals only an absent one (never `0`).
     */
    public static function same(?self $left, ?self $right): bool
    {
        if (!$left instanceof self || !$right instanceof self) {
            return $left === $right;
        }

        return $left->equals($right);
    }
}
