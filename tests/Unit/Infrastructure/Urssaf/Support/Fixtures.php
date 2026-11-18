<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use JsonException;
use RuntimeException;

/**
 * Official wire examples of tests/Fixtures/wire (placeholders such as "string" are kept on purpose, ADR 0001).
 *
 * @mago-expect lint:too-many-methods
 */
final class Fixtures
{
    private const ENCODING_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const PERIOD_KEYS = ['annee', 'mois', 'trimestre'];

    private function __construct() {}

    /**
     * Raw content of tests/Fixtures/wire/<name>.json.
     */
    public static function load(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/../../../../Fixtures/wire/' . $name . '.json');
        if ($content === false) {
            throw new RuntimeException('Missing fixture ' . $name);
        }

        return $content;
    }

    /**
     * Names of the fixtures whose file name starts with the prefix, sorted.
     *
     * @return list<string>
     */
    public static function names(string $prefix = ''): array
    {
        $files = glob(__DIR__ . '/../../../../Fixtures/wire/' . $prefix . '*.json');
        $names = array_map(static fn(string $file): string => basename($file, '.json'), $files === false ? [] : $files);
        sort($names);

        return $names;
    }

    /**
     * Decoded content of tests/Fixtures/wire/<name>.json.
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    public static function json(string $name): array
    {
        return self::decode(self::load($name));
    }

    /**
     * Decodes a JSON object or array document.
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    public static function decode(string $json): array
    {
        return self::arrayOf(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Member of a fixture which is itself an object or an array.
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    public static function section(string $name, string $key): array
    {
        return self::arrayOf(self::json($name)[$key] ?? null);
    }

    /**
     * Production shape (ADR 0006): the fixture object under a wrapper key, re-encoded as JSON.
     *
     * @throws JsonException
     */
    public static function wrapped(string $name, string $wrapperKey): string
    {
        return self::encode([$wrapperKey => self::json($name)]);
    }

    /**
     * The fixture with every `annee`, `mois` and `trimestre` digit string turned into an integer (contract shape),
     * re-encoded as JSON.
     *
     * @throws JsonException
     */
    public static function withIntegerPeriods(string $name): string
    {
        return self::encode(self::castPeriods(self::json($name)));
    }

    /**
     * String member of a fixture object.
     *
     * @throws JsonException
     */
    public static function string(string $name, string $key): string
    {
        return self::stringOf(self::json($name)[$key] ?? null, $name, $key);
    }

    private static function stringOf(mixed $value, string $name, string $key): string
    {
        return \is_string($value) ? $value : throw new RuntimeException(\sprintf('Fixture "%s" has no string "%s".', $name, $key));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function encode(array $data): string
    {
        $json = json_encode($data, self::ENCODING_FLAGS);

        return \is_string($json) ? $json : throw new RuntimeException('Fixture could not be encoded.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function castPeriods(array $data): array
    {
        $keys = array_keys($data);

        return array_combine($keys, array_map(self::castPeriod(...), $keys, $data));
    }

    private static function castPeriod(int|string $key, mixed $value): mixed
    {
        if (\is_array($value)) {
            return self::castPeriods($value);
        }

        $isPeriodDigits = \is_string($value) && \in_array($key, self::PERIOD_KEYS, true) && ctype_digit($value);

        return $isPeriodDigits ? (int) $value : $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOf(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            throw new RuntimeException('Not a JSON object or array');
        }

        return $decoded;
    }
}
