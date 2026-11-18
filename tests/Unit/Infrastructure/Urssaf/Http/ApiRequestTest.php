<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonObject;

/**
 * One method per case.
 *
 * @mago-expect lint:too-many-methods
 */
final class ApiRequestTest extends TestCase
{
    public function testGetCarriesAQueryAndNoBody(): void
    {
        $request = ApiRequest::get(Operation::FindAccount, '/comptes', ['siret' => '12345678901234']);

        static::assertSame(Operation::FindAccount, $request->operation());
        static::assertSame('GET', $request->method());
        static::assertSame('/comptes', $request->path());
        static::assertSame(['siret' => '12345678901234'], $request->query());
        static::assertNull($request->jsonBody());
        static::assertNull($request->subject());
    }

    public function testGetDefaultsToAnEmptyQuery(): void
    {
        static::assertSame([], ApiRequest::get(Operation::FindAccount, '/p')->query());
    }

    public function testPostEncodesAnArrayBodyOnce(): void
    {
        $request = ApiRequest::post(Operation::Declare, '/p', [], ['k' => 1, 'l' => [1, 2]]);

        static::assertSame(Operation::Declare, $request->operation());
        static::assertSame('POST', $request->method());
        static::assertSame('/p', $request->path());
        static::assertSame([], $request->query());
        static::assertSame('{"k":1,"l":[1,2]}', $request->jsonBody());
        static::assertNull($request->subject());
    }

    public function testPostEncodesAnEmptyJsonObjectAsBraces(): void
    {
        static::assertSame('{}', ApiRequest::post(Operation::Declare, '/p', [], JsonObject::of([]))->jsonBody());
    }

    public function testPostKeepsAnEmptyListAsAnArray(): void
    {
        static::assertSame('[]', ApiRequest::post(Operation::Declare, '/p', [], [])->jsonBody());
    }

    public function testPostWithoutBodyHasNoBody(): void
    {
        $request = ApiRequest::postWithoutBody(Operation::NotifyMandate, '/mandats', ['siret' => '1']);

        static::assertSame('POST', $request->method());
        static::assertSame(['siret' => '1'], $request->query());
        static::assertNull($request->jsonBody());
    }

    public function testPostDefaultsToNoQueryAndNoBody(): void
    {
        $request = ApiRequest::postWithoutBody(Operation::NotifyMandate, '/mandats');

        static::assertSame([], $request->query());
        static::assertNull($request->jsonBody());
    }

    public function testAFloatInTheBodyIsRefused(): void
    {
        try {
            ApiRequest::post(Operation::Pay, '/p', [], ['amount' => 1.5]);
            static::fail('A float must be refused.');
        } catch (InvariantViolation $exception) {
            static::assertSame('json_encoder.unsupported_value', $exception->violationCode());
        }
    }

    public function testDeleteCarriesAQueryAndNoBody(): void
    {
        $request = ApiRequest::delete(Operation::CancelMandate, '/mandats', ['siret' => '1']);

        static::assertSame(Operation::CancelMandate, $request->operation());
        static::assertSame('DELETE', $request->method());
        static::assertSame('/mandats', $request->path());
        static::assertSame(['siret' => '1'], $request->query());
        static::assertNull($request->jsonBody());
    }

    public function testDeleteDefaultsToAnEmptyQuery(): void
    {
        static::assertSame([], ApiRequest::delete(Operation::CancelMandate, '/mandats')->query());
    }

    public function testAboutReturnsACopyCarryingTheSubject(): void
    {
        $siret = Siret::fromString('12345678901234');
        $original = ApiRequest::post(Operation::Declare, '/p', ['a' => 'b'], ['k' => 1]);

        $copy = $original->about($siret);

        static::assertNotSame($original, $copy);
        static::assertNull($original->subject());
        static::assertSame($siret, $copy->subject());
        static::assertSame(Operation::Declare, $copy->operation());
        static::assertSame('POST', $copy->method());
        static::assertSame('/p', $copy->path());
        static::assertSame(['a' => 'b'], $copy->query());
        static::assertSame('{"k":1}', $copy->jsonBody());
    }

    public function testDebugInfoShowsQueryNamesOnly(): void
    {
        $request = ApiRequest::post(
            Operation::RegisterSepaMandate,
            '/sepa/mandats/12345678901234/RUM1',
            ['siret' => '12345678901234', 'dateNaissance' => '1990-01-01'],
            ['iban' => 'FR7630001007941234567890185'],
        )->about(Siret::fromString('12345678901234'));

        static::assertSame(
            [
                'operation' => '070',
                'method' => 'POST',
                'path' => '***',
                'query' => ['siret', 'dateNaissance'],
                'jsonBody' => '***',
                'subject' => '***',
            ],
            $request->__debugInfo(),
        );
        $dump = print_r($request, true);
        static::assertStringNotContainsString('12345678901234', $dump);
        static::assertStringNotContainsString('1990-01-01', $dump);
        static::assertStringNotContainsString('FR7630001007941234567890185', $dump);
    }

    public function testDebugInfoOfARequestWithoutBodyNorQueryNorSubject(): void
    {
        static::assertSame(
            ['operation' => '031', 'method' => 'DELETE', 'path' => '***', 'query' => [], 'jsonBody' => null, 'subject' => null],
            ApiRequest::delete(Operation::CancelMandate, '/mandats', [])->__debugInfo(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSensitiveParameters(): iterable
    {
        yield 'get query' => ['get', 'query'];
        yield 'post query' => ['post', 'query'];
        yield 'post body' => ['post', 'body'];
        yield 'delete query' => ['delete', 'query'];
        yield 'about siret' => ['about', 'siret'];
    }

    #[DataProvider('provideSensitiveParameters')]
    public function testPersonalDataParametersAreSensitive(string $method, string $parameter): void
    {
        $names = [];
        foreach ((new ReflectionMethod(ApiRequest::class, $method))->getParameters() as $reflected) {
            $names[$reflected->getName()] = $reflected->getAttributes(SensitiveParameter::class) !== [];
        }

        static::assertTrue($names[$parameter] ?? false, $method . '::$' . $parameter);
    }
}
