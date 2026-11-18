<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http;

use DateInterval;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\AccessToken;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafEndpoints;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;

/**
 * OAuth2 client-credentials flow with a shared token cache (ADR 0002, ADR 0010, ADR 0013). Build the gateway with
 * {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafGatewayFactory}.
 *
 * The cache key is `urssaf_tdae.<sha256(environment|baseUri|clientId)>` (never contains the secret; the prefix differs
 * from the sibling packages so that a shared pool never mixes scopes). Nothing secret (client secret, token) is ever
 * logged. A token failure never has an unknown outcome: the API call itself was not sent. The token request is not
 * counted by the rate limiter (ADR 0013).
 *
 * @internal
 */
final readonly class OAuth2TokenProvider
{
    private const KEY_PREFIX = 'urssaf_tdae.';

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private TokenStore $tokenStore,
        private Clock $clock,
        private ApiConfiguration $configuration,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Returns a valid bearer token, fetching a new one when the cached one is missing or about to expire.
     *
     * @throws UrssafAuthenticationFailed token endpoint answered 400, 401 or 403
     * @throws UrssafUnavailable          token endpoint answered 429 / 5xx or could not be reached (the API call itself
     *                                    is not sent: {@see UrssafUnavailable::mayHaveReachedUrssaf()} is false)
     * @throws UnexpectedUrssafResponse   any other status, unreadable token response
     */
    public function accessToken(Credentials $credentials, Operation $operation): string
    {
        $key = $this->cacheKey($credentials);
        $cached = $this->tokenStore->get($key);

        if ($cached instanceof AccessToken && !$cached->isExpired(
            $this->clock->now(),
            $this->configuration->tokenSafetyMarginSeconds(),
        )) {
            return $cached->value();
        }

        $token = $this->fetch($credentials, $operation);
        $this->tokenStore->save($key, $token);

        return $token->value();
    }

    public function invalidate(Credentials $credentials): void
    {
        $this->tokenStore->delete($this->cacheKey($credentials));
    }

    /**
     * Public for the tests of the cache contract (ADR 0010).
     */
    public function cacheKey(Credentials $credentials): string
    {
        return self::KEY_PREFIX . hash('sha256', \sprintf(
            '%s|%s|%s',
            $this->configuration->environment()->value,
            $this->configuration->baseUri(),
            $credentials->clientId(),
        ));
    }

    private function fetch(Credentials $credentials, Operation $operation): AccessToken
    {
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $credentials->clientId(),
            'client_secret' => $credentials->clientSecret(),
            'scope' => UrssafEndpoints::TOKEN_SCOPE,
        ], '', '&', PHP_QUERY_RFC3986);

        $request = $this->requestFactory
            ->createRequest('POST', $this->configuration->tokenUri())
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->configuration->userAgent())
            ->withBody($this->streamFactory->createStream($body));

        $startedAt = $this->clock->now();

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $this->logger->warning('URSSAF token request failed: transport error.', ['exception' => $exception::class]);

            throw UrssafUnavailable::duringAuthentication(
                $operation,
                null,
                null,
                TransportError::fromClientException($exception),
            );
        }

        $this->logger->info('URSSAF token request.', [
            'method' => 'POST',
            'path' => UrssafEndpoints::TOKEN_PATH,
            'status' => $response->getStatusCode(),
            'duration_ms' => Duration::millisecondsSince($startedAt, $this->clock->now()),
        ]);

        return $this->tokenFrom($response, $operation);
    }

    private function tokenFrom(ResponseInterface $response, Operation $operation): AccessToken
    {
        $status = $response->getStatusCode();

        if (\in_array($status, [400, 401, 403], true)) {
            throw UrssafAuthenticationFailed::withStatus($status);
        }

        if ($status === 429 || ($status >= 500 && $status <= 599)) {
            throw UrssafUnavailable::duringAuthentication($operation, $status, RetryAfter::seconds($response), null);
        }

        if ($status < 200 || $status > 299) {
            throw UnexpectedUrssafResponse::unexpectedStatus($status);
        }

        $payload = JsonDecoder::decode((string) $response->getBody());
        // No requireJsonObject(): the required fields below already reject an empty body or a list.
        $lifetime = $payload->number('expires_in');
        if ($lifetime === null || (int) $lifetime <= 0) {
            throw UnexpectedUrssafResponse::invalidPayload('field "expires_in" is missing or not positive');
        }

        return AccessToken::of(
            $payload->requireString('access_token'),
            Instant::fromDateTime($this->clock->now()->add(new DateInterval('PT' . (int) $lifetime . 'S'))),
        );
    }
}
