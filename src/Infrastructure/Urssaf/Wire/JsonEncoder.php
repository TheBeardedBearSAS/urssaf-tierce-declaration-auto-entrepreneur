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

use JsonException;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;

/**
 * Minimal JSON encoder for request bodies. Floats are refused on purpose: every amount of the AE contract is a whole
 * euro amount written as an integer (ADR 0007).
 *
 * A PHP list (including the empty array) is encoded as a JSON array and any other array as an object; wrap a map in
 * a {@see JsonObject} so that it is encoded as an object even when empty (ADR 0002).
 */
final class JsonEncoder
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param array<array-key, mixed>|bool|int|string|JsonObject|null $value
     *
     * @throws InvariantViolation "json_encoder.unsupported_value" for floats and objects other than JsonObject
     * @throws JsonException      for invalid UTF-8 strings
     */
    public static function encode(#[SensitiveParameter] array|bool|int|string|JsonObject|null $value): string
    {
        if ($value instanceof JsonObject) {
            return self::object($value->members());
        }

        if (!\is_array($value)) {
            return self::literal($value);
        }

        if (array_is_list($value)) {
            return '[' . implode(',', array_map(self::encodeItem(...), $value)) . ']';
        }

        return self::object($value);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function object(#[SensitiveParameter] array $value): string
    {
        return '{' . implode(',', array_map(
            static fn(int|string $key, mixed $item): string => self::literal((string) $key)
                . ':'
                . self::encodeItem($item),
            array_keys($value),
            $value,
        )) . '}';
    }

    private static function literal(#[SensitiveParameter] bool|int|string|null $value): string
    {
        return \strval(json_encode($value, self::FLAGS));
    }

    private static function encodeItem(#[SensitiveParameter] mixed $item): string
    {
        if ($item === null || \is_scalar($item) && !\is_float($item) || \is_array($item) || $item instanceof JsonObject) {
            return self::encode($item);
        }

        throw InvariantViolation::because(
            'json_encoder.unsupported_value',
            'Only null, bool, int, string, arrays and JsonObject can be encoded.',
        );
    }
}
