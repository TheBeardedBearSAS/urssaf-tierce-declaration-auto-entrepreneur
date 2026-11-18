<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Diagnostic;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\AuthenticationCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CheckContext;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CheckResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CheckStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\ConfigurationCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\CredentialsCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\LockCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\PersistenceCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\RateLimiterCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\SimulatedGatewayCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Diagnostic\TokenStoreCheck;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\OAuth2TokenProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;

/**
 * Every check of the installation, one method per outcome (ADR 0015 item 10).
 *
 * @mago-expect lint:too-many-methods Each outcome of each check is one test method.
 */
final class ChecksTest extends TestCase
{
    public function testACredentialsCheckReportsEveryTenant(): void
    {
        $check = new CredentialsCheck(new InMemoryCredentialsProvider(['tenant-1' => Credentials::of('client-1', 'secret-1')]));

        $results = $check->run(CheckContext::withoutRemoteCalls([TenantId::fromString('tenant-1'), TenantId::fromString('tenant-2')]));

        static::assertEquals([
            new CheckResult('credentials', 'tenant-1', CheckStatus::Ok, 'Credentials found.'),
            new CheckResult('credentials', 'tenant-2', CheckStatus::Failure, $results[1]->detail),
        ], $results);
        static::assertStringContainsString('tenant-2', $results[1]->detail);
        static::assertStringNotContainsString('secret-1', $results[1]->detail);
    }

    public function testTheSimulatedGatewayIsAWarning(): void
    {
        $results = (new SimulatedGatewayCheck())->run(self::context());

        static::assertEquals([new CheckResult('simulated gateway', '', CheckStatus::Warning, 'Simulated gateway ("gateway.simulated"): nothing is sent to URSSAF.')], $results);
    }

    public function testTheLockIsOkOnlyWhenEnabled(): void
    {
        $enabled = (new LockCheck(new OperationLocks(new LockFactory(new InMemoryStore()))))->run(self::context());
        $disabled = (new LockCheck(new OperationLocks(null)))->run(self::context());

        static::assertEquals([new CheckResult('lock', '', CheckStatus::Ok, 'Enabled: use a store shared by every host that runs the crons and workers.')], $enabled);
        static::assertEquals([new CheckResult('lock', '', CheckStatus::Warning, 'Disabled: overlapping runs are only protected by the claims (install symfony/lock, see docs/symfony.md).')], $disabled);
    }

    public function testTheTokenStoreIsReportedByServiceThenByPool(): void
    {
        $service = (new TokenStoreCheck('app.token_store', 'cache.app'))->run(self::context());
        $shared = (new TokenStoreCheck(null, 'cache.app'))->run(self::context());
        $dedicated = (new TokenStoreCheck(null, 'urssaf_tdae.token_cache'))->run(self::context());

        static::assertEquals([new CheckResult('token store', '', CheckStatus::Ok, 'Service "app.token_store".')], $service);
        static::assertSame(CheckStatus::Warning, $shared[0]->status);
        static::assertStringContainsString('declare a dedicated pool shared by every process', $shared[0]->detail);
        static::assertEquals([new CheckResult('token store', '', CheckStatus::Ok, 'Cache pool "urssaf_tdae.token_cache": it must be shared by every process that calls URSSAF.')], $dedicated);
    }

    public function testThePersistenceDriverIsReported(): void
    {
        $inMemory = (new PersistenceCheck('in_memory'))->run(self::context());
        $custom = (new PersistenceCheck('custom'))->run(self::context());
        $doctrine = (new PersistenceCheck('doctrine'))->run(self::context());

        static::assertEquals([new CheckResult('persistence', '', CheckStatus::Warning, 'In-memory persistence: every record is lost when the process ends (tests and prototypes only).')], $inMemory);
        static::assertEquals([new CheckResult('persistence', '', CheckStatus::Ok, 'Repositories of the application ("custom" driver).')], $custom);
        static::assertEquals([new CheckResult('persistence', '', CheckStatus::Ok, 'Doctrine repositories (see the database schema check).')], $doctrine);
    }

