<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf;

use Psr\Log\NullLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\RandomUuidGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\SecureRandomBytes;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\JsonApiClient;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\OAuth2TokenProvider;

/**
 * Public entry point to build the URSSAF HTTP gateway outside a framework bridge:
 *
 *     $gateway = UrssafGatewayFactory::createWith(
 *         new HttpTransport($psr18Client, $psr17RequestFactory, $psr17StreamFactory),
 *         $credentialsProvider,
 *         $tokenStore,
 *         ApiConfiguration::forEnvironment(Environment::Production),
 *         GatewayOptions::defaults()->withLogger($logger),
 *     );
 *
 * {@see self::create()} takes the same arguments without the options.
 *
 * The OAuth2 token provider and the JSON client it wires are internal.
 */
final class UrssafGatewayFactory
{
    /**
     * Gateway with the default options ({@see GatewayOptions::defaults()}): sliding window of 300 calls per 60 seconds
     * per tenant (ADR 0013), random UUIDs, UTC clock, no log.
     */
    public static function create(
        HttpTransport $transport,
        CredentialsProvider $credentials,
        TokenStore $tokens,
        ApiConfiguration $configuration,
    ): UrssafHttpGateway {
        return self::createWith($transport, $credentials, $tokens, $configuration, GatewayOptions::defaults());
    }

    public static function createWith(
        HttpTransport $transport,
        CredentialsProvider $credentials,
        TokenStore $tokens,
        ApiConfiguration $configuration,
        GatewayOptions $options,
    ): UrssafHttpGateway {
        $clock = $options->clock() ?? new UtcSystemClock();
        $logger = $options->logger() ?? new NullLogger();
        $rateLimiter = $options->rateLimiter() ?? new SlidingWindowRateLimiter(
            $clock,
            $options->rateLimit() ?? SlidingWindowRateLimiter::DEFAULT_LIMIT,
            $options->rateLimitWindowSeconds() ?? SlidingWindowRateLimiter::DEFAULT_WINDOW_SECONDS,
        );
        $ids = $options->idGenerator() ?? new RandomUuidGenerator(new SecureRandomBytes());

        $tokenProvider = new OAuth2TokenProvider(
            $transport->client(),
            $transport->requestFactory(),
            $transport->streamFactory(),
            $tokens,
            $clock,
            $configuration,
            $logger,
        );
        $client = new JsonApiClient(
            $transport->client(),
            $transport->requestFactory(),
            $transport->streamFactory(),
            $tokenProvider,
            $rateLimiter,
            $ids,
            $clock,
            $configuration,
            $logger,
        );

        return new UrssafHttpGateway($credentials, $client, $logger);
    }
}
