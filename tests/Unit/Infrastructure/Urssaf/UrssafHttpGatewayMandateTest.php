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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\RetryMatrix;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * Operations 030 and 031 end to end: the request has a query and no body, the answer is empty.
 */
final class UrssafHttpGatewayMandateTest extends TestCase
{
    public function testNotifyPostsTheSiretAndTheBirthDateWithoutABody(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '');

        Gateways::gateway($stack)->notifyMandate(Stacks::tenant(), WireSamples::accountReference());

        $request = $stack->lastRequest();
        static::assertSame('POST', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/mandats', $request->getUri()->getPath());
        static::assertSame('siret=12345678901234&dateNaissance=1975-10-01', $request->getUri()->getQuery());
        static::assertSame('', (string) $request->getBody());
        static::assertFalse($request->hasHeader('Content-Type'));
        static::assertSame(['tenant-1:030'], $stack->rateLimiter->acquired());
    }

    public function testCancelDeletesTheSiretAndTheBirthDateWithoutABody(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '{}');

        Gateways::gateway($stack)->cancelMandate(Stacks::tenant(), WireSamples::accountReference());

        $request = $stack->lastRequest();
        static::assertSame('DELETE', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/mandats', $request->getUri()->getPath());
        static::assertSame('siret=12345678901234&dateNaissance=1975-10-01', $request->getUri()->getQuery());
        static::assertSame('', (string) $request->getBody());
        static::assertFalse($request->hasHeader('Content-Type'));
        static::assertSame(['tenant-1:031'], $stack->rateLimiter->acquired());
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function provideMandateOperations(): iterable
    {
        yield '030' => [Operation::NotifyMandate];
        yield '031' => [Operation::CancelMandate];
    }

    #[DataProvider('provideMandateOperations')]
    public function testBothAnEmptyBodyAndAnEmptyObjectSucceed(Operation $operation): void
    {
        foreach (['', '{}'] as $body) {
            $stack = Gateways::warm();
            $stack->respond(200, $body);

            static::assertNull(Gateways::call(Gateways::gateway($stack), $operation));
        }
    }

    #[DataProvider('provideMandateOperations')]
    public function testHeldElsewhereIsClassified(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(400, Fixtures::load('error_siret_deja_mandate'));

        try {
            Gateways::call(Gateways::gateway($stack), $operation);
            static::fail('Expected a rejection.');
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame(RejectionKind::MandateHeldElsewhere, $rejected->kind());
        }
    }

    #[DataProvider('provideMandateOperations')]
    public function testATransportErrorAndA500AreAnUnknownOutcome(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->fail(new TransportFailure('reset'));
        $stack->respond(500, '');
        $gateway = Gateways::gateway($stack);
        $causes = [];

        foreach ([1, 2] as $_attempt) {
            try {
                Gateways::call($gateway, $operation);
                static::fail('Expected an unknown outcome.');
            } catch (OutcomeUnknown $unknown) {
                $causes[] = $unknown->cause();
            }
        }

        static::assertSame([OutcomeUnknownCause::Transport, OutcomeUnknownCause::ServerError], $causes);
    }

    public function testAListedNotifyIsRetryableOnATransportError(): void
    {
        $stack = Gateways::warmWith(RetryMatrix::configuration([Operation::NotifyMandate]));
        $stack->fail(new TransportFailure('reset'));

        try {
            Gateways::gateway($stack)->notifyMandate(Stacks::tenant(), WireSamples::accountReference());
            static::fail('Expected an unavailable error.');
        } catch (UrssafUnavailable $unavailable) {
            static::assertTrue($unavailable->mayHaveReachedUrssaf());
        }
    }

    /**
     * @return iterable<string, array{Operation, ApiConfiguration}>
     */
    public static function provideUnreadableBodies(): iterable
    {
        foreach ([Operation::NotifyMandate, Operation::CancelMandate] as $operation) {
            yield $operation->value . ' unlisted' => [$operation, RetryMatrix::configuration([])];
            yield $operation->value . ' listed' => [$operation, RetryMatrix::configuration([$operation])];
        }
    }

    #[DataProvider('provideUnreadableBodies')]
    public function testABodyThatIsNotEmptyIsAnUnknownOutcomeListedOrNot(Operation $operation, ApiConfiguration $configuration): void
    {
        $stack = Gateways::warmWith($configuration);
        $stack->respond(200, '{"x":1}');
        $stack->respond(200, '[]');
        $gateway = Gateways::gateway($stack);

        foreach ([1, 2] as $_attempt) {
            try {
                Gateways::call($gateway, $operation);
                static::fail('Expected an unknown outcome.');
            } catch (OutcomeUnknown $unknown) {
                static::assertSame(OutcomeUnknownCause::UnreadableResponse, $unknown->cause());
                static::assertSame(200, $unknown->httpStatus());
                static::assertSame($operation, $unknown->operation());
            }
        }
    }
}
