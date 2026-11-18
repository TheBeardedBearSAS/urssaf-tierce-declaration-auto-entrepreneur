<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support;

use Http\Mock\Client;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafEndpoints;

/**
 * The scripted platform answers each of the nine operations and the token route, counts the calls per route and can be
 * switched to a status or to a transport failure.
 */
final class HttpScriptTest extends TestCase
{
    private const BASE = 'https://api.example.test' . UrssafEndpoints::API_BASE_PATH;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield HttpScript::ROUTE_AUTHENTICATION => [HttpScript::ROUTE_AUTHENTICATION, 'POST', 'https://api.example.test' . UrssafEndpoints::TOKEN_PATH];
        yield HttpScript::ROUTE_020 => [HttpScript::ROUTE_020, 'GET', self::BASE . '/comptes'];
        yield HttpScript::ROUTE_030 => [HttpScript::ROUTE_030, 'POST', self::BASE . '/mandats'];
        yield HttpScript::ROUTE_031 => [HttpScript::ROUTE_031, 'DELETE', self::BASE . '/mandats'];
        yield HttpScript::ROUTE_040 => [HttpScript::ROUTE_040, 'POST', self::BASE . '/estimer'];
        yield HttpScript::ROUTE_050 => [HttpScript::ROUTE_050, 'POST', self::BASE . '/declarer'];
        yield HttpScript::ROUTE_060 => [HttpScript::ROUTE_060, 'POST', self::BASE . '/payer'];
        yield HttpScript::ROUTE_070 => [HttpScript::ROUTE_070, 'POST', self::BASE . '/sepa/mandats'];
        yield HttpScript::ROUTE_071 => [HttpScript::ROUTE_071, 'DELETE', self::BASE . '/sepa/mandats/73282932000074/SIMRUM00000001'];
        yield HttpScript::ROUTE_075 => [HttpScript::ROUTE_075, 'GET', self::BASE . '/sepa/mandats/rechercher'];
    }

    #[DataProvider('routes')]
    public function testEachRouteAnswersAndCountsItsCallsOnly(string $route, string $method, string $uri): void
    {
        [$client, $script] = self::platform();

        $response = $client->sendRequest(new Request($method, $uri));

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(1, $script->calls($route));
        foreach (array_keys(iterator_to_array(self::routes(), true)) as $other) {
            static::assertSame($other === $route ? 1 : 0, $script->calls($other), $other);
        }
    }

    public function testARouteAnswersAGivenStatusAndFixture(): void
    {
        [$client, $script] = self::platform();
        $script->respond(HttpScript::ROUTE_050, 400, '{"codeErreur":"ER_API_050_0070"}', ['Retry-After' => '3']);

        $response = $client->sendRequest(new Request('POST', self::BASE . '/declarer'));
        static::assertSame(400, $response->getStatusCode());
        static::assertSame('3', $response->getHeaderLine('Retry-After'));
        static::assertSame('{"codeErreur":"ER_API_050_0070"}', (string) $response->getBody());

        $script->respondWithFixture(HttpScript::ROUTE_050, 200, 'declare_response.json');
        $response = $client->sendRequest(new Request('POST', self::BASE . '/declarer'));
        static::assertSame(200, $response->getStatusCode());
        static::assertSame(HttpScript::fixture('declare_response.json'), (string) $response->getBody());
    }

    public function testARouteCanFailLikeABrokenNetwork(): void
    {
        [$client, $script] = self::platform();
        $script->failTransport(HttpScript::ROUTE_020);

        $this->expectException(ClientExceptionInterface::class);
        $this->expectExceptionMessage('connection refused');

        try {
            $client->sendRequest(new Request('GET', self::BASE . '/comptes'));
        } finally {
            static::assertSame(1, $script->calls(HttpScript::ROUTE_020));
        }
    }

    /**
     * @return array{Client, HttpScript}
     */
    private static function platform(): array
    {
        $client = new Client();

        return [$client, new HttpScript($client)];
    }
}
