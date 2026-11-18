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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DateEncoding;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorPayloadParser;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\Payload;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\QueryEncoding;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * Every wire entry point that receives personal data (a raw body, a decoded document, a request array) redacts it in a
 * stack trace (ADR 0010 item 4).
 */
final class SensitiveParametersTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, list<string>}>
     */
    public static function provideSensitiveEntryPoints(): iterable
    {
        yield 'JsonDecoder::decode' => [JsonDecoder::class, 'decode', ['json']];
        yield 'JsonDecoder::tryDecode' => [JsonDecoder::class, 'tryDecode', ['json']];
        yield 'JsonEncoder::encode' => [JsonEncoder::class, 'encode', ['value']];
        yield 'JsonEncoder::object' => [JsonEncoder::class, 'object', ['value']];
        yield 'JsonEncoder::literal' => [JsonEncoder::class, 'literal', ['value']];
        yield 'JsonEncoder::encodeItem' => [JsonEncoder::class, 'encodeItem', ['item']];
        yield 'Payload::__construct' => [Payload::class, '__construct', ['data']];
        yield 'Payload::fromDecoded' => [Payload::class, 'fromDecoded', ['decoded']];
        yield 'QueryEncoding::build' => [QueryEncoding::class, 'build', ['parameters']];
        yield 'ErrorPayloadParser::parseBody' => [ErrorPayloadParser::class, 'parseBody', ['body']];
        yield 'DateEncoding::decodeIsoDate' => [DateEncoding::class, 'decodeIsoDate', ['value']];
        yield 'DateEncoding::decodeFrenchDate' => [DateEncoding::class, 'decodeFrenchDate', ['value']];
    }

    /**
     * @param class-string $class
     * @param list<string> $expected
     */
    #[DataProvider('provideSensitiveEntryPoints')]
    public function testTheEntryPointRedactsItsPersonalData(string $class, string $method, array $expected): void
    {
        DomainAssertions::assertSensitiveParameters($class, $method, $expected);
    }
}
