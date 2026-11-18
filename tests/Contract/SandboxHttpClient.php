<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Contract;

use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafEndpoints;

/**
 * PSR-18 decorator that remembers the structure of every exchange with the API (see {@see Exchange}), so a contract
 * test can tell which response shape the sandbox really used. The token exchange is not kept.
 */
final class SandboxHttpClient implements ClientInterface
{
    /** Method 071 puts the SIRET and the RUM in its path: personal data, masked. */
    public const REVOKE_ROUTE = UrssafEndpoints::API_BASE_PATH . '/sepa/mandats/{siret}/{rum}';

    /** @var list<Exchange> */
    private array $exchanges = [];

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly Psr17Factory $factory,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        $body = (string) $response->getBody();
        $path = $request->getUri()->getPath();

        if (str_starts_with($path, UrssafEndpoints::API_BASE_PATH)) {
            $this->exchanges[] = new Exchange(
                $request->getMethod(),
                (string) preg_replace('#(/sepa/mandats)/[^/]+/[^/]+$#D', '$1/{siret}/{rum}', $path),
                $response->getStatusCode(),
                Exchange::shapeOf(self::decode((string) $request->getBody())),
                Exchange::shapeOf(self::decode($body)),
            );
        }

        return $response->withBody($this->factory->createStream($body));
    }

    /**
     * The last exchange of a method on a path relative to the API base path (e.g. "/comptes", or {@see self::REVOKE_ROUTE}).
     */
    public function last(string $method, string $path): ?Exchange
    {
        $route = str_starts_with($path, UrssafEndpoints::API_BASE_PATH) ? $path : UrssafEndpoints::API_BASE_PATH . $path;
        foreach (array_reverse($this->exchanges) as $exchange) {
            if ($exchange->method === $method && $exchange->route === $route) {
                return $exchange;
            }
        }

        return null;
    }

    private static function decode(string $json): mixed
    {
        if ($json === '') {
            return null;
        }

        try {
            return json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
