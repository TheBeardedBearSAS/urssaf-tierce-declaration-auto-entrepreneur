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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EstimateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireBodyAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * Operation 040 end to end: a POST with a query and a body, direct or wrapped answer.
 */
final class UrssafHttpGatewayEstimateTest extends TestCase
{
    public function testItSendsTheOfficialBodyAndMapsTheDirectAnswer(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::load('estimate_response'));

        $estimate = Gateways::gateway($stack)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());

        $request = $stack->lastRequest();
        static::assertSame('POST', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/estimer', $request->getUri()->getPath());
        static::assertSame('siret=12345678912345', $request->getUri()->getQuery());
        static::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        WireBodyAssertions::assertMatchesFixture('estimate_request', Fixtures::decode((string) $request->getBody()));
        static::assertEquals(EstimateResponseMapper::map(JsonDecoder::decode(Fixtures::load('estimate_response'))), $estimate);
        static::assertSame(['tenant-1:040'], $stack->rateLimiter->acquired());
    }

    public function testDirectAndWrappedAnswersMapIdenticallyAndLogTheShape(): void
    {
        $direct = Gateways::warm();
        $direct->respond(200, Fixtures::load('estimate_response'));
        $wrapped = Gateways::warm();
        $wrapped->respond(200, Fixtures::wrapped('estimate_response', ResponseEnvelope::ESTIMATE_OR_DECLARATION));

        $fromDirect = Gateways::gateway($direct)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());
        $fromWrapped = Gateways::gateway($wrapped)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());

        static::assertEquals($fromDirect, $fromWrapped);
        static::assertSame(
            [['level' => 'debug', 'message' => 'URSSAF response shape.', 'context' => ['operation' => '040', 'shape' => 'direct']]],
            $this->shapeRecords($direct->logger->records()),
        );
        static::assertSame(
            [['level' => 'debug', 'message' => 'URSSAF response shape.', 'context' => ['operation' => '040', 'shape' => 'wrapped']]],
            $this->shapeRecords($wrapped->logger->records()),
        );
    }

    public function testAMissingAmountsFieldIsUnexpected(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '{"autre":1}');

        $this->expectException(UnexpectedUrssafResponse::class);

        Gateways::gateway($stack)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());
    }

    public function testAnEmptyWrapperIsUnexpected(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '{"OutputDeclaration":{}}');

        $this->expectException(UnexpectedUrssafResponse::class);

        Gateways::gateway($stack)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());
    }

    public function testNoShapeIsLoggedWhenTheAnswerCannotBeRead(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '{"autre":1}');

        try {
            Gateways::gateway($stack)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());
        } catch (UnexpectedUrssafResponse) {
            static::assertSame([], $this->shapeRecords($stack->logger->records()));
        }
    }

    /**
     * @return iterable<string, array{string, RejectionKind}>
     */
    public static function provideBusinessErrors(): iterable
    {
        yield 'unknown mandate' => ['error_mdt_inconnu', RejectionKind::MandateMissing];
        yield 'mandate out of period' => ['error_mdt_hors_periode', RejectionKind::MandateOutOfPeriod];
    }

    #[DataProvider('provideBusinessErrors')]
    public function testBusinessErrorsAreClassified(string $fixture, RejectionKind $kind): void
    {
        $stack = Gateways::warm();
        $stack->respond(400, Fixtures::load($fixture));

        try {
            Gateways::gateway($stack)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());
            static::fail('Expected a rejection.');
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame($kind, $rejected->kind());
        }
    }

    public function testA503IsUnavailable(): void
    {
        $stack = Gateways::warm();
        $stack->respond(503, '');

        $this->expectException(UrssafUnavailable::class);

        Gateways::gateway($stack)->estimateContributions(Stacks::tenant(), WireSamples::siret(), WireSamples::turnover());
    }

    /**
     * @param list<array{level: string, message: string, context: array<array-key, mixed>}> $records
     *
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    private function shapeRecords(array $records): array
    {
        return array_values(array_filter($records, static fn(array $record): bool => $record['message'] === 'URSSAF response shape.'));
    }
}
