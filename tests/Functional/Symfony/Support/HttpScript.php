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

use Http\Message\RequestMatcher\RequestMatcher;
use Http\Mock\Client;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafEndpoints;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ApiPaths;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;

/**
 * Scripted URSSAF platform behind the mocked PSR-18 client. Every route answers a success by default (the wire fixtures
 * of tests/Fixtures/wire) and can be switched to a given status or to a transport failure. Requests are counted per
 * route.
 *
 * One constant and one route per operation of the contract.
 */
final class HttpScript
{
    public const ROUTE_AUTHENTICATION = 'authentication';
    public const ROUTE_020 = '020';
    public const ROUTE_030 = '030';
    public const ROUTE_031 = '031';
    public const ROUTE_040 = '040';
    public const ROUTE_050 = '050';
    public const ROUTE_060 = '060';
    public const ROUTE_070 = '070';
    public const ROUTE_071 = '071';
    public const ROUTE_075 = '075';

    private const SAMPLE_SIRET = '73282932000074';
    private const SAMPLE_RUM = 'SIMRUM00000001';

    /** @var array<string, RouteEndpoint> */
    private array $routes;

    public function __construct(Client $client)
    {
        $this->routes = [
            self::ROUTE_AUTHENTICATION => new RouteEndpoint(200, Stack::TOKEN_RESPONSE),
            self::ROUTE_020 => new RouteEndpoint(200, self::fixture('account_response.json')),
            self::ROUTE_030 => new RouteEndpoint(200, ''),
            self::ROUTE_031 => new RouteEndpoint(200, ''),
            self::ROUTE_040 => new RouteEndpoint(200, self::fixture('estimate_response.json')),
            self::ROUTE_050 => new RouteEndpoint(200, self::fixture('declare_response.json')),
            self::ROUTE_060 => new RouteEndpoint(200, self::fixture('pay_response.json')),
            self::ROUTE_070 => new RouteEndpoint(200, self::fixture('sepa_mandate_response.json')),
            self::ROUTE_071 => new RouteEndpoint(200, ''),
            self::ROUTE_075 => new RouteEndpoint(200, self::fixture('sepa_mandates_search_response.json')),
        ];
        $matchers = [
            self::ROUTE_AUTHENTICATION => new RequestMatcher(self::path(UrssafEndpoints::TOKEN_PATH, ''), null, ['POST']),
            self::ROUTE_020 => new RequestMatcher(self::path(ApiPaths::findAccount()), null, ['GET']),
            self::ROUTE_030 => new RequestMatcher(self::path(ApiPaths::notifyMandate()), null, ['POST']),
            self::ROUTE_031 => new RequestMatcher(self::path(ApiPaths::cancelMandate()), null, ['DELETE']),
            self::ROUTE_040 => new RequestMatcher(self::path(ApiPaths::estimateContributions()), null, ['POST']),
            self::ROUTE_050 => new RequestMatcher(self::path(ApiPaths::declareTurnover()), null, ['POST']),
            self::ROUTE_060 => new RequestMatcher(self::path(ApiPaths::payContributions()), null, ['POST']),
            self::ROUTE_070 => new RequestMatcher(self::path(ApiPaths::registerSepaMandate()), null, ['POST']),
            self::ROUTE_071 => new RequestMatcher(self::path(ApiPaths::revokeSepaMandate(Siret::fromString(self::SAMPLE_SIRET), Rum::fromString(self::SAMPLE_RUM))), null, ['DELETE']),
            self::ROUTE_075 => new RequestMatcher(self::path(ApiPaths::listSepaMandates()), null, ['GET']),
        ];
        foreach ($matchers as $route => $matcher) {
            $client->on($matcher, $this->routes[$route]);
        }
    }

    /**
     * The content of a wire fixture of tests/Fixtures/wire.
     */
    public static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/../../../Fixtures/wire/' . $name);
        \assert(\is_string($content), 'Unreadable wire fixture ' . $name);

        return $content;
    }

    /**
     * The request path regular expression of a path of the API: the sample SIRET and RUM match any SIRET and RUM.
     */
    private static function path(string $path, string $base = UrssafEndpoints::API_BASE_PATH): string
    {
        $pattern = preg_quote($base . $path, '/');

        return str_replace([preg_quote(self::SAMPLE_SIRET, '/'), preg_quote(self::SAMPLE_RUM, '/')], ['[^/]+', '[^/]+'], $pattern) . '$';
    }

    public function calls(string $route): int
    {
        return $this->routes[$route]->calls();
    }

    /**
     * @param array<string, string> $headers
     */
    public function respond(string $route, int $status, string $body, array $headers = []): void
    {
        $this->routes[$route]->respond($status, $body, $headers);
    }

    public function respondWithFixture(string $route, int $status, string $fixture): void
    {
        $this->routes[$route]->respond($status, self::fixture($fixture));
    }

    public function failTransport(string $route): void
    {
        $this->routes[$route]->failTransport();
    }
}
