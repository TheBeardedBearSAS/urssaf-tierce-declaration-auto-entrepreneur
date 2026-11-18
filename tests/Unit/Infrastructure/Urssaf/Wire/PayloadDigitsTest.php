<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class PayloadDigitsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'integer' => ['{"k":2019}', 2019];
        yield 'zero' => ['{"k":0}', 0];
        yield 'negative integer' => ['{"k":-3}', -3];
        yield 'digit string' => ['{"k":"2019"}', 2019];
        yield 'zero padded' => ['{"k":"03"}', 3];
        yield 'single digit' => ['{"k":"3"}', 3];
        yield 'zero string' => ['{"k":"0"}', 0];
        yield 'nine digits' => ['{"k":"999999999"}', 999_999_999];
    }

    #[DataProvider('provideAccepted')]
    public function testAccepted(string $json, int $expected): void
    {
        $payload = JsonDecoder::decode($json);

        static::assertSame($expected, $payload->intOrDigits('k'));
        static::assertSame($expected, $payload->requireIntOrDigits('k'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefused(): iterable
    {
        yield 'trailing newline' => ['{"k":"2019\n"}'];
        yield 'leading newline' => ['{"k":"\n2019"}'];
        yield 'negative string' => ['{"k":"-1"}'];
        yield 'plus sign' => ['{"k":"+1"}'];
        yield 'decimal string' => ['{"k":"1.0"}'];
        yield 'leading space' => ['{"k":" 1"}'];
        yield 'trailing space' => ['{"k":"1 "}'];
        yield 'ten digits' => ['{"k":"1234567890"}'];
        yield 'empty string' => ['{"k":""}'];
        yield 'letters' => ['{"k":"abc"}'];
        yield 'hex' => ['{"k":"0x10"}'];
        yield 'float' => ['{"k":1.0}'];
        yield 'fraction' => ['{"k":1.5}'];
        yield 'boolean true' => ['{"k":true}'];
        yield 'boolean false' => ['{"k":false}'];
        yield 'list' => ['{"k":[1]}'];
        yield 'object' => ['{"k":{"a":1}}'];
        yield 'bigint' => ['{"k":123456789012345678901234567890}'];
    }

    #[DataProvider('provideRefused')]
    public function testRefused(string $json): void
    {
        $payload = JsonDecoder::decode($json);

        WireAssertions::assertUnexpected('Unexpected URSSAF response: field "k" is not an integer.', static fn(): ?int => $payload->intOrDigits('k'));
        WireAssertions::assertUnexpected('Unexpected URSSAF response: field "k" is not an integer.', static fn(): int => $payload->requireIntOrDigits('k'));
    }

    public function testAbsentOrNull(): void
    {
        foreach (['{}', '{"k":null}', '{"other":1}'] as $json) {
            $payload = JsonDecoder::decode($json);

            static::assertNull($payload->intOrDigits('k'));
            WireAssertions::assertUnexpected('Unexpected URSSAF response: field "k" is missing.', static fn(): int => $payload->requireIntOrDigits('k'));
        }
    }

    public function testAValueIsNeverInTheMessage(): void
    {
        $payload = JsonDecoder::decode('{"k":"PERSONAL-VALUE"}');

        static::assertStringNotContainsString('PERSONAL-VALUE', WireAssertions::caught(static fn(): ?int => $payload->intOrDigits('k'))->getMessage());
    }
}
