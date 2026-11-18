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
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\HttpClient\Psr18Client;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnoverHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\UrssafTierceDeclarationAutoEntrepreneurBundle;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * Processed defaults of the configuration tree, the minimal application and the tenants parameter (ADR 0015).
 */
final class BundleConfigurationTest extends TestCase
{
    public function testTheMinimalConfigurationUsesTheDocumentedDefaults(): void
    {
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        $processed = $this->process([
            'environment' => 'sandbox',
            'credentials' => ['tenant-1' => ['client_id' => 'client-1', 'client_secret' => 'secret-1']],
            'persistence' => ['driver' => 'in_memory'],
        ]);

        // The keys given by the application come first, then the tree order.
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        static::assertSame(
            [
                'environment' => 'sandbox',
                'credentials' => ['tenant-1' => ['client_id' => 'client-1', 'client_secret' => 'secret-1']],
                'persistence' => [
                    'driver' => 'in_memory',
                    'account_repository' => null,
                    'mandate_repository' => null,
                    'declaration_record_repository' => null,
                    'payment_record_repository' => null,
                    'transaction_manager' => null,
                    'workflow_statistics' => null,
                    'entity_manager' => 'default',
                ],
                'base_uri' => null,
                'user_agent' => 'thebeardedbear-urssaf-tierce-declaration-auto-entrepreneur',
                'headers' => ['request_id' => null, 'application_id' => ['name' => null, 'value' => null]],
                'business_timezone' => 'Europe/Paris',
                'token_safety_margin' => 60,
                'log_siret' => false,
                'retry_safe_writes' => [],
                'http_client' => null,
                'http_timeout' => 15.0,
                'credentials_provider' => null,
                'tenants' => [],
                'token_store' => ['cache_pool' => 'cache.app', 'service' => null],
                'gateway' => ['simulated' => false],
                'rate_limiter' => ['enabled' => false, 'limit' => 300, 'window' => 'PT60S', 'service' => null],
                'lock' => ['enabled' => 'auto', 'factory' => 'lock.factory', 'ttl' => 3600.0],
                'outbox' => ['enabled' => false, 'service' => null, 'sink' => 'messenger', 'bus' => 'messenger.default_bus', 'retention' => 'P30D'],
                'scheduler' => [
                    'enabled' => false,
                    'name' => 'urssaf_tdae',
                    'frequencies' => [
                        'sync_accounts' => 'P1D',
                        'reconcile_declarations' => 'PT15M',
                        'reconcile_payments' => 'PT15M',
                        'relay_outbox' => 'PT1M',
                        'purge_outbox' => 'P1D',
                    ],
                ],
                'workflow' => [
                    'stale_submission_after' => 'PT15M',
                    'max_submission_attempts' => 5,
                    'require_local_mandate' => true,
                    'default_page_size' => 100,
                    'max_page_size' => 1000,
                    'erasure' => ['allow_unknown_outcomes' => false, 'allow_active_mandate' => false],
                ],
                'messenger' => ['enabled' => false, 'bus' => 'messenger.default_bus'],
            ],
            $processed,
        );
    }

    public function testTheMinimalConfigurationCompiles(): void
    {
        $harness = Harness::boot();

        static::assertInstanceOf(DeclareTurnoverHandler::class, $harness->get(DeclareTurnoverHandler::class));
        static::assertInstanceOf(NoopRateLimiter::class, $harness->privateService(RateLimiter::class, RateLimiter::class));
        static::assertInstanceOf(Psr18Client::class, $harness->privateService(ServiceIds::PSR18_CLIENT, Psr18Client::class));
        static::assertSame(['tenant-1'], $harness->kernel->getContainer()->getParameter(UrssafTierceDeclarationAutoEntrepreneurBundle::PARAMETER_TENANTS));
    }

    public function testTheMinimalConfigurationWiresTheDocumentedDefaults(): void
    {
        $harness = Harness::boot();
        $policy = $harness->privateService(WorkflowPolicy::class, WorkflowPolicy::class);
        $api = $harness->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);

        static::assertSame('Europe/Paris', $policy->businessTimeZone()->getName());
        static::assertSame(15, $policy->staleSubmissionAfter()->i);
        static::assertSame(5, $policy->maxSubmissionAttempts());
        static::assertTrue($policy->requireLocalMandate());
        static::assertFalse($policy->allowErasureWithUnknownOutcomes());
        static::assertFalse($policy->allowErasureWithActiveMandate());
        static::assertSame(100, $policy->pageSize(null));
        static::assertSame(1000, $policy->pageSize(5000));
        static::assertSame(30, $policy->outboxRetention()->d);
        static::assertSame('Europe/Paris', $api->businessTimeZone()->getName());
        static::assertSame(Environment::Sandbox, $api->environment());
        static::assertSame(60, $api->tokenSafetyMarginSeconds());
        static::assertSame(ApiConfiguration::DEFAULT_USER_AGENT, $api->userAgent());
        static::assertNull($api->requestIdHeaderName());
        static::assertNull($api->applicationIdHeader());
        static::assertSame([], $api->retrySafeWrites());
        static::assertFalse($api->logSiret());
    }

    public function testTheApplicationBootsWithMessengerAndLockConfiguredBesideTheBundle(): void
    {
        // The bundle messages are routed by namespace wildcard: no message class is needed for the framework to boot.
        $harness = Harness::boot(['messenger' => ['enabled' => true], 'lock' => ['enabled' => true]], true, true);

        static::assertTrue($harness->hasPrivate('test.lock_factory'));
        static::assertTrue($harness->has('messenger.default_bus'));
    }

    public function testTheApplicationBootsWithTheOptionalAdaptersEnabled(): void
    {
        $harness = Harness::boot(['scheduler' => ['enabled' => true], 'messenger' => ['enabled' => true]], true);

        static::assertTrue($harness->has('messenger.default_bus'));
    }

    public function testOnlyTheHandlersArePublic(): void
    {
        $harness = Harness::boot();

        static::assertTrue($harness->has(DeclareTurnoverHandler::class));
        static::assertFalse($harness->has(WorkflowPolicy::class));
        static::assertFalse($harness->has(Clock::class));
        static::assertTrue($harness->hasPrivate(Clock::class));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function process(array $config): array
    {
        $extension = (new UrssafTierceDeclarationAutoEntrepreneurBundle())->getContainerExtension();
        static::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        static::assertNotNull($configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
