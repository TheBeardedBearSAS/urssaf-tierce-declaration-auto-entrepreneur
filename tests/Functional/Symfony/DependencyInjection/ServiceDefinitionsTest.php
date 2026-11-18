<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\EventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\FailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionEstimator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ContributionPayer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\MandateNotifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateFinder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRegistrar;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\SepaMandateRevoker;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TurnoverDeclarer;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ClockFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\SimulatedGatewayFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Outbox\MessengerIntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\Psr14EventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\Psr14IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Logging\PsrLoggerFailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore\Psr6TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\JsonApiClient;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\OAuth2TokenProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpHeader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;

/**
 * The service definitions declared by the bundle (not the compiled container): arguments, aliases, visibility, tags.
 *
 * One test per definition of the bundle, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final class ServiceDefinitionsTest extends TestCase
{
    private const LOGGER_TAG = ['monolog.logger' => [['channel' => 'urssaf_tdae']]];

    public function testThePortsOfTheGatewayAreAliasesOfTheGateway(): void
    {
        $container = self::definitions();

        foreach ([AccountReader::class, MandateNotifier::class, ContributionEstimator::class, TurnoverDeclarer::class, ContributionPayer::class, SepaMandateRegistrar::class, SepaMandateRevoker::class, SepaMandateFinder::class] as $port) {
            static::assertSame(TierceDeclarationGateway::class, (string) $container->getAlias($port), $port);
            static::assertFalse($container->getAlias($port)->isPublic(), $port);
        }
        static::assertSame(UrssafHttpGateway::class, $container->getDefinition(TierceDeclarationGateway::class)->getClass());
        static::assertFalse($container->getDefinition(TierceDeclarationGateway::class)->isPublic());
    }

    public function testTheGatewayArgumentsAreItsCollaborators(): void
    {
        $container = self::definitions();
        $gateway = $container->getDefinition(TierceDeclarationGateway::class);

        static::assertEquals([new Reference(CredentialsProvider::class), new Reference(ServiceIds::JSON_CLIENT), new Reference('logger')], $gateway->getArguments());
        static::assertSame(self::LOGGER_TAG, $gateway->getTags());
        static::assertSame(JsonApiClient::class, $container->getDefinition(ServiceIds::JSON_CLIENT)->getClass());
        static::assertEquals(
            [
                new Reference('test.http_client'),
                new Reference(ServiceIds::PSR18_CLIENT),
                new Reference(ServiceIds::PSR18_CLIENT),
                new Reference(ServiceIds::TOKEN_PROVIDER),
                new Reference(RateLimiter::class),
                new Reference(IdGenerator::class),
                new Reference(Clock::class),
                new Reference(ServiceIds::API_CONFIGURATION),
                new Reference('logger'),
            ],
            $container->getDefinition(ServiceIds::JSON_CLIENT)->getArguments(),
        );
        static::assertSame(self::LOGGER_TAG, $container->getDefinition(ServiceIds::JSON_CLIENT)->getTags());
    }

    public function testTheTokenProviderUsesTheSharedTokenStoreAndTheProbeHasItsOwn(): void
    {
        $container = self::definitions();
        $token = $container->getDefinition(ServiceIds::TOKEN_PROVIDER);
        $probe = $container->getDefinition(ServiceIds::PROBE_TOKEN_PROVIDER);

        static::assertSame(OAuth2TokenProvider::class, $token->getClass());
        static::assertSame(OAuth2TokenProvider::class, $probe->getClass());
        static::assertEquals(
            [
                new Reference('test.http_client'),
                new Reference(ServiceIds::PSR18_CLIENT),
                new Reference(ServiceIds::PSR18_CLIENT),
                new Reference(TokenStore::class),
                new Reference(Clock::class),
                new Reference(ServiceIds::API_CONFIGURATION),
                new Reference('logger'),
            ],
            $token->getArguments(),
        );
        $probeStore = self::nested($probe, 3);
        static::assertSame(InMemoryTokenStore::class, $probeStore->getClass());
        static::assertEquals(
            [
                new Reference('test.http_client'),
                new Reference(ServiceIds::PSR18_CLIENT),
                new Reference(ServiceIds::PSR18_CLIENT),
                $probeStore,
                new Reference(Clock::class),
                new Reference(ServiceIds::API_CONFIGURATION),
                new Reference('logger'),
            ],
            $probe->getArguments(),
        );
        static::assertSame(self::LOGGER_TAG, $token->getTags());
        static::assertSame(self::LOGGER_TAG, $probe->getTags());
    }

    public function testTheDedicatedHttpClientIsUsedByDefaultAndBoundsTheWholeCall(): void
    {
        $container = self::definitions(['http_client' => null, 'http_timeout' => 7.5]);

        static::assertEquals(
            [new Reference(ServiceIds::PSR18_CLIENT), new Reference(ServiceIds::PSR18_CLIENT), new Reference(ServiceIds::PSR18_CLIENT)],
            [
                $container->getDefinition(ServiceIds::JSON_CLIENT)->getArguments()[0],
                $container->getDefinition(ServiceIds::JSON_CLIENT)->getArguments()[1],
                $container->getDefinition(ServiceIds::TOKEN_PROVIDER)->getArguments()[0],
            ],
        );
        static::assertSame([['timeout' => 7.5, 'max_duration' => 7.5]], $container->getDefinition(ServiceIds::SYMFONY_HTTP_CLIENT)->getArguments());
        static::assertEquals([new Reference(ServiceIds::SYMFONY_HTTP_CLIENT)], $container->getDefinition(ServiceIds::PSR18_CLIENT)->getArguments());
    }

    public function testTheHttpClientOfTheApplicationReplacesTheDedicatedOneForTheCalls(): void
    {
        $container = self::definitions();

        static::assertEquals(new Reference('test.http_client'), $container->getDefinition(ServiceIds::JSON_CLIENT)->getArguments()[0]);
        static::assertEquals(new Reference('test.http_client'), $container->getDefinition(ServiceIds::TOKEN_PROVIDER)->getArguments()[0]);
        static::assertEquals(new Reference('test.http_client'), $container->getDefinition(ServiceIds::PROBE_TOKEN_PROVIDER)->getArguments()[0]);
        // The PSR-17 factories always come from the dedicated client.
        static::assertEquals(new Reference(ServiceIds::PSR18_CLIENT), $container->getDefinition(ServiceIds::JSON_CLIENT)->getArguments()[1]);
        static::assertEquals(new Reference(ServiceIds::PSR18_CLIENT), $container->getDefinition(ServiceIds::JSON_CLIENT)->getArguments()[2]);
    }

    public function testTheApiConfigurationArgumentsFollowTheConstructor(): void
    {
        $container = self::definitions([
            'environment' => 'production',
            'base_uri' => 'https://proxy.example.test',
            'user_agent' => 'my-application/1.0',
            'headers' => ['request_id' => 'X-Request-Id', 'application_id' => ['name' => 'X-Application', 'value' => 'my-application']],
            'business_timezone' => 'Pacific/Noumea',
            'token_safety_margin' => 120,
            'log_siret' => true,
            'retry_safe_writes' => ['030', '031'],
        ]);
        $arguments = $container->getDefinition(ServiceIds::API_CONFIGURATION)->getArguments();

        static::assertSame(ApiConfiguration::class, $container->getDefinition(ServiceIds::API_CONFIGURATION)->getClass());
        static::assertCount(9, $arguments);
        $environment = self::nested($container->getDefinition(ServiceIds::API_CONFIGURATION), 0);
        static::assertSame([Environment::class, 'from'], $environment->getFactory());
        static::assertSame(['production'], $environment->getArguments());
        static::assertSame('https://proxy.example.test', $arguments[1]);
        static::assertSame('X-Request-Id', $arguments[2]);
        $application = self::nested($container->getDefinition(ServiceIds::API_CONFIGURATION), 3);
        static::assertSame([HttpHeader::class, 'of'], $application->getFactory());
        static::assertSame(['X-Application', 'my-application'], $application->getArguments());
        static::assertSame(120, $arguments[4]);
        static::assertSame('my-application/1.0', $arguments[5]);
        $zone = self::nested($container->getDefinition(ServiceIds::API_CONFIGURATION), 6);
        static::assertSame(['Pacific/Noumea'], $zone->getArguments());
        $writes = self::nestedList($container->getDefinition(ServiceIds::API_CONFIGURATION), 7);
        static::assertCount(2, $writes);
        foreach ([Operation::NotifyMandate, Operation::CancelMandate] as $index => $operation) {
            static::assertEquals((new Definition(Operation::class, [$operation->value]))->setFactory([Operation::class, 'from']), $writes[$index]);
        }
        static::assertTrue($arguments[8]);
    }

    public function testTheApiConfigurationWithoutOptionalHeadersHasNone(): void
    {
        $arguments = self::definitions(['headers' => ['application_id' => ['name' => '', 'value' => '']]])->getDefinition(ServiceIds::API_CONFIGURATION)->getArguments();

        static::assertNull($arguments[2]);
        static::assertNull($arguments[3]);
        static::assertSame([], $arguments[7]);
        static::assertFalse($arguments[8]);
    }

    public function testTheFailureReporterReceivesThePackageLogger(): void
    {
        $definition = self::definitions()->getDefinition(FailureReporter::class);

        static::assertSame(PsrLoggerFailureReporter::class, $definition->getClass());
        static::assertEquals([new Reference('logger')], $definition->getArguments());
        static::assertSame(self::LOGGER_TAG, $definition->getTags());
    }

    public function testTheEventsAndTheIdentifiersAreWiredOnTheContainer(): void
    {
        $container = self::definitions();

        static::assertSame(Psr14EventPublisher::class, $container->getDefinition(EventPublisher::class)->getClass());
        static::assertEquals([new Reference('event_dispatcher')], $container->getDefinition(EventPublisher::class)->getArguments());
        static::assertSame([ClockFactory::class, 'create'], $container->getDefinition(Clock::class)->getFactory());
        static::assertEquals([new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE)], $container->getDefinition(Clock::class)->getArguments());
    }

    public function testTheCredentialsAreBuiltFromTheConfiguration(): void
    {
        $definition = self::definitions()->getDefinition(CredentialsProvider::class);
        $tenants = self::nestedList($definition, 0);

        static::assertSame(InMemoryCredentialsProvider::class, $definition->getClass());
        static::assertSame(['tenant-1'], array_keys($tenants));
        // @mago-expect analysis:mixed-assignment The tenants map is untyped; the assertion below narrows it.
        $credentials = $tenants['tenant-1'];
        static::assertInstanceOf(Definition::class, $credentials);
        // The secret is an environment placeholder: the value never reaches the definitions.
        static::assertSame('client-1', $credentials->getArgument(0));
        static::assertStringContainsString('URSSAF_TEST_SECRET', self::text($credentials, 1));
        static::assertStringNotContainsString(TestKernel::SECRET, self::text($credentials, 1));
    }

    public function testTheCredentialsProviderOfTheApplicationIsAnAlias(): void
    {
        $container = self::definitions(['credentials' => null, 'credentials_provider' => 'test.credentials']);

        static::assertSame('test.credentials', (string) $container->getAlias(CredentialsProvider::class));
    }

    public function testTheTokenStoreIsAPsr6StoreOnTheConfiguredPool(): void
    {
        $definition = self::definitions(['token_store' => ['cache_pool' => 'my.pool']])->getDefinition(TokenStore::class);

        static::assertSame(Psr6TokenStore::class, $definition->getClass());
        static::assertEquals([new Reference('my.pool')], $definition->getArguments());
    }

    public function testTheTokenStoreOfTheApplicationIsAnAlias(): void
    {
        static::assertSame('test.token_store', (string) self::definitions(['token_store' => ['service' => 'test.token_store']])->getAlias(TokenStore::class));
    }

    public function testTheRateLimiterIsOffByDefaultAndTakesTheHostLimitsWhenEnabled(): void
    {
        static::assertSame(NoopRateLimiter::class, self::definitions()->getDefinition(RateLimiter::class)->getClass());

        $limiter = self::definitions(['rate_limiter' => ['enabled' => true, 'limit' => 100, 'window' => 'PT2M']])->getDefinition(RateLimiter::class);

        static::assertSame(SlidingWindowRateLimiter::class, $limiter->getClass());
        static::assertEquals([new Reference(Clock::class), 100, 120], $limiter->getArguments());
    }

    public function testTheBuiltInRateLimiterDefaultsToTheOfficialQuota(): void
    {
        $limiter = self::definitions(['rate_limiter' => ['enabled' => true]])->getDefinition(RateLimiter::class);

        static::assertEquals([new Reference(Clock::class), 300, 60], $limiter->getArguments());
    }

    public function testTheRateLimiterOfTheApplicationIsAnAlias(): void
    {
        $container = self::definitions(['rate_limiter' => ['enabled' => true, 'service' => 'my.limiter']]);

        static::assertSame('my.limiter', (string) $container->getAlias(RateLimiter::class));
        static::assertFalse($container->hasDefinition(RateLimiter::class));
    }

    public function testTheWorkflowPolicyReceivesNamedArguments(): void
    {
        $definition = self::definitions([
            'business_timezone' => 'Pacific/Noumea',
            'outbox' => ['retention' => 'P7D'],
            'workflow' => [
                'stale_submission_after' => 'PT45M',
                'max_submission_attempts' => 3,
                'require_local_mandate' => false,
                'default_page_size' => 10,
                'max_page_size' => 20,
                'erasure' => ['allow_unknown_outcomes' => true, 'allow_active_mandate' => true],
            ],
        ])->getDefinition(WorkflowPolicy::class);
        $arguments = $definition->getArguments();

        static::assertSame(
            ['$businessTimeZone', '$staleSubmissionAfter', '$maxSubmissionAttempts', '$requireLocalMandate', '$defaultPageSize', '$maxPageSize', '$allowErasureWithUnknownOutcomes', '$allowErasureWithActiveMandate', '$outboxRetention'],
            array_keys($arguments),
        );
        static::assertSame(3, $arguments['$maxSubmissionAttempts']);
        static::assertFalse($arguments['$requireLocalMandate']);
        static::assertSame(10, $arguments['$defaultPageSize']);
        static::assertSame(20, $arguments['$maxPageSize']);
        static::assertTrue($arguments['$allowErasureWithUnknownOutcomes']);
        static::assertTrue($arguments['$allowErasureWithActiveMandate']);
        foreach (['$businessTimeZone' => 'Pacific/Noumea', '$staleSubmissionAfter' => 'PT45M', '$outboxRetention' => 'P7D'] as $name => $value) {
            static::assertInstanceOf(Definition::class, $arguments[$name]);
            static::assertSame([$value], $arguments[$name]->getArguments());
        }
    }

    public function testTheUnitOfWorkReceivesEveryCollaboratorExplicitly(): void
    {
        $definition = self::definitions()->getDefinition(UnitOfWork::class);

        static::assertEquals(
            [new Reference(TransactionManager::class), new Reference(EventPublisher::class), new Reference(FailureReporter::class), new Reference(Outbox::class)],
            $definition->getArguments(),
        );
        static::assertFalse($definition->isAutowired());
    }

    public function testTheOutboxIsIgnoredWhenDisabled(): void
    {
        $container = self::definitions();

        static::assertSame(IgnoredOutbox::class, $container->getDefinition(Outbox::class)->getClass());
        static::assertFalse($container->hasDefinition(IntegrationEventSink::class));
        static::assertFalse($container->hasDefinition(RelayOutboxHandler::class));
        static::assertFalse($container->hasDefinition(PurgeOutboxHandler::class));
    }

    public function testTheOutboxIsInMemoryByDefaultAndRelaysToMessenger(): void
    {
        $container = self::definitions(['outbox' => ['enabled' => true, 'bus' => 'my.bus']]);

        static::assertSame(InMemoryOutbox::class, $container->getDefinition(Outbox::class)->getClass());
        static::assertSame(MessengerIntegrationEventSink::class, $container->getDefinition(IntegrationEventSink::class)->getClass());
        static::assertEquals([new Reference('my.bus')], $container->getDefinition(IntegrationEventSink::class)->getArguments());
        static::assertTrue($container->getDefinition(RelayOutboxHandler::class)->isPublic());
        static::assertTrue($container->getDefinition(PurgeOutboxHandler::class)->isPublic());
    }

    public function testTheOutboxOfTheApplicationIsAnAlias(): void
    {
        static::assertSame('test.outbox', (string) self::definitions(['outbox' => ['enabled' => true, 'service' => 'test.outbox']])->getAlias(Outbox::class));
    }

    public function testTheSinkMayBeTheEventDispatcher(): void
    {
        $sink = self::definitions(['outbox' => ['enabled' => true, 'sink' => 'event_dispatcher']])->getDefinition(IntegrationEventSink::class);

        static::assertSame(Psr14IntegrationEventSink::class, $sink->getClass());
        static::assertEquals([new Reference('event_dispatcher')], $sink->getArguments());
    }

    public function testTheSinkMayBeAServiceOfTheApplication(): void
    {
        static::assertSame('test.sink', (string) self::definitions(['outbox' => ['enabled' => true, 'sink' => 'test.sink']])->getAlias(IntegrationEventSink::class));
    }

    public function testTheLocksReceiveTheOptionalFactoryAndTheTtl(): void
    {
        static::assertEquals([new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE), 3600.0], self::definitions()->getDefinition(OperationLocks::class)->getArguments());
        static::assertEquals([new Reference('lock.factory'), 30.0], self::definitions(['lock' => ['enabled' => true, 'ttl' => 30.0]])->getDefinition(OperationLocks::class)->getArguments());
        static::assertEquals([null, 3600.0], self::definitions(['lock' => ['enabled' => false]])->getDefinition(OperationLocks::class)->getArguments());
    }

    public function testTheSimulatedGatewayIsBuiltByTheFactoryAndPublic(): void
    {
        $container = self::definitions(['gateway' => ['simulated' => true]]);

        static::assertSame(SimulatedAeGateway::class, $container->getDefinition(TierceDeclarationGateway::class)->getClass());
        static::assertSame([SimulatedGatewayFactory::class, 'create'], $container->getDefinition(TierceDeclarationGateway::class)->getFactory());
        static::assertEquals(['sandbox', new Reference(Clock::class)], $container->getDefinition(TierceDeclarationGateway::class)->getArguments());
        static::assertSame(TierceDeclarationGateway::class, (string) $container->getAlias(SimulatedAeGateway::class));
        static::assertTrue($container->getAlias(SimulatedAeGateway::class)->isPublic());
    }

    private static function nested(Definition $definition, int $index): Definition
    {
        // @mago-expect analysis:mixed-assignment The arguments of a definition are untyped; the assertion below narrows them.
        $nested = $definition->getArguments()[$index] ?? null;
        static::assertInstanceOf(Definition::class, $nested);

        return $nested;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function nestedList(Definition $definition, int $index): array
    {
        // @mago-expect analysis:mixed-assignment The arguments of a definition are untyped; the assertion below narrows them.
        $list = $definition->getArguments()[$index] ?? null;
        static::assertIsArray($list);

        return $list;
    }

    private static function text(Definition $definition, int $index): string
    {
        // @mago-expect analysis:mixed-assignment The arguments of a definition are untyped; the assertion below narrows them.
        $text = $definition->getArguments()[$index] ?? null;
        static::assertIsString($text);

        return $text;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function definitions(array $config = []): ContainerBuilder
    {
        return (new TestKernel($config))->definitions();
    }
}
