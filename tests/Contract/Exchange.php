<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Contract;

/**
 * One request / response pair seen on the wire, reduced to its structure: the JSON types of the keys, never a value
 * (a body carries a birth date, an IBAN, an address) and never a query string; the dynamic segments of 071 are masked.
 */
final readonly class Exchange
{
    public const WRAPPER_ESTIMATE_OR_DECLARATION = 'OutputDeclaration';
    public const WRAPPER_PAYMENT = 'OutputTelepaiement';

    /**
     * @param array<string, mixed>|null $requestShape  key => JSON type name, or a nested shape
     * @param array<string, mixed>|null $responseShape same, for a JSON object (a list is described by its first item under "[]")
     */
    public function __construct(
        public string $method,
        public string $route,
        public int $status,
        public ?array $requestShape,
        public ?array $responseShape,
    ) {}

    public function hasResponseKey(string $key): bool
    {
        return $this->responseShape !== null && \array_key_exists($key, $this->responseShape);
    }

    /**
     * "wrapped" when the answer sits under the given wrapper key, "direct" for a bare object, "none" without a JSON object.
     */
    public function shapeUnder(string $wrapperKey): string
    {
        if ($this->responseShape === null) {
            return 'none';
        }

        return $this->hasResponseKey($wrapperKey) ? 'wrapped' : 'direct';
    }

    /**
     * JSON type of a field of the request `periode` object ("int", "string", "absent").
     */
    public function requestPeriodType(string $field): string
    {
        // @mago-expect analysis:mixed-assignment A shape is untyped: the value is narrowed on the next line.
        $period = $this->requestShape['periode'] ?? null;
        if (!\is_array($period)) {
            return 'absent';
        }

        // @mago-expect analysis:mixed-assignment A shape is untyped: the value is narrowed on the next line.
        $type = $period[$field] ?? null;

        return \is_string($type) ? $type : 'absent';
    }

    /**
     * Structure of a decoded JSON value: types instead of values.
     *
     * @return array<string, mixed>|null null unless the value is an object or a list
     */
    public static function shapeOf(mixed $decoded): ?array
    {
        if (!\is_array($decoded)) {
            return null;
        }

        if (array_is_list($decoded)) {
            return $decoded === [] ? ['[]' => 'empty'] : ['[]' => self::describe($decoded[0])];
        }

        $shape = [];
        // @mago-expect analysis:mixed-assignment Decoded JSON is untyped: describe() narrows it.
        foreach ($decoded as $key => $value) {
            $shape[(string) $key] = self::describe($value);
        }

        return $shape;
    }

    private static function describe(mixed $value): mixed
    {
        return match (true) {
            \is_array($value) => self::shapeOf($value),
            \is_int($value) => 'int',
            \is_float($value) => 'float',
            \is_string($value) => 'string',
            \is_bool($value) => 'bool',
            default => 'null',
        };
    }
}
