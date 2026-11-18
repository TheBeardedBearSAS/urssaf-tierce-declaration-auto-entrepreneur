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

use DateTimeZone;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionEstimator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionPayer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\MandateNotifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateFinder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRevoker;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TurnoverDeclarer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\JsonApiClient;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\OAuth2TokenProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpHeader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;

/**
 * The URSSAF HTTP gateway and everything it needs: PSR-18 client, PSR-17 factories, access tokens, JSON client.
 *
 * It is wired piece by piece (not through UrssafGatewayFactory) to expose the probe token provider of
 * "urssaf:tdae:check". The token provider, the JSON client and the gateway take their collaborators directly.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class GatewayRegistrar
{
    private const LOGGER_TAG = 'monolog.logger';

    /** Ports implemented by the gateway, each one an alias of {@see TierceDeclarationGateway}. */
    private const PORTS = [
        AccountReader::class,
        MandateNotifier::class,
        ContributionEstimator::class,
        TurnoverDeclarer::class,
        ContributionPayer::class,
        SepaMandateRegistrar::class,
        SepaMandateRevoker::class,
        SepaMandateFinder::class,
    ];

    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        // "max_duration" bounds the whole call (the timeout alone only bounds the inactivity): the stale rule of the
        // configuration (ConfigurationRules::staleSubmissionNotAboveHttpTimeout) relies on "http_timeout" being the
        // duration of one call.
        $this->services->set(ServiceIds::SYMFONY_HTTP_CLIENT, HttpClientInterface::class)
            ->factory(HttpClient::class . '::create')
            ->args([['timeout' => $config['http_timeout'], 'max_duration' => $config['http_timeout']]]);
        // Also provides the PSR-17 request and stream factories, whatever the PSR-18 client in use.
        $this->services->set(ServiceIds::PSR18_CLIENT, Psr18Client::class)->args([new Reference(ServiceIds::SYMFONY_HTTP_CLIENT)]);
        $this->services->set(ServiceIds::API_CONFIGURATION, ApiConfiguration::class)->args([
            // Resolved at runtime: the environment is usually an "%env(URSSAF_TDAE_ENV)%" placeholder.
            (new Definition(Environment::class, [$config['environment']]))->setFactory([Environment::class, 'from']),
            $config['base_uri'],
            $config['headers']['request_id'],
            $this->applicationIdHeader($config['headers']['application_id']),
            $config['token_safety_margin'],
            $config['user_agent'],
            new Definition(DateTimeZone::class, [$config['business_timezone']]),
            array_map(
                static fn(string $operation): Definition => (new Definition(Operation::class, [$operation]))->setFactory([Operation::class, 'from']),
                $config['retry_safe_writes'],
            ),
            $config['log_siret'],
        ]);

        $client = new Reference($config['http_client'] ?? ServiceIds::PSR18_CLIENT);
        foreach ([ServiceIds::TOKEN_PROVIDER => new Reference(TokenStore::class), ServiceIds::PROBE_TOKEN_PROVIDER => new Definition(InMemoryTokenStore::class)] as $id => $tokenStore) {
            $this->services->set($id, OAuth2TokenProvider::class)
                ->args([
                    $client,
                    new Reference(ServiceIds::PSR18_CLIENT),
                    new Reference(ServiceIds::PSR18_CLIENT),
                    $tokenStore,
                    new Reference(Clock::class),
                    new Reference(ServiceIds::API_CONFIGURATION),
                    self::logger(),
                ])
                ->tag(self::LOGGER_TAG, ['channel' => ServiceIds::LOGGER_CHANNEL]);
        }
        $this->services->set(ServiceIds::JSON_CLIENT, JsonApiClient::class)
            ->args([
                $client,
                new Reference(ServiceIds::PSR18_CLIENT),
                new Reference(ServiceIds::PSR18_CLIENT),
                new Reference(ServiceIds::TOKEN_PROVIDER),
                new Reference(RateLimiter::class),
                new Reference(IdGenerator::class),
                new Reference(Clock::class),
                new Reference(ServiceIds::API_CONFIGURATION),
                self::logger(),
            ])
            ->tag(self::LOGGER_TAG, ['channel' => ServiceIds::LOGGER_CHANNEL]);
        $this->registerGateway($config);
        foreach (self::PORTS as $port) {
            $this->services->alias($port, TierceDeclarationGateway::class);
        }
    }

    /**
     * The HTTP gateway, or the simulator (tests) with "gateway.simulated".
     *
     * @param Config $config
     */
    private function registerGateway(array $config): void
    {
        if ($config['gateway']['simulated']) {
            $this->services->set(TierceDeclarationGateway::class, SimulatedAeGateway::class)
                ->factory(SimulatedGatewayFactory::class . '::create')
                ->args([$config['environment'], new Reference(Clock::class)]);
            $this->services->alias(SimulatedAeGateway::class, TierceDeclarationGateway::class)->public();

            return;
        }
        $this->services->set(TierceDeclarationGateway::class, UrssafHttpGateway::class)
            ->args([new Reference(CredentialsProvider::class), new Reference(ServiceIds::JSON_CLIENT), self::logger()])
            ->tag(self::LOGGER_TAG, ['channel' => ServiceIds::LOGGER_CHANNEL]);
    }

    /**
     * The logger is a plain mandatory reference: the constructors take a non-nullable LoggerInterface and an
     * IGNORE_ON_INVALID argument would become null. FrameworkBundle (required by the bundle) always defines "logger".
     */
    private static function logger(): Reference
    {
        return new Reference('logger');
    }

    /**
     * An empty name is "not configured", like null (ConfigurationRules::incompleteApplicationIdHeader()).
     *
     * @param array{name: ?string, value: ?string} $header
     */
    private function applicationIdHeader(array $header): ?Definition
    {
        if ($header['name'] === null || $header['name'] === '') {
            return null;
        }

        return (new Definition(HttpHeader::class, [$header['name'], $header['value']]))->setFactory([HttpHeader::class, 'of']);
    }
}
