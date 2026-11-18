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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\AccountResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * Operation 020 end to end: the request, the mapped answer and the business errors.
 */
final class UrssafHttpGatewayAccountTest extends TestCase
{
    public function testItMapsTheOfficialAnswerAndSendsAGetWithTheSiret(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::load('account_response'));

        $status = Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());

        static::assertEquals(AccountResponseMapper::map(JsonDecoder::decode(Fixtures::load('account_response'))), $status);
        $request = $stack->lastRequest();
        static::assertSame('GET', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/comptes', $request->getUri()->getPath());
        static::assertSame('siret=12345678912345', $request->getUri()->getQuery());
        static::assertSame(['tenant-1:020'], $stack->rateLimiter->acquired());
    }

    /**
     * @return iterable<string, array{string, RejectionKind}>
     */
    public static function provideBusinessErrors(): iterable
    {
        yield 'unknown account' => ['error_cpte_inconnu', RejectionKind::AccountUnknown];
        yield 'no active user' => ['error_abn_inconnu', RejectionKind::NoActiveUser];
        yield 'invalid parameter' => ['error_param_invalide', RejectionKind::InvalidParameter];
        yield 'technical' => ['error_technique', RejectionKind::Technical];
    }

    #[DataProvider('provideBusinessErrors')]
    public function testA400IsClassified(string $fixture, RejectionKind $kind): void
    {
        $stack = Gateways::warm();
        $stack->respond(400, Fixtures::load($fixture));

        try {
            Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());
            static::fail('Expected a rejection.');
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame($kind, $rejected->kind());
            static::assertSame(400, $rejected->httpStatus());
            static::assertSame(Operation::FindAccount, $rejected->operation());
        }
    }

    public function testA401IsReplayedOnceThenSucceeds(): void
    {
        $stack = Gateways::warm();
        $stack->respond(401, '');
        $stack->respondToken();
        $stack->respond(200, Fixtures::load('account_response'));

        Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());

        static::assertCount(4, $stack->requests());
        static::assertSame(['tenant-1:020', 'tenant-1:020'], $stack->rateLimiter->acquired());
    }

    public function testA403IsAnAuthenticationFailure(): void
    {
        $stack = Gateways::warm();
        $stack->respond(403, '');

        $this->expectException(UrssafAuthenticationFailed::class);

        Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());
    }

    public function testA429IsRetryableWithTheRetryAfterDelay(): void
    {
        $stack = Gateways::warm();
        $stack->respond(429, '', ['Retry-After' => '30']);

        try {
            Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());
            static::fail('Expected an unavailable error.');
        } catch (UrssafUnavailable $unavailable) {
            static::assertTrue($unavailable->isQuotaExceeded());
            static::assertSame(30, $unavailable->retryAfterSeconds());
            static::assertFalse($unavailable->mayHaveReachedUrssaf());
        }
    }

    public function testA500IsUnavailableAndMayHaveReachedUrssaf(): void
    {
        $stack = Gateways::warm();
        $stack->respond(500, '');

        try {
            Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());
            static::fail('Expected an unavailable error.');
        } catch (UrssafUnavailable $unavailable) {
            static::assertTrue($unavailable->mayHaveReachedUrssaf());
            static::assertSame(500, $unavailable->httpStatus());
        }
    }

    public function testATransportErrorIsUnavailableAndMayHaveReachedUrssaf(): void
    {
        $stack = Gateways::warm();
        $stack->fail(new TransportFailure('connection reset'));

        try {
            Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());
            static::fail('Expected an unavailable error.');
        } catch (UrssafUnavailable $unavailable) {
            static::assertTrue($unavailable->mayHaveReachedUrssaf());
            static::assertNull($unavailable->httpStatus());
        }
    }

    public function testA501AndAnUnreadableAnswerAreUnexpected(): void
    {
        $stack = Gateways::warm();
        $stack->respond(501, '');
        $stack->respond(200, 'not json');
        $gateway = Gateways::gateway($stack);
        $failures = [];

        foreach ([1, 2] as $_attempt) {
            try {
                $gateway->findAccount(Stacks::tenant(), WireSamples::siret());
                static::fail('Expected an unexpected response.');
            } catch (UnexpectedUrssafResponse $unexpected) {
                $failures[] = $unexpected->getMessage();
            }
        }

        static::assertCount(2, $failures);
    }

    public function testA404IsUnexpectedOutsideSepa(): void
    {
        $stack = Gateways::warm();
        $stack->respond(404, '');

        $this->expectException(UnexpectedUrssafResponse::class);

        Gateways::gateway($stack)->findAccount(Stacks::tenant(), WireSamples::siret());
    }
}
