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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EurosEncoding;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\Payload;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

/**
 * @mago-expect lint:too-many-methods
 */
final class EurosEncodingTest extends TestCase
{
    private const NOT_WHOLE = 'Unexpected URSSAF response: field "montant" is not a whole euro amount.';

    public function testEncodeWritesAnInteger(): void
    {
        static::assertSame(1678, EurosEncoding::encode(Euros::of(1678)));
        static::assertSame(-5, EurosEncoding::encode(Euros::of(-5)));
        static::assertSame(0, EurosEncoding::encode(Euros::of(0)));
    }

    public function testEncodeOptional(): void
    {
        static::assertNull(EurosEncoding::encodeOptional(null));
        static::assertSame(0, EurosEncoding::encodeOptional(Euros::of(0)));
        static::assertSame(88, EurosEncoding::encodeOptional(Euros::of(88)));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideAcceptedAmounts(): iterable
    {
        yield 'integer' => ['{"montant":1678}', 1678];
        yield 'zero' => ['{"montant":0}', 0];
        yield 'negative integer' => ['{"montant":-5}', -5];
        yield 'integral float' => ['{"montant":1678.0}', 1678];
        yield 'negative integral float' => ['{"montant":-5.0}', -5];
        yield 'zero float' => ['{"montant":0.0}', 0];
        yield 'int32 max' => ['{"montant":2147483647}', Euros::MAX];
        yield 'int32 min' => ['{"montant":-2147483648}', Euros::MIN];
        yield 'int32 max float' => ['{"montant":2147483647.0}', Euros::MAX];
        yield 'int32 min float' => ['{"montant":-2147483648.0}', Euros::MIN];
        yield 'exponent form' => ['{"montant":1.678e3}', 1678];
    }

    #[DataProvider('provideAcceptedAmounts')]
    public function testDecodeAcceptsWholeAmounts(string $json, int $expected): void
    {
        $payload = JsonDecoder::decode($json);

        static::assertSame($expected, EurosEncoding::decode($payload, 'montant')->toInt());
        static::assertSame($expected, EurosEncoding::decodeOptional($payload, 'montant')?->toInt());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedAmounts(): iterable
    {
        $notWhole = 'is not a whole euro amount';
        $notNumber = 'is not a number';

        yield 'fraction' => ['{"montant":1678.5}', $notWhole];
        yield 'small fraction' => ['{"montant":0.1}', $notWhole];
        yield 'negative fraction' => ['{"montant":-0.5}', $notWhole];
        yield 'above int32' => ['{"montant":2147483648}', $notWhole];
        yield 'below int32' => ['{"montant":-2147483649}', $notWhole];
        yield 'above int32 float' => ['{"montant":2147483648.0}', $notWhole];
        yield 'below int32 float' => ['{"montant":-2147483649.0}', $notWhole];
        yield 'huge float' => ['{"montant":1e30}', $notWhole];
        yield 'two to the 64 float' => ['{"montant":18446744073709551616.0}', $notWhole];
        yield 'minus two to the 64 float' => ['{"montant":-18446744073709551616.0}', $notWhole];
        yield 'bigint' => ['{"montant":123456789012345678901234567890}', $notNumber];
        yield 'string' => ['{"montant":"1678"}', $notNumber];
        yield 'empty string' => ['{"montant":""}', $notNumber];
        yield 'boolean' => ['{"montant":true}', $notNumber];
        yield 'list' => ['{"montant":[1]}', $notNumber];
        yield 'object' => ['{"montant":{"a":1}}', $notNumber];
    }

    #[DataProvider('provideRefusedAmounts')]
    public function testDecodeRefusesWhatIsNotAWholeAmount(string $json, string $reason): void
    {
        $payload = JsonDecoder::decode($json);
        $message = \sprintf('Unexpected URSSAF response: field "montant" %s.', $reason);

        WireAssertions::assertUnexpected($message, static fn(): Euros => EurosEncoding::decode($payload, 'montant'));
        WireAssertions::assertUnexpected($message, static fn(): ?Euros => EurosEncoding::decodeOptional($payload, 'montant'));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideNonFiniteNumbers(): iterable
    {
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('provideNonFiniteNumbers')]
    public function testDecodeRefusesNonFiniteNumbers(float $value): void
    {
        $payload = Payload::fromDecoded(['montant' => $value]);

        WireAssertions::assertUnexpected(self::NOT_WHOLE, static fn(): Euros => EurosEncoding::decode($payload, 'montant'));
    }

    public function testAnOutOfRangeIntegerKeepsTheDomainCause(): void
    {
        $exception = WireAssertions::caught(
            static fn(): Euros => EurosEncoding::decode(JsonDecoder::decode('{"montant":2147483648}'), 'montant'),
        );

        static::assertNotNull($exception->getPrevious());
        static::assertStringNotContainsString('2147483648', $exception->getMessage());
    }

    public function testAnAbsentOrNullAmount(): void
    {
        foreach (['{}', '{"montant":null}', '{"other":1}'] as $json) {
            $payload = JsonDecoder::decode($json);

            static::assertNull(EurosEncoding::decodeOptional($payload, 'montant'));
            WireAssertions::assertUnexpected(
                'Unexpected URSSAF response: field "montant" is missing.',
                static fn(): Euros => EurosEncoding::decode($payload, 'montant'),
            );
        }
    }

    public function testAZeroAmountIsNotAbsent(): void
    {
        static::assertSame(0, EurosEncoding::decodeOptional(JsonDecoder::decode('{"montant":0}'), 'montant')?->toInt());
    }
}
