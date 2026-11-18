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
 * BIC of a bank: 1 to 11 characters, kept verbatim. Personal data (ADR 0010): masked when dumped, no `__toString`.
 */
final readonly class Bic
{
    public const MAX_LENGTH = 11;
    private const SUBJECT = 'bic';

    private function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {}

    /**
     * @throws InvalidValue "bic.empty" or "bic.too_long"
     */
    public static function fromString(#[SensitiveParameter] string $value): self
    {
        if (trim($value) === '') {
            throw InvalidValue::empty(self::SUBJECT);
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidValue::tooLong(self::SUBJECT, self::MAX_LENGTH);
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }
}
