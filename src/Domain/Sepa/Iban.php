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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;

/**
 * IBAN of a bank account, kept verbatim: no normalisation, no pattern, no checksum (ADR 0011 item 5), URSSAF
 * publishes no rule for method 060 and a 34 characters bound for method 070.
 *
 * Personal data (ADR 0010): masked when dumped and by {@see self::masked()}. No `__toString`.
 */
final readonly class Iban
{
    public const SEPA_MANDATE_MAX_LENGTH = 34;
    private const SUBJECT = 'iban';
    private const MASK = '***';
    private const VISIBLE = 4;

    private function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {}

    /**
     * IBAN of a payment order (060): only a blank value is refused.
     *
     * @throws InvalidValue "iban.empty"
     */
    public static function fromString(#[SensitiveParameter] string $value): self
    {
        if (trim($value) === '') {
            throw InvalidValue::empty(self::SUBJECT);
        }

        return new self($value);
    }

    /**
     * IBAN of a new SEPA mandate (070): at most 34 characters.
     *
     * @throws InvalidValue "iban.empty" or "iban.too_long"
     */
    public static function forSepaMandate(#[SensitiveParameter] string $value): self
    {
        $iban = self::fromString($value);
        if ($iban->length() > self::SEPA_MANDATE_MAX_LENGTH) {
            throw InvalidValue::tooLong(self::SUBJECT, self::SEPA_MANDATE_MAX_LENGTH);
        }

        return $iban;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function length(): int
    {
        return mb_strlen($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * First four and last four characters ("FR76…0185"), "***" when the value is too short to hide anything.
     */
    public function masked(): string
    {
        if ($this->length() <= 2 * self::VISIBLE) {
            return self::MASK;
        }

        return mb_substr($this->value, 0, self::VISIBLE) . '…' . mb_substr($this->value, -self::VISIBLE);
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => $this->masked()];
    }
}
