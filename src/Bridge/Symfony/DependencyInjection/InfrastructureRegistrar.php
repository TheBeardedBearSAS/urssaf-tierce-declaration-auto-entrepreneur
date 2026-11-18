<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\EventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\FailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\Psr14EventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\RandomUuidGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\SecureRandomBytes;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Logging\PsrLoggerFailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore\Psr6TokenStore;

/**
 * Generic infrastructure: clock, identifiers, events, failure reporting, credentials, access token cache, rate limiter.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class InfrastructureRegistrar
{
    private const LOGGER_TAG = 'monolog.logger';

    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        $this->services->set(Clock::class, Clock::class)
            ->factory(ClockFactory::class . '::create')
            ->args([new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $this->services->set(IdGenerator::class, RandomUuidGenerator::class)->args([new Definition(SecureRandomBytes::class)]);
        $this->services->set(EventPublisher::class, Psr14EventPublisher::class)->args([new Reference('event_dispatcher')]);
        // The logger is mandatory: the constructor takes a non-nullable LoggerInterface and an IGNORE_ON_INVALID
        // argument would become null. FrameworkBundle (required by the bundle) always defines "logger".
        $this->services->set(FailureReporter::class, PsrLoggerFailureReporter::class)
            ->args([new Reference('logger')])
            ->tag(self::LOGGER_TAG, ['channel' => ServiceIds::LOGGER_CHANNEL]);
        $this->registerCredentials($config['credentials_provider'], $config['credentials']);
        $this->registerTokenStore($config['token_store']);
        $this->registerRateLimiter($config['rate_limiter']);
    }

    /**
     * The client secret only reaches the container as an argument of the {@see Credentials::of()} factory, so that a
     * dumped container shows the "%env(...)%" placeholder, never the secret (ADR 0010).
     *
     * @param array<string, array{client_id: string, client_secret: string}> $credentials
     */
    private function registerCredentials(?string $provider, array $credentials): void
    {
        if ($provider !== null) {
            $this->services->alias(CredentialsProvider::class, $provider);

            return;
        }
        $definitions = [];
        foreach ($credentials as $tenantId => $tenant) {
            $definitions[$tenantId] = (new Definition(Credentials::class, [$tenant['client_id'], $tenant['client_secret']]))
                ->setFactory([Credentials::class, 'of']);
        }
        $this->services->set(CredentialsProvider::class, InMemoryCredentialsProvider::class)->args([$definitions]);
    }

    /**
     * @param array{cache_pool: string, service: ?string} $tokenStore
     */
    private function registerTokenStore(array $tokenStore): void
    {
        if ($tokenStore['service'] !== null) {
            $this->services->alias(TokenStore::class, $tokenStore['service']);

            return;
        }
        $this->services->set(TokenStore::class, Psr6TokenStore::class)->args([new Reference($tokenStore['cache_pool'])]);
    }

    /**
     * @param array{enabled: bool, limit: int, window: string, service: ?string} $rateLimiter
     */
    private function registerRateLimiter(array $rateLimiter): void
    {
        if (!$rateLimiter['enabled']) {
            $this->services->set(RateLimiter::class, NoopRateLimiter::class);

            return;
        }
        if ($rateLimiter['service'] !== null) {
            $this->services->alias(RateLimiter::class, $rateLimiter['service']);

            return;
        }
        $this->services->set(RateLimiter::class, SlidingWindowRateLimiter::class)
            ->args([new Reference(Clock::class), $rateLimiter['limit'], ConfigurationRules::seconds($rateLimiter['window'])]);
    }
}
