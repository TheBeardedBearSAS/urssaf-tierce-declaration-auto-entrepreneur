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
use ReflectionClassConstant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\Payload;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseShape;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class ResponseEnvelopeTest extends TestCase
{
    public function testTheWrapperKeys(): void
    {
        $keys = [];
        foreach (['ESTIMATE_OR_DECLARATION', 'PAYMENT'] as $name) {
            $keys[] = (new ReflectionClassConstant(ResponseEnvelope::class, $name))->getValue();
        }

        static::assertSame(['OutputDeclaration', 'OutputTelepaiement'], $keys);
    }

    public function testTheShapesAreNamed(): void
    {
        static::assertSame('wrapped', ResponseShape::Wrapped->value);
        static::assertSame('direct', ResponseShape::Direct->value);
        static::assertCount(2, ResponseShape::cases());
    }

    public function testAnAbsentKeyIsADirectAnswer(): void
    {
        $payload = JsonDecoder::decode('{"montants":{"montantTotal":1}}');

        static::assertSame(ResponseShape::Direct, ResponseEnvelope::shapeOf($payload, 'OutputDeclaration'));
        static::assertSame($payload, ResponseEnvelope::unwrap($payload, 'OutputDeclaration'));
    }

    public function testAnotherWrapperKeyIsNotThisWrapper(): void
    {
        $payload = JsonDecoder::decode('{"OutputTelepaiement":{"certificat":"1"}}');

        static::assertSame(ResponseShape::Direct, ResponseEnvelope::shapeOf($payload, 'OutputDeclaration'));
        static::assertSame(ResponseShape::Wrapped, ResponseEnvelope::shapeOf($payload, 'OutputTelepaiement'));
    }

    public function testAPresentKeyUnwrapsTheObject(): void
    {
        $payload = JsonDecoder::decode('{"OutputDeclaration":{"certificat":"56335826"}}');

        static::assertSame(ResponseShape::Wrapped, ResponseEnvelope::shapeOf($payload, 'OutputDeclaration'));
        static::assertSame('56335826', ResponseEnvelope::unwrap($payload, 'OutputDeclaration')->requireString('certificat'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidWrappers(): iterable
    {
        yield 'null' => ['{"OutputDeclaration":null}'];
        yield 'empty object' => ['{"OutputDeclaration":{}}'];
        yield 'empty list' => ['{"OutputDeclaration":[]}'];
        yield 'filled list' => ['{"OutputDeclaration":[{"a":1}]}'];
        yield 'string' => ['{"OutputDeclaration":"x"}'];
        yield 'integer' => ['{"OutputDeclaration":1}'];
        yield 'zero' => ['{"OutputDeclaration":0}'];
        yield 'boolean' => ['{"OutputDeclaration":false}'];
    }

    #[DataProvider('provideInvalidWrappers')]
    public function testAPresentKeyMustHoldANonEmptyObject(string $json): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "OutputDeclaration" is not a non-empty object.',
            static fn(): Payload => ResponseEnvelope::unwrap(JsonDecoder::decode($json), 'OutputDeclaration'),
        );
    }

    public function testTheRefusalNamesTheRequestedKey(): void
    {
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "OutputTelepaiement" is not a non-empty object.',
            static fn(): Payload => ResponseEnvelope::unwrap(JsonDecoder::decode('{"OutputTelepaiement":null}'), 'OutputTelepaiement'),
        );
    }
}
