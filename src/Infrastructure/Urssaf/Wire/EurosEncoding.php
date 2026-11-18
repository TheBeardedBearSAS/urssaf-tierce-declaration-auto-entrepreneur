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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Whole euro amounts of the wire contract (ADR 0007): written as JSON integers, read from a JSON integer or an
 * integral float (`1678.0`). Any other number, an out-of-range value, a string or a boolean is refused: amounts are
 * never truncated or guessed.
 */
final class EurosEncoding
{
    public static function encode(Euros $amount): int
    {
        return $amount->toInt();
    }

    public static function encodeOptional(?Euros $amount): ?int
    {
        return $amount?->toInt();
    }

    /**
     * @throws UnexpectedUrssafResponse when the key is absent or its value is not a whole euro amount
     */
    public static function decode(Payload $payload, string $key): Euros
    {
        return self::decodeOptional($payload, $key) ?? throw UnexpectedUrssafResponse::invalidPayload(
            \sprintf('field "%s" is missing', $key),
        );
    }

    /**
     * Null when absent or null.
     *
     * @throws UnexpectedUrssafResponse when present but not a whole euro amount
     */
    public static function decodeOptional(Payload $payload, string $key): ?Euros
    {
        $number = $payload->number($key);

        if ($number === null) {
            return null;
        }

        try {
            return Euros::of(self::wholeNumber($number, $key));
        } catch (InvalidValue $exception) {
            throw UnexpectedUrssafResponse::causedBy(self::reason($key), $exception);
        }
    }

    private static function wholeNumber(int|float $number, string $key): int
    {
        if (\is_int($number)) {
            return $number;
        }

        if (fmod($number, 1.0) !== 0.0 || $number < Euros::MIN || $number > Euros::MAX) {
            throw UnexpectedUrssafResponse::invalidPayload(self::reason($key));
        }

        return (int) $number;
    }

    private static function reason(string $key): string
    {
        return \sprintf('field "%s" is not a whole euro amount', $key);
    }
}
