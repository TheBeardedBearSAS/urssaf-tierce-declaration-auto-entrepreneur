<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception;

/**
 * Thrown when a value object cannot be built from the given input.
 *
 * The "subject" is the snake_case name of the value (e.g. "tenant_id", "amount"); the violation code is
 * "<subject>.<reason>" (e.g. "tenant_id.too_long").
 */
final class InvalidValue extends DomainException
{
    /**
     * @param array<string, scalar> $params
     */
    public static function because(string $violationCode, string $message, array $params = []): self
    {
        return new self($message, $violationCode, $params);
    }

    public static function empty(string $subject): self
    {
        return new self(\sprintf('Invalid %s: the value must not be empty.', $subject), $subject . '.empty', []);
    }

    public static function tooLong(string $subject, int $maxLength): self
    {
        return new self(
            \sprintf('Invalid %s: the value must not exceed %d characters.', $subject, $maxLength),
            $subject . '.too_long',
            ['max_length' => $maxLength],
        );
    }

    public static function invalidFormat(string $subject, string $expectedFormat): self
    {
        return new self(
            \sprintf('Invalid %s: the value does not match the expected format (%s).', $subject, $expectedFormat),
            $subject . '.invalid_format',
            ['expected_format' => $expectedFormat],
        );
    }

    public static function negative(string $subject): self
    {
        return new self(\sprintf('Invalid %s: the value must not be negative.', $subject), $subject . '.negative', []);
    }

    /**
     * @param int|string $min lower bound as given (an integer: counts and whole euros, ADR 0007)
     * @param int|string $max upper bound, same convention
     */
    public static function outOfRange(string $subject, int|string $min, int|string $max): self
    {
        return new self(
            \sprintf('Invalid %s: the value must be between %s and %s.', $subject, $min, $max),
            $subject . '.out_of_range',
            ['min' => $min, 'max' => $max],
        );
    }
}
