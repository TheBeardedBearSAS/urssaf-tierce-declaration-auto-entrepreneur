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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;

/**
 * SIRET of the auto-entrepreneur: exactly 14 digits, kept verbatim (no trimming, no checksum, ADR 0008).
 *
 * Personal data (ADR 0010): never in a message, a log nor an event, and masked when dumped. No `__toString`: reading
 * the value is always explicit through {@see self::toString()}.
 */
final readonly class Siret
{
    private const PATTERN = '/^\d{14}$/D';
    private const VISIBLE_DIGITS = 4;
    private const MASK_LENGTH = 10;

    private function __construct(private string $value) {}

    /**
     * @throws InvalidValue "siret.invalid_format"
     */
    public static function fromString(#[SensitiveParameter] string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidValue::invalidFormat('siret', '14 digits');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Ten stars followed by the last four digits.
     *
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => str_repeat('*', self::MASK_LENGTH) . substr($this->value, -self::VISIBLE_DIGITS)];
    }
}
