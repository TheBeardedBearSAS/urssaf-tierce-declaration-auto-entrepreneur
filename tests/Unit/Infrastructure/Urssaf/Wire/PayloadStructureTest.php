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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\Payload;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;

final class PayloadStructureTest extends TestCase
{
    /**
     * Body and the shape it has: `object`, `list` or `none`.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideTopLevelDocuments(): iterable
    {
        yield 'blank' => ['', 'none'];
        yield 'spaces' => ['  ', 'none'];
        yield 'empty object' => ['{}', 'object'];
        yield 'empty list' => ['[]', 'list'];
        yield 'list of an empty object' => ['[{}]', 'list'];
        yield 'list of scalars' => ['[1,2]', 'list'];
        yield 'object' => ['{"a":1}', 'object'];
        yield 'object with numeric keys' => ['{"0":"a","1":"b"}', 'list'];
    }

    #[DataProvider('provideTopLevelDocuments')]
    public function testTheTopLevelShape(string $body, string $shape): void
    {
        $payload = JsonDecoder::decode($body);

        static::assertSame($shape === 'object', self::acceptsObject($payload));
        static::assertSame($shape === 'list', self::acceptsList($payload));
        if ($shape !== 'object') {
            WireAssertions::assertUnexpected('Unexpected URSSAF response: body is not a JSON object.', static function () use ($payload): void {
                $payload->requireJsonObject();
            });
        }
        if ($shape !== 'list') {
            WireAssertions::assertUnexpected('Unexpected URSSAF response: body is not a JSON array.', static function () use ($payload): void {
                $payload->requireJsonList();
            });
        }
    }

    private static function acceptsObject(Payload $payload): bool
    {
        try {
            $payload->requireJsonObject();
        } catch (UnexpectedUrssafResponse) {
            return false;
        }

        return true;
    }

    private static function acceptsList(Payload $payload): bool
    {
        try {
            $payload->requireJsonList();
        } catch (UnexpectedUrssafResponse) {
            return false;
        }

        return true;
    }

    public function testTheConstructorsHaveTheirOwnShape(): void
    {
        static::assertFalse(self::acceptsObject(Payload::empty()));
        static::assertFalse(self::acceptsList(Payload::empty()));
        static::assertTrue(self::acceptsObject(Payload::emptyObject()));
        static::assertFalse(self::acceptsList(Payload::emptyObject()));
        static::assertTrue(self::acceptsList(Payload::fromDecoded([])));
        static::assertFalse(self::acceptsObject(Payload::fromDecoded([])));
        static::assertTrue(self::acceptsObject(Payload::fromDecoded(['a' => 1])));
        static::assertFalse(self::acceptsList(Payload::fromDecoded(['a' => 1])));
        static::assertTrue(self::acceptsList(Payload::fromDecoded([1])));
        static::assertFalse(self::acceptsObject(Payload::fromDecoded([1])));
    }

    public function testANestedEmptyListIsAnObject(): void
    {
        $payload = JsonDecoder::decode('{"o":[],"l":[1]}');

        static::assertTrue($payload->requireObject('o')->isEmpty());
        static::assertTrue($payload->object('o')?->isEmpty());
        WireAssertions::assertUnexpected(
            'Unexpected URSSAF response: field "l" is missing or not an object.',
            static fn(): Payload => $payload->requireObject('l'),
        );
    }

    public function testANestedObjectIsNeverAList(): void
    {
        $payload = JsonDecoder::decode('{"o":{"a":1}}');

        static::assertTrue(self::acceptsObject($payload->requireObject('o')));
        static::assertFalse(self::acceptsList($payload->requireObject('o')));
    }

    public function testEntriesOfAList(): void
    {
        $list = JsonDecoder::decode('[{"a":"x"},{"a":"y"}]');

        $list->requireJsonList();
        static::assertSame(['x', 'y'], array_map(static fn(Payload $entry): ?string => $entry->string('a'), $list->entries()));
        static::assertSame([], JsonDecoder::decode('[]')->entries());
    }

    public function testEntriesRefuseWhatIsNotAListOfObjects(): void
    {
        WireAssertions::assertUnexpected('Unexpected URSSAF response: body is not a JSON array.', static fn(): array => JsonDecoder::decode('{"a":{}}')->entries());
        WireAssertions::assertUnexpected('Unexpected URSSAF response: body is not a JSON array of objects.', static fn(): array => JsonDecoder::decode('[{},1]')->entries());
        WireAssertions::assertUnexpected('Unexpected URSSAF response: body is not a JSON array of objects.', static fn(): array => JsonDecoder::decode('[null]')->entries());
    }

    public function testLenientAccessorsNeverThrow(): void
    {
        $payload = JsonDecoder::decode('{"a":[{"x":"1"},3,{"x":"2"}],"b":{"k":{"x":"3"}},"c":null}');

        static::assertSame(['1', '2'], array_map(static fn(Payload $entry): ?string => $entry->string('x'), $payload->lenientItems('a')));
        static::assertSame([], $payload->lenientItems('b'));
        static::assertSame([], $payload->lenientItems('c'));
        static::assertSame([], $payload->lenientEntries());
        static::assertSame(['1'], array_map(static fn(Payload $entry): ?string => $entry->string('x'), JsonDecoder::decode('[{"x":"1"},"y"]')->lenientEntries()));
    }
}
