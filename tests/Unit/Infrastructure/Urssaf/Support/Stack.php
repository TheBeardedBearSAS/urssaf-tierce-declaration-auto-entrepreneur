<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\JsonApiClient;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\OAuth2TokenProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\CapturingLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\InMemoryTokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\RecordingRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\SequenceIdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\SteppingClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;

/**
 * Wiring of the Urssaf infrastructure on top of a PSR-18 mock client and in-memory fakes.
 *
 * One accessor per collaborator of the stack. There is no gateway here: it is wired by `Gateways`.
 *
 * @mago-expect lint:too-many-methods
 */
final readonly class Stack
{
    public const TOKEN_RESPONSE = '{"access_token":"fictitious-access-token-0001","token_type":"Bearer","expires_in":3600,"scope":"tiersdecl.ae"}';

    public Client $http;
    public InMemoryTokenStore $tokens;
    public RecordingRateLimiter $rateLimiter;
    public SteppingClock $clock;
    public CapturingLogger $logger;
    public StaticCredentialsProvider $credentials;
    public Psr17Factory $factory;
    public SequenceIdGenerator $ids;

    public function __construct(
        public ApiConfiguration $configuration,
        int $clockStepMicroseconds,
    ) {
        $this->http = new Client();
        $this->tokens = new InMemoryTokenStore();
        $this->rateLimiter = new RecordingRateLimiter();
        $this->clock = new SteppingClock($clockStepMicroseconds);
        $this->logger = new CapturingLogger();
        $this->credentials = new StaticCredentialsProvider();
        $this->factory = new Psr17Factory();
        $this->ids = new SequenceIdGenerator();
    }

    /**
     * The credentials of the stack tenant (the `credentials` property is the provider).
     */
    public function tenantCredentials(): Credentials
    {
        return $this->credentials->forTenant(Stacks::tenant());
    }

    /**
     * Fetches and caches a token now, so that a later failure queued on the mock client hits the API call itself.
     */
    public function warmUpToken(): void
    {
        $this->respondToken();
        $this->tokenProvider()->accessToken($this->tenantCredentials(), Operation::Estimate);
        $this->logger->clear();
    }

    public function transport(): HttpTransport
    {
        return new HttpTransport($this->http, $this->factory, $this->factory);
    }

    public function tokenProvider(): OAuth2TokenProvider
    {
        return new OAuth2TokenProvider(
            $this->http,
            $this->factory,
            $this->factory,
            $this->tokens,
            $this->clock,
            $this->configuration,
            $this->logger,
        );
    }

    public function jsonClient(): JsonApiClient
    {
        return $this->jsonClientWith($this->rateLimiter);
    }

    public function jsonClientWithIds(IdGenerator $ids): JsonApiClient
    {
        return $this->build($this->rateLimiter, $ids);
    }

    public function jsonClientWith(RateLimiter $rateLimiter): JsonApiClient
    {
        return $this->build($rateLimiter, $this->ids);
    }

    private function build(RateLimiter $rateLimiter, IdGenerator $ids): JsonApiClient
    {
        return new JsonApiClient(
            $this->http,
            $this->factory,
            $this->factory,
            $this->tokenProvider(),
            $rateLimiter,
            $ids,
            $this->clock,
            $this->configuration,
            $this->logger,
        );
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_values($this->http->getRequests());
    }

    public function lastRequest(): RequestInterface
    {
        $requests = $this->requests();
        $last = array_pop($requests);

        return $last ?? throw new LogicException('No request was sent.');
    }

    /**
     * @param array<string, string> $headers
     */
    public function respond(int $status, string $body, array $headers = []): void
    {
        $this->http->addResponse(new Response($status, $headers, $body));
    }

    public function respondToken(): void
    {
        $this->respond(200, self::TOKEN_RESPONSE);
    }

    public function fail(TransportFailure $exception): void
    {
        $this->http->addException($exception);
    }
}
