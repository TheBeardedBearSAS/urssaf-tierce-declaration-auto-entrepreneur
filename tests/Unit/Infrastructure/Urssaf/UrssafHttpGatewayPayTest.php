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

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknownCause;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\RejectingRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireBodyAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * Operation 060 end to end: never resent, whatever the configuration.
 */
final class UrssafHttpGatewayPayTest extends TestCase
{
    public function testItSendsTheOfficialBodyAndMapsTheDirectAnswer(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::load('pay_response'));

        $result = Gateways::gateway($stack)->payContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::paymentOrder());

        $request = $stack->lastRequest();
        static::assertSame('POST', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/payer', $request->getUri()->getPath());
        static::assertSame('siret=12345678912345', $request->getUri()->getQuery());
        WireBodyAssertions::assertMatchesFixture('pay_request', Fixtures::decode((string) $request->getBody()));
        static::assertEquals(PaymentResponseMapper::map(JsonDecoder::decode(Fixtures::load('pay_response'))), $result);
        static::assertSame(['tenant-1:060'], $stack->rateLimiter->acquired());
    }

    public function testAWrappedAnswerMapsTheSameAndLogsTheShape(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::wrapped('pay_response', ResponseEnvelope::PAYMENT));

        $result = Gateways::gateway($stack)->payContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::paymentOrder());

        static::assertEquals(PaymentResponseMapper::map(JsonDecoder::decode(Fixtures::load('pay_response'))), $result);
        $records = $stack->logger->records();
        static::assertSame(['operation' => '060', 'shape' => 'wrapped'], $records[1]['context']);
        static::assertSame('URSSAF response shape.', $records[1]['message']);
    }

    public function testEveryFailureThatMayHaveReachedUrssafIsAnUnknownOutcome(): void
    {
        $stack = Gateways::warm();
        $stack->fail(new TransportFailure('reset'));
        foreach ([500, 502, 599] as $status) {
            $stack->respond($status, '');
        }
        $stack->respond(200, 'not json');
        $gateway = Gateways::gateway($stack);
        $causes = [];

        foreach ([1, 2, 3, 4, 5] as $_attempt) {
            try {
                $gateway->payContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::paymentOrder());
                static::fail('Expected an unknown outcome.');
            } catch (OutcomeUnknown $unknown) {
                $causes[] = $unknown->cause();
            }
        }

        static::assertSame(
            [
                OutcomeUnknownCause::Transport,
                OutcomeUnknownCause::ServerError,
                OutcomeUnknownCause::ServerError,
                OutcomeUnknownCause::ServerError,
                OutcomeUnknownCause::UnreadableResponse,
            ],
            $causes,
        );
    }

    public function testA429DidNotReachUrssaf(): void
    {
        $stack = Gateways::warm();
        $stack->respond(429, '');

        try {
            Gateways::gateway($stack)->payContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::paymentOrder());
            static::fail('Expected an unavailable error.');
        } catch (UrssafUnavailable $unavailable) {
            static::assertFalse($unavailable->mayHaveReachedUrssaf());
            static::assertTrue($unavailable->isQuotaExceeded());
        }
    }

    public function testA401IsReplayedOnce(): void
    {
        $stack = Gateways::warm();
        $stack->respond(401, '');
        $stack->respondToken();
        $stack->respond(200, Fixtures::load('pay_response'));

        Gateways::gateway($stack)->payContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::paymentOrder());

        $paths = array_map(static fn(RequestInterface $request): string => $request->getUri()->getPath(), $stack->requests());
        static::assertSame(
            ['/api/oauth/v1/token', '/tiersdecl/v1/ae/payer', '/api/oauth/v1/token', '/tiersdecl/v1/ae/payer'],
            $paths,
        );
    }

    public function testATokenFailureIsNeverAnUnknownOutcome(): void
    {
        $stack = Stacks::sandbox();
        $stack->respond(503, '');

        try {
            Gateways::gateway($stack)->payContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::paymentOrder());
            static::fail('Expected an unavailable error.');
        } catch (UrssafUnavailable $unavailable) {
            static::assertTrue($unavailable->whileAuthenticating());
            static::assertFalse($unavailable->mayHaveReachedUrssaf());
            static::assertSame(Operation::Pay, $unavailable->operation());
        }
    }

    public function testTheLocalLimiterRefusesWithoutAnyRequest(): void
    {
        $stack = Stacks::sandbox();

        try {
            Gateways::withRateLimiter($stack, new RejectingRateLimiter())->payContributions(
                Stacks::tenant(),
                WireSamples::siret(),
                WireSamples::paymentOrder(),
            );
            static::fail('Expected a rate limit refusal.');
        } catch (RateLimitExceeded) {
            static::assertSame([], $stack->requests());
        }
    }
}
