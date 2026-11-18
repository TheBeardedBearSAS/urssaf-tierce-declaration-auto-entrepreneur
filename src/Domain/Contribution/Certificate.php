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

use Stringable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;

/**
 * Certificate (`certificat`) of an auto-entrepreneur declaration or payment.
 *
 * {@see self::fromString()} is strict (8 digits) and serves the operator resolution; {@see self::fromResponse()} is
 * lenient and keeps what URSSAF answered, so that an accepted 050 / 060 is never lost to an unexpected certificate
 * (ADR 0006). Not personal data.
 */
final readonly class Certificate implements Stringable
{
    private const PATTERN = '/^\d{8}$/D';
    private const SUBJECT = 'certificate';

    private function __construct(private string $value) {}

    /**
     * @throws InvalidValue "certificate.invalid_format"
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidValue::invalidFormat(self::SUBJECT, '8 digits');
        }

        return new self($value);
    }

    /**
     * @throws InvalidValue "certificate.empty"
     */
    public static function fromResponse(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidValue::empty(self::SUBJECT);
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

    public function __toString(): string
    {
        return $this->value;
    }
}
