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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EmptyResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireAssertions;
use Throwable;

final class EmptyResponseTest extends TestCase
{
    private const REFUSED = 'Unexpected URSSAF response: expected an empty body.';

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBlankBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['  '];
        yield 'newline' => ["\n"];
        yield 'crlf and tab' => ["\r\n\t"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonEmptyBodies(): iterable
    {
        yield 'object with a member' => ['{"a":1}'];
        yield 'empty list' => ['[]'];
        yield 'list with an object' => ['[{}]'];
        yield 'invalid JSON' => ['x'];
        yield 'truncated object' => ['{'];
        yield 'scalar' => ['1'];
        yield 'null literal' => ['null'];
        yield 'string literal' => ['"{}"'];
    }

    #[DataProvider('provideBlankBodies')]
    public function testAssertEmptyAcceptsABlankBody(string $body): void
    {
        EmptyResponse::assertEmpty($body);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEmptyObjects(): iterable
    {
        yield 'compact' => ['{}'];
        yield 'spaced' => ['{ }'];
        yield 'padded' => [" \n{}\n"];
    }

    #[DataProvider('provideEmptyObjects')]
    public function testAssertEmptyAcceptsAnEmptyObject(string $body): void
    {
        EmptyResponse::assertEmpty($body);

        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('provideNonEmptyBodies')]
    public function testAssertEmptyRefusesAnythingElse(string $body): void
    {
        WireAssertions::assertUnexpected(self::REFUSED, static fn() => EmptyResponse::assertEmpty($body));
    }

    #[DataProvider('provideBlankBodies')]
    public function testAssertNoContentAcceptsABlankBody(string $body): void
    {
        EmptyResponse::assertNoContent($body);

        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('provideNonEmptyBodies')]
    public function testAssertNoContentRefusesAnythingElse(string $body): void
    {
        WireAssertions::assertUnexpected(self::REFUSED, static fn() => EmptyResponse::assertNoContent($body));
    }

    public function testAssertNoContentRefusesAnEmptyObject(): void
    {
        WireAssertions::assertUnexpected(self::REFUSED, static fn() => EmptyResponse::assertNoContent('{}'));
    }

    public function testTheRefusalNeverHoldsTheBody(): void
    {
        $exception = WireAssertions::caught(static fn() => EmptyResponse::assertEmpty('{"secret":"S3CR3T"}'));

        static::assertStringNotContainsString('S3CR3T', $exception->getMessage());
        static::assertNotInstanceOf(Throwable::class, $exception->getPrevious());
    }
}
