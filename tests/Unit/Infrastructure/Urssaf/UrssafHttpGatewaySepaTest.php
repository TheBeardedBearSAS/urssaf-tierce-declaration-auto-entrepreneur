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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknownCause;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireBodyAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;

/**
 * Operations 070, 071 and 075 end to end.
 *
 * @mago-expect lint:too-many-methods
 */
final class UrssafHttpGatewaySepaTest extends TestCase
{
    public function testRegisterSendsTheOfficialBodyWithoutQueryAndMapsTheMandate(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::load('sepa_mandate_response'));

        $mandate = Gateways::gateway($stack)->registerSepaMandate(Stacks::tenant(), WireSamples::siret(), WireSamples::newSepaMandate());

        $request = $stack->lastRequest();
        static::assertSame('POST', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/sepa/mandats', $request->getUri()->getPath());
        static::assertSame('', $request->getUri()->getQuery());
        WireBodyAssertions::assertMatchesFixture('sepa_mandate_add_request', Fixtures::decode((string) $request->getBody()), ['nir']);
        static::assertEquals(SepaMandateResponseMapper::mapRegistered(JsonDecoder::decode(Fixtures::load('sepa_mandate_response'))), $mandate);
        static::assertSame(['tenant-1:070'], $stack->rateLimiter->acquired());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAlreadyExistsBodies(): iterable
    {
        yield 'without body' => [''];
        yield 'with the error' => [Fixtures::load('error_sepa_mandate_already_exists')];
    }

    #[DataProvider('provideAlreadyExistsBodies')]
    public function testA409MeansTheMandateAlreadyExists(string $body): void
    {
        $stack = Gateways::warm();
        $stack->respond(409, $body);

        try {
            Gateways::gateway($stack)->registerSepaMandate(Stacks::tenant(), WireSamples::siret(), WireSamples::newSepaMandate());
            static::fail('Expected a rejection.');
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame(RejectionKind::SepaMandateAlreadyExists, $rejected->kind());
            static::assertSame(409, $rejected->httpStatus());
        }
    }

    public function testRegisterClassifiesA404AndAGeneric400(): void
    {
        $stack = Gateways::warm();
        $stack->respond(404, '');
        $stack->respond(400, Fixtures::load('error_sepa_generic'));
        $gateway = Gateways::gateway($stack);
        $kinds = [];

        foreach ([1, 2] as $_attempt) {
            try {
                $gateway->registerSepaMandate(Stacks::tenant(), WireSamples::siret(), WireSamples::newSepaMandate());
                static::fail('Expected a rejection.');
            } catch (UrssafRequestRejected $rejected) {
                $kinds[] = $rejected->kind();
            }
        }

        static::assertSame([RejectionKind::NotFound, RejectionKind::Technical], $kinds);
    }

    public function testRegisterIsAnUnknownOutcomeOnATransportError(): void
    {
        $stack = Gateways::warm();
        $stack->fail(new TransportFailure('reset'));

        try {
            Gateways::gateway($stack)->registerSepaMandate(Stacks::tenant(), WireSamples::siret(), WireSamples::newSepaMandate());
            static::fail('Expected an unknown outcome.');
        } catch (OutcomeUnknown $unknown) {
            static::assertSame(OutcomeUnknownCause::Transport, $unknown->cause());
        }
    }

    public function testRevokeDeletesTheEncodedPathWithTheEffectiveDate(): void
    {
        $stack = Gateways::warm();
        $stack->respond(204, '');

        Gateways::gateway($stack)->revokeSepaMandate(Stacks::tenant(), WireSamples::revocation());

        $request = $stack->lastRequest();
        static::assertSame('DELETE', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/sepa/mandats/12345678912345/' . WireSamples::RUM, $request->getUri()->getPath());
        static::assertSame('dateEffet=2020-07-20', $request->getUri()->getQuery());
        static::assertSame(['tenant-1:071'], $stack->rateLimiter->acquired());
    }

    public function testRevokeSendsNoQueryWithoutAnEffectiveDateAndAcceptsABlank200(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '');
        $revocation = SepaRevocation::of(WireSamples::siret(), Rum::fromString(WireSamples::RUM), null);

        Gateways::gateway($stack)->revokeSepaMandate(Stacks::tenant(), $revocation);

        static::assertSame('', $stack->lastRequest()->getUri()->getQuery());
    }

    public function testRevokeRefusesA200ObjectAsAnUnknownOutcome(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '{}');

        try {
            Gateways::gateway($stack)->revokeSepaMandate(Stacks::tenant(), WireSamples::revocation());
            static::fail('Expected an unknown outcome.');
        } catch (OutcomeUnknown $unknown) {
            static::assertSame(OutcomeUnknownCause::UnreadableResponse, $unknown->cause());
            static::assertSame(200, $unknown->httpStatus());
        }
    }

    public function testRevokeClassifiesA404(): void
    {
        $stack = Gateways::warm();
        $stack->respond(404, '');

        try {
            Gateways::gateway($stack)->revokeSepaMandate(Stacks::tenant(), WireSamples::revocation());
            static::fail('Expected a rejection.');
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame(RejectionKind::NotFound, $rejected->kind());
        }
    }

    public function testListMapsTheOfficialAnswer(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, Fixtures::load('sepa_mandates_search_response'));

        $mandates = Gateways::gateway($stack)->listSepaMandates(Stacks::tenant(), WireSamples::siret());

        $request = $stack->lastRequest();
        static::assertSame('GET', $request->getMethod());
        static::assertSame('/tiersdecl/v1/ae/sepa/mandats/rechercher', $request->getUri()->getPath());
        static::assertSame('siret=12345678912345', $request->getUri()->getQuery());
        static::assertEquals(SepaMandateResponseMapper::mapList(JsonDecoder::decode(Fixtures::load('sepa_mandates_search_response'))), $mandates);
        static::assertCount(1, $mandates);
        static::assertSame(['tenant-1:075'], $stack->rateLimiter->acquired());
    }

    public function testListAcceptsAnEmptyList(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '[]');

        static::assertSame([], Gateways::gateway($stack)->listSepaMandates(Stacks::tenant(), WireSamples::siret()));
    }

    public function testList404IsNotAnEmptyList(): void
    {
        $stack = Gateways::warm();
        $stack->respond(404, '');

        try {
            Gateways::gateway($stack)->listSepaMandates(Stacks::tenant(), WireSamples::siret());
            static::fail('Expected a rejection.');
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame(RejectionKind::NotFound, $rejected->kind());
        }
    }

    public function testListRefusesABlankBodyAndAnObjectAsUnexpected(): void
    {
        $stack = Gateways::warm();
        $stack->respond(200, '');
        $stack->respond(200, '{"a":1}');
        $gateway = Gateways::gateway($stack);

        foreach ([1, 2] as $_attempt) {
            try {
                $gateway->listSepaMandates(Stacks::tenant(), WireSamples::siret());
                static::fail('Expected an unexpected response.');
            } catch (UnexpectedUrssafResponse $unexpected) {
                static::assertNotSame('', $unexpected->getMessage());
            }
        }
    }

    public function testList500IsUnavailable(): void
    {
        $stack = Gateways::warm();
        $stack->respond(500, '');

        $this->expectException(UrssafUnavailable::class);

        Gateways::gateway($stack)->listSepaMandates(Stacks::tenant(), WireSamples::siret());
    }
}
