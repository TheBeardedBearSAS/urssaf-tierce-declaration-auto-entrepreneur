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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknownCause;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\RetryMatrix;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireBodyAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * Operation 050 end to end: an unspecified write, never resent unless declared safe, but an unreadable 2xx is an unknown
 * outcome even then.
 */
final class UrssafHttpGatewayDeclareTest extends TestCase
{
    public function testItSendsTheOfficialBodyAndMapsTheDirectAnswer(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::load('declare_response'));

        $result = Gateways::gateway($stack)->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());

        $request = $stack->lastRequest();
        static::assertSame('POST', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/declarer', $request->getUri()->getPath());
        static::assertSame('siret=12345678912345', $request->getUri()->getQuery());
        WireBodyAssertions::assertMatchesFixture('declare_request', Fixtures::decode((string) $request->getBody()));
        static::assertEquals(DeclarationResponseMapper::map(JsonDecoder::decode(Fixtures::load('declare_response'))), $result);
        static::assertSame(['tenant-1:050'], $stack->rateLimiter->acquired());
    }

    public function testAWrappedAnswerMapsTheSameAndLogsTheShape(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::wrapped('declare_response', ResponseEnvelope::ESTIMATE_OR_DECLARATION));

        $result = Gateways::gateway($stack)->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());

        static::assertEquals(DeclarationResponseMapper::map(JsonDecoder::decode(Fixtures::load('declare_response'))), $result);
        $records = $stack->logger->records();
        static::assertSame('URSSAF response shape.', $records[1]['message']);
        static::assertSame('debug', $records[1]['level']);
        static::assertSame(['operation' => '050', 'shape' => 'wrapped'], $records[1]['context']);
    }

    /**
     * @return iterable<string, array{string, RejectionKind}>
     */
    public static function provideBusinessErrors(): iterable
    {
        yield 'period not open' => ['error_appel_inconnu', RejectionKind::PeriodNotOpen];
        yield 'periodicity mismatch' => ['error_periodicite_invalide', RejectionKind::PeriodicityMismatch];
    }

    #[DataProvider('provideBusinessErrors')]
    public function testBusinessErrorsAreClassified(string $fixture, RejectionKind $kind): void
    {
        $stack = Gateways::warm();
        $stack->respond(400, Fixtures::load($fixture));

        try {
            Gateways::gateway($stack)->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());
            static::fail('Expected a rejection.');
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame($kind, $rejected->kind());
        }
    }

    public function testATransportErrorA500AndA512AreAnUnknownOutcome(): void
    {
        $stack = Gateways::warm();
        $stack->fail(new TransportFailure('reset'));
        $stack->respond(500, '');
        $stack->respond(512, '');
        $gateway = Gateways::gateway($stack);
        $causes = [];

        foreach ([1, 2, 3] as $_attempt) {
            try {
                $gateway->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());
                static::fail('Expected an unknown outcome.');
            } catch (OutcomeUnknown $unknown) {
                $causes[] = $unknown->cause();
            }
        }

        static::assertSame(
            [OutcomeUnknownCause::Transport, OutcomeUnknownCause::ServerError, OutcomeUnknownCause::ServerError],
            $causes,
        );
    }

    public function testAListedDeclareIsRetryableOnATransportErrorAndA503(): void
    {
        $stack = Gateways::warmWith(RetryMatrix::configuration([Operation::Declare]));
        $stack->fail(new TransportFailure('reset'));
        $stack->respond(503, '');
        $gateway = Gateways::gateway($stack);

        foreach ([1, 2] as $_attempt) {
            try {
                $gateway->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());
                static::fail('Expected an unavailable error.');
            } catch (UrssafUnavailable $unavailable) {
                static::assertTrue($unavailable->mayHaveReachedUrssaf());
            }
        }
    }

    public function testAListedDeclareStillTreatsA501AsUnexpected(): void
    {
        $stack = Gateways::warmWith(RetryMatrix::configuration([Operation::Declare]));
        $stack->respond(501, '');

        $this->expectException(UnexpectedUrssafResponse::class);

        Gateways::gateway($stack)->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());
    }

    /**
     * @return iterable<string, array{ApiConfiguration}>
     */
    public static function provideListing(): iterable
    {
        yield 'unlisted' => [RetryMatrix::configuration([])];
        yield 'listed' => [RetryMatrix::configuration([Operation::Declare])];
    }

    #[DataProvider('provideListing')]
    public function testAnUnreadable2xxIsAnUnknownOutcomeListedOrNot(ApiConfiguration $configuration): void
    {
        $stack = Gateways::warmWith($configuration);
        $stack->respond(200, 'not json');
        $stack->respond(200, '{"OutputDeclaration":null}');
        $gateway = Gateways::gateway($stack);

        foreach ([1, 2] as $_attempt) {
            try {
                $gateway->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());
                static::fail('Expected an unknown outcome.');
            } catch (OutcomeUnknown $unknown) {
                static::assertSame(OutcomeUnknownCause::UnreadableResponse, $unknown->cause());
                static::assertSame(200, $unknown->httpStatus());
                static::assertSame(Operation::Declare, $unknown->operation());
            }
        }
    }

    public function testA429IsRetryable(): void
    {
        $stack = Gateways::warm();
        $stack->respond(429, '', ['Retry-After' => '7']);

        try {
            Gateways::gateway($stack)->declareTurnover(Stacks::tenant(), WireSamples::siret(), WireSamples::declaration());
            static::fail('Expected an unavailable error.');
        } catch (UrssafUnavailable $unavailable) {
            static::assertTrue($unavailable->isQuotaExceeded());
            static::assertFalse($unavailable->mayHaveReachedUrssaf());
            static::assertSame(7, $unavailable->retryAfterSeconds());
        }
    }
}
