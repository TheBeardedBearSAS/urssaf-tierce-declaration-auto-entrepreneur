<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Clock\FrozenClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\GatewayOptions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafGatewayFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\AccountResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\CapturingLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\SequenceIdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\SteppingClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * The public factory: wiring, defaults of the options and what each option changes (ADR 0013).
 *
 * One method per option.
 *
 * @mago-expect lint:too-many-methods
 */
final class UrssafGatewayFactoryTest extends TestCase
{
    public function testCreateBuildsAWorkingGatewayEndToEnd(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, Fixtures::load('account_response'));

        $gateway = UrssafGatewayFactory::create($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration);

        static::assertEquals(
            AccountResponseMapper::map(JsonDecoder::decode(Fixtures::load('account_response'))),
            $gateway->findAccount(Stacks::tenant(), WireSamples::siret()),
        );
        $requests = $stack->requests();
        static::assertCount(2, $requests);
        static::assertSame('https://api-edi.urssaf.fr/api/oauth/v1/token', (string) $requests[0]->getUri());
        static::assertSame('Bearer fictitious-access-token-0001', $requests[1]->getHeaderLine('Authorization'));
    }

    public function testTheDefaultLimiterIsASlidingWindowOf300CallsPer60SecondsPerTenant(): void
    {
        $stack = $this->stackWithToken();
        $options = GatewayOptions::defaults()->withClock($this->frozenClock());
        $gateway = UrssafGatewayFactory::createWith($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration, $options);

        for ($call = 0; $call < 300; ++$call) {
            $stack->respond(200, Fixtures::load('account_response'));
            $gateway->findAccount(Stacks::tenant(), WireSamples::siret());
        }
        $sent = \count($stack->requests());

        try {
            $gateway->findAccount(Stacks::tenant(), WireSamples::siret());
            static::fail('Expected a rate limit refusal.');
        } catch (RateLimitExceeded $exception) {
            static::assertSame(60, $exception->retryAfterSeconds());
            static::assertSame(Operation::FindAccount, $exception->operation());
        }
        static::assertCount($sent, $stack->requests());
    }

    public function testWithRateLimitConfiguresTheDefaultLimiter(): void
    {
        $stack = $this->stackWithToken();
        $options = GatewayOptions::defaults()->withClock($this->frozenClock())->withRateLimit(2, 10);
        $gateway = UrssafGatewayFactory::createWith($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration, $options);
        $stack->respond(200, Fixtures::load('account_response'));
        $stack->respond(200, Fixtures::load('account_response'));

        $gateway->findAccount(Stacks::tenant(), WireSamples::siret());
        $gateway->findAccount(Stacks::tenant(), WireSamples::siret());
        $sent = \count($stack->requests());

        try {
            $gateway->findAccount(Stacks::tenant(), WireSamples::siret());
            static::fail('Expected a rate limit refusal.');
        } catch (RateLimitExceeded $exception) {
            static::assertSame(10, $exception->retryAfterSeconds());
        }
        static::assertCount($sent, $stack->requests());
    }

    public function testAnExplicitLimiterWinsOverTheRateLimit(): void
    {
        $stack = $this->stackWithToken();
        $options = GatewayOptions::defaults()->withRateLimit(1, 60)->withRateLimiter(new NoopRateLimiter());
        $gateway = UrssafGatewayFactory::createWith($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration, $options);
        $stack->respond(200, Fixtures::load('account_response'));
        $stack->respond(200, Fixtures::load('account_response'));

        $gateway->findAccount(Stacks::tenant(), WireSamples::siret());
        $gateway->findAccount(Stacks::tenant(), WireSamples::siret());

        static::assertCount(3, $stack->requests());
    }

    public function testAnInjectedClockIsTheOneUsed(): void
    {
        $stack = $this->stackWithToken();
        $clock = new SteppingClock(1);
        $options = GatewayOptions::defaults()->withClock($clock);
        $gateway = UrssafGatewayFactory::createWith($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration, $options);
        $stack->respond(200, Fixtures::load('account_response'));
        $before = (int) $clock->now()->format('Uu');

        $gateway->findAccount(Stacks::tenant(), WireSamples::siret());

        // Without a call of the gateway on this clock, the next reading would follow the first one directly.
        static::assertGreaterThan($before + 1, (int) $clock->now()->format('Uu'));
    }

    public function testTheIdGeneratorFeedsTheRequestIdHeader(): void
    {
        $configuration = new ApiConfiguration(Environment::Sandbox, null, 'x-request-id', null);
        $stack = Stacks::configured($configuration);
        $stack->respondToken();
        $stack->respond(200, Fixtures::load('account_response'));
        $options = GatewayOptions::defaults()->withIdGenerator(new SequenceIdGenerator());

        UrssafGatewayFactory::createWith($stack->transport(), $stack->credentials, $stack->tokens, $configuration, $options)
            ->findAccount(Stacks::tenant(), WireSamples::siret());

        static::assertSame('request-id-1', $stack->lastRequest()->getHeaderLine('x-request-id'));
    }

    public function testTheDefaultIdGeneratorProducesRandomUuids(): void
    {
        $configuration = new ApiConfiguration(Environment::Sandbox, null, 'x-request-id', null);
        $stack = Stacks::configured($configuration);
        $stack->respondToken();
        $stack->respond(200, Fixtures::load('account_response'));

        UrssafGatewayFactory::create($stack->transport(), $stack->credentials, $stack->tokens, $configuration)
            ->findAccount(Stacks::tenant(), WireSamples::siret());

        static::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $stack->lastRequest()->getHeaderLine('x-request-id'),
        );
    }

    public function testTheLoggerReceivesTheTokenTheCallAndTheShapeLines(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, Fixtures::load('estimate_response'));
        $logger = new CapturingLogger();
        $options = GatewayOptions::defaults()->withLogger($logger);

        UrssafGatewayFactory::createWith($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration, $options)
            ->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());

        static::assertSame(
            ['URSSAF token request.', 'URSSAF call.', 'URSSAF response shape.'],
            array_column($logger->records(), 'message'),
        );
        static::assertSame([], $stack->logger->records());
    }

    public function testTheTokenCacheIsSharedThroughTheGivenStore(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, Fixtures::load('account_response'));
        $stack->respond(200, Fixtures::load('account_response'));

        $first = UrssafGatewayFactory::create($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration);
        $second = UrssafGatewayFactory::create($stack->transport(), $stack->credentials, $stack->tokens, $stack->configuration);
        $first->findAccount(Stacks::tenant(), WireSamples::siret());
        $second->findAccount(Stacks::tenant(), WireSamples::siret());

        static::assertCount(3, $stack->requests());
        static::assertCount(1, $stack->tokens->keys());
        static::assertStringStartsWith('urssaf_tdae.', $stack->tokens->keys()[0]);
    }

    private function stackWithToken(): Stack
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();

        return $stack;
    }

    private function frozenClock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2024-01-01T10:00:00', new DateTimeZone('UTC')));
    }
}
