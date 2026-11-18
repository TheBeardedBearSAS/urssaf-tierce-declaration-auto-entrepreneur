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

use PHPUnit\Framework\Assert;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonObject;

/**
 * Compares an encoded request body with an official request fixture: same JSON, same key order at every level.
 * Request fixtures carry string periods (PDF) and the contract wants integers, so the expected body is the fixture with
 * integer periods (ADR 0006).
 */
final class WireBodyAssertions
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed>|JsonObject $body
     * @param list<string>                       $ignoredKeys top-level fixture keys the mapper must not write
     */
    public static function assertMatchesFixture(string $fixture, array|JsonObject $body, array $ignoredKeys = []): void
    {
        $expected = Fixtures::decode(Fixtures::withIntegerPeriods($fixture));
        foreach ($ignoredKeys as $key) {
            unset($expected[$key]);
        }

        $encoded = JsonEncoder::encode($body);

        Assert::assertJsonStringEqualsJsonString(
            json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $encoded,
        );
        Assert::assertSame(self::keyTree($expected), self::keyTree(Fixtures::decode($encoded)));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, array<array-key, mixed>|null>
     */
    private static function keyTree(array $data): array
    {
        return array_map(static fn(mixed $value): ?array => \is_array($value) ? self::keyTree($value) : null, $data);
    }
}