    public function testTheRateLimiterIsReportedOffThenByServiceThenByLimit(): void
    {
        $off = (new RateLimiterCheck(false, 300, null, 60))->run(self::context());
        $offWhateverTheService = (new RateLimiterCheck(false, 1000, 'app.limiter', 60))->run(self::context());
        $service = (new RateLimiterCheck(true, 1000, 'app.limiter', 60))->run(self::context());
        $builtIn = (new RateLimiterCheck(true, 300, null, 60))->run(self::context());
        $above = (new RateLimiterCheck(true, 301, null, 60))->run(self::context());

        static::assertEquals([new CheckResult('rate limiter', '', CheckStatus::Ok, 'Off ("rate_limiter.enabled": false): URSSAF answers 429 beyond its own limit.')], $off);
        static::assertEquals($off, $offWhateverTheService);
        static::assertEquals([new CheckResult('rate limiter', '', CheckStatus::Ok, 'Host limiter "app.limiter".')], $service);
        static::assertEquals([new CheckResult('rate limiter', '', CheckStatus::Ok, '300 per 60 s.')], $builtIn);
        static::assertEquals([new CheckResult('rate limiter', '', CheckStatus::Warning, '301 per 60 s: above the published 300 per minute (ADR 0013).')], $above);
    }

    public function testTheConfigurationIsReportedWithItsEnvironmentAndBaseUri(): void
    {
        $check = new ConfigurationCheck(static fn(): ApiConfiguration => ApiConfiguration::forEnvironment(Environment::Production));

        static::assertEquals(
            [new CheckResult('configuration', '', CheckStatus::Ok, \sprintf('production: %s', Environment::Production->baseUri()))],
            $check->run(self::context()),
        );
    }

    public function testTheAuthenticationIsSkippedWithoutRemoteCalls(): void
    {
        $harness = Harness::boot();
        $check = self::authentication($harness);

        $results = $check->run(CheckContext::withoutRemoteCalls([TenantId::fromString('tenant-1')]));

        static::assertEquals([new CheckResult('authentication', '', CheckStatus::Skipped, 'Not checked (--skip-remote).')], $results);
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_AUTHENTICATION));
    }

    public function testTheAuthenticationAsksAFreshTokenForEveryTenant(): void
    {
        // @mago-expect lint:no-literal-password The secret is an environment placeholder, set by the test kernel.
        $harness = Harness::boot(['credentials' => ['tenant-2' => ['client_id' => 'client-2', 'client_secret' => '%env(URSSAF_TEST_SECRET)%']]]);
        $check = self::authentication($harness);
        $context = CheckContext::withRemoteCalls([TenantId::fromString('tenant-1'), TenantId::fromString('tenant-2')]);

        $first = $check->run($context);
        $second = $check->run($context);

        static::assertEquals([
            new CheckResult('authentication', 'tenant-1', CheckStatus::Ok, 'Access token granted by URSSAF.'),
            new CheckResult('authentication', 'tenant-2', CheckStatus::Ok, 'Access token granted by URSSAF.'),
        ], $first);
        static::assertEquals($first, $second);
        static::assertSame(4, $harness->urssaf->calls(HttpScript::ROUTE_AUTHENTICATION));
    }

    public function testRefusedCredentialsAreAFailureOfTheTenant(): void
    {
        $harness = Harness::boot();
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 401, '{"error":"invalid_client"}');

        $results = self::authentication($harness)->run(CheckContext::withRemoteCalls([TenantId::fromString('tenant-1')]));

        static::assertSame(CheckStatus::Failure, $results[0]->status);
        static::assertSame('tenant-1', $results[0]->subject);
        static::assertStringContainsString('401', $results[0]->detail);
        static::assertStringNotContainsString('secret', $results[0]->detail);
    }

    public function testATenantWithoutCredentialsIsAFailureWithoutCallingUrssaf(): void
    {
        $harness = Harness::boot();

        $results = self::authentication($harness)->run(CheckContext::withRemoteCalls([TenantId::fromString('tenant-9')]));

        static::assertSame(CheckStatus::Failure, $results[0]->status);
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_AUTHENTICATION));
    }

    private static function authentication(Harness $harness): AuthenticationCheck
    {
        $tokens = $harness->privateService(ServiceIds::PROBE_TOKEN_PROVIDER, OAuth2TokenProvider::class);

        return new AuthenticationCheck(
            new InMemoryCredentialsProvider([
                'tenant-1' => Credentials::of('client-1', 'secret-1'),
                'tenant-2' => Credentials::of('client-2', 'secret-2'),
            ]),
            static fn(): OAuth2TokenProvider => $tokens,
        );
    }

    private static function context(): CheckContext
    {
        return CheckContext::withRemoteCalls([TenantId::fromString('tenant-1')]);
    }
}
