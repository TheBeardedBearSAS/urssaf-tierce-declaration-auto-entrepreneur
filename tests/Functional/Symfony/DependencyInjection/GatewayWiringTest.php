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
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\CapturingLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The HTTP gateway of the bundle, end to end through the scripted platform: the configuration reaches the requests, the
 * token, the headers and the logs.
 */
final class GatewayWiringTest extends TestCase
{
    public function testTheAccountReadGoesThroughTheTokenAndTheScriptedPlatform(): void
    {
        $harness = Harness::boot();

        $status = $harness->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class)
            ->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertNotNull($status->periodicity());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_AUTHENTICATION));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
        $request = self::lastRequest($harness);
        static::assertSame('GET', $request->getMethod());
        static::assertSame('api-edi.urssaf.fr', $request->getUri()->getHost());
        static::assertSame('/tiersdecl/v1/ae/comptes', $request->getUri()->getPath());
        static::assertSame('Bearer fictitious-access-token-0001', $request->getHeaderLine('Authorization'));
        static::assertSame(ApiConfiguration::DEFAULT_USER_AGENT, $request->getHeaderLine('User-Agent'));
        static::assertFalse($request->hasHeader('X-Request-Id'));
        static::assertFalse($request->hasHeader('X-Application'));
    }

    public function testTheHostHeadersAndTheBaseUriReachTheRequests(): void
    {
        $harness = Harness::boot([
            'base_uri' => 'https://proxy.example.test',
            'user_agent' => 'my-application/1.0',
            'headers' => ['request_id' => 'X-Request-Id', 'application_id' => ['name' => 'X-Application', 'value' => 'my-application']],
        ]);

        $harness->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class)->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        $request = self::lastRequest($harness);
        static::assertSame('proxy.example.test', $request->getUri()->getHost());
        static::assertSame('my-application/1.0', $request->getHeaderLine('User-Agent'));
        static::assertSame('my-application', $request->getHeaderLine('X-Application'));
        static::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/D', $request->getHeaderLine('X-Request-Id'));
    }

    public function testTheSiretIsLoggedOnlyWhenTheConfigurationAsksForIt(): void
    {
        $quiet = Harness::boot();
        $quiet->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class)->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());
        $verbose = Harness::boot(['log_siret' => true]);
        $verbose->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class)->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        $quietLog = $quiet->privateService('logger', CapturingLogger::class)->dump();
        static::assertStringContainsString('URSSAF call', $quietLog);
        static::assertStringNotContainsString(SimulatorSamples::SIRET, $quietLog);
        static::assertStringContainsString(SimulatorSamples::SIRET, $verbose->privateService('logger', CapturingLogger::class)->dump());
        static::assertTrue($verbose->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class)->logSiret());
        static::assertFalse($quiet->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class)->logSiret());
    }

    public function testTheWritesDeclaredSafeToRetryReachTheApiConfiguration(): void
    {
        $default = Harness::boot()->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);
        $configured = Harness::boot(['retry_safe_writes' => ['030', '050']])->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);

        static::assertSame([], $default->retrySafeWrites());
        static::assertSame([Operation::NotifyMandate, Operation::Declare], $configured->retrySafeWrites());
        static::assertFalse($default->isSafeToRetry(Operation::NotifyMandate));
        static::assertTrue($configured->isSafeToRetry(Operation::NotifyMandate));
        static::assertFalse($configured->isSafeToRetry(Operation::Pay));
    }

    public function testAFailedWriteIsAnUnknownOutcomeUnlessTheHostDeclaredItSafeToRetry(): void
    {
        $default = Harness::boot();
        $default->urssaf->respond(HttpScript::ROUTE_030, 503, '');
        $safe = Harness::boot(['retry_safe_writes' => ['030']]);
        $safe->urssaf->respond(HttpScript::ROUTE_030, 503, '');
        $reference = SimulatorSamples::reference();
        $tenant = SimulatorSamples::tenant();

        try {
            $default->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class)->notifyMandate($tenant, $reference);
            static::fail('The notification must fail.');
        } catch (OutcomeUnknown) {
            static::assertSame(1, $default->urssaf->calls(HttpScript::ROUTE_030));
        }
        try {
            $safe->privateService(TierceDeclarationGateway::class, TierceDeclarationGateway::class)->notifyMandate($tenant, $reference);
            static::fail('The notification must fail.');
        } catch (UrssafUnavailable) {
            static::assertSame(1, $safe->urssaf->calls(HttpScript::ROUTE_030));
        }
    }

    private static function lastRequest(Harness $harness): RequestInterface
    {
        $requests = array_values(array_filter(
            $harness->client->getRequests(),
            static fn(RequestInterface $request): bool => !str_contains($request->getUri()->getPath(), '/oauth/'),
        ));
        $last = end($requests);
        static::assertInstanceOf(RequestInterface::class, $last);

        return $last;
    }
}
