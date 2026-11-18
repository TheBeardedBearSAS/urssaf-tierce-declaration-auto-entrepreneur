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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\MandateQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

/**
 * Every mapper entry point that receives personal data (a birth date, an IBAN, a raw body) redacts it in a stack trace
 * (ADR 0010 item 4).
 */
final class WireMapperSensitiveParametersTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, list<string>}>
     */
    public static function provideSensitiveEntryPoints(): iterable
    {
        yield 'MandateQuery::of' => [MandateQuery::class, 'of', ['account']];
        yield 'PaymentRequestMapper::body' => [PaymentRequestMapper::class, 'body', ['order']];
        yield 'SepaMandateRequestMapper::body' => [SepaMandateRequestMapper::class, 'body', ['mandate']];
        yield 'EmptyResponse::assertEmpty' => [EmptyResponse::class, 'assertEmpty', ['body']];
        yield 'EmptyResponse::assertNoContent' => [EmptyResponse::class, 'assertNoContent', ['body']];
        yield 'EmptyResponse::isBlank' => [EmptyResponse::class, 'isBlank', ['body']];
        yield 'EmptyResponse::isEmptyObject' => [EmptyResponse::class, 'isEmptyObject', ['body']];
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
