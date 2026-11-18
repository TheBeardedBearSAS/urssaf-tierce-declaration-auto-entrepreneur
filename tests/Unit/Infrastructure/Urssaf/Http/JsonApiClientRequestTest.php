<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpHeader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\SequenceIdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ApiRequests;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ClientCalls;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;

/**
 * Shape of the HTTP requests of the client (ADR 0002).
 *
 * One method per case.
 *
 * @mago-expect lint:too-many-methods
 */
final class JsonApiClientRequestTest extends TestCase
{
    private const BASE = 'https://api-edi.urssaf.fr/tiersdecl/v1/ae';

    private function send(Stack $stack, ApiRequest $request): void
    {
        $stack->jsonClient()->send(Stacks::tenant(), $stack->tenantCredentials(), $request);
    }

    /**
     * @return iterable<string, array{Operation, string, string}>
     */
    public static function provideShapesWithoutBody(): iterable
    {
        yield '020 GET with a query' => [Operation::FindAccount, 'GET', '/comptes?siret=12345678912345'];
        yield '030 POST with a query only' => [
            Operation::NotifyMandate,
            'POST',
            '/mandats?siret=12345678901234&dateNaissance=1975-10-01',
        ];
        yield '031 DELETE with a query' => [
            Operation::CancelMandate,
            'DELETE',
            '/mandats?siret=12345678901234&dateNaissance=1975-10-01',
        ];
        yield '071 DELETE with a path and a query' => [
            Operation::RevokeSepaMandate,
            'DELETE',
            '/sepa/mandats/12345678912345/T1234567891234567891234568912345678?dateEffet=2020-07-20',
        ];
        yield '075 GET with a query' => [
            Operation::ListSepaMandates,
            'GET',
            '/sepa/mandats/rechercher?siret=12345678912345',
        ];
    }

    /**
     * @return iterable<string, array{Operation, string, string}>
     */
    public static function provideShapesWithBody(): iterable
    {
        yield '040 POST with a query and a body' => [Operation::Estimate, 'POST', '/estimer?siret=12345678912345'];
        yield '050 POST with a query and a body' => [Operation::Declare, 'POST', '/declarer?siret=12345678912345'];
        yield '060 POST with a query and a body' => [Operation::Pay, 'POST', '/payer?siret=12345678912345'];
        yield '070 POST with a body only' => [Operation::RegisterSepaMandate, 'POST', '/sepa/mandats'];
    }

    #[DataProvider('provideShapesWithoutBody')]
    public function testItBuildsTheRequestOfAnOperationWithoutBody(Operation $operation, string $method, string $target): void
    {
        $request = $this->requestOf($operation);

        static::assertSame($method, $request->getMethod());
        static::assertSame(self::BASE . $target, (string) $request->getUri());
        static::assertFalse($request->hasHeader('Content-Type'));
        static::assertSame('', (string) $request->getBody());
        $this->assertContractHeaders($request);
    }

    #[DataProvider('provideShapesWithBody')]
    public function testItBuildsTheRequestOfAnOperationWithABody(Operation $operation, string $method, string $target): void
    {
        $request = $this->requestOf($operation);

        static::assertSame($method, $request->getMethod());
        static::assertSame(self::BASE . $target, (string) $request->getUri());
        static::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        static::assertSame(ApiRequests::of($operation)->jsonBody(), (string) $request->getBody());
        static::assertNotSame('', (string) $request->getBody());
        $this->assertContractHeaders($request);
    }

    private function requestOf(Operation $operation): RequestInterface
    {
        $stack = ClientCalls::stack();
        $stack->respond(200, '{}');

        ClientCalls::send($stack, $operation);

        return $stack->lastRequest();
    }

    private function assertContractHeaders(RequestInterface $request): void
    {
        static::assertSame('Bearer fictitious-access-token-0001', $request->getHeaderLine('Authorization'));
        static::assertSame('application/json', $request->getHeaderLine('Accept'));
        static::assertSame(ApiConfiguration::DEFAULT_USER_AGENT, $request->getHeaderLine('User-Agent'));
    }

    public function testItSendsOnlyTheContractHeadersByDefault(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::FindAccount);

        static::assertSame(
            ['Host', 'Authorization', 'Accept', 'User-Agent'],
            array_keys($stack->lastRequest()->getHeaders()),
        );
    }

    public function testItEncodesTheQueryAsRfc3986(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(200, '{}');

        $this->send($stack, ApiRequest::get(Operation::FindAccount, '/comptes', [
            'a' => 'D ARTAGNAN',
            'b' => "L'\u{E9}",
            'c' => 'a+b',
        ]));

        static::assertSame('a=D%20ARTAGNAN&b=L%27%C3%A9&c=a%2Bb', $stack->lastRequest()->getUri()->getQuery());
    }

    public function testItOmitsTheQuerySeparatorWithoutAQuery(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(200, '{}');

        $this->send($stack, ApiRequest::post(Operation::RegisterSepaMandate, '/sepa/mandats', [], ['k' => 1]));

        static::assertSame(self::BASE . '/sepa/mandats', (string) $stack->lastRequest()->getUri());
    }

    public function testThePathIsUsedVerbatimAndNeverEncodedAgain(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(204, '');

        $this->send($stack, ApiRequest::delete(Operation::RevokeSepaMandate, '/sepa/mandats/1234%2F56/R%20UM'));

        static::assertSame(self::BASE . '/sepa/mandats/1234%2F56/R%20UM', (string) $stack->lastRequest()->getUri());
    }

    public function testItAddsAFreshRequestIdPerCallAndTheApplicationIdWhenConfigured(): void
    {
        $stack = ClientCalls::stackWith(new ApiConfiguration(
            Environment::Sandbox,
            null,
            'x-request-id',
            HttpHeader::of('recouv.application.id', 'app-42'),
        ));
        $stack->respond(200, '{}');
        $stack->respond(200, '{}');

        $first = $stack->jsonClient()->send(Stacks::tenant(), $stack->tenantCredentials(), ApiRequests::of(Operation::FindAccount));
        $firstRequest = $stack->lastRequest();
        $second = $stack->jsonClient()->send(Stacks::tenant(), $stack->tenantCredentials(), ApiRequests::of(Operation::FindAccount));

        static::assertSame('request-id-1', $firstRequest->getHeaderLine('x-request-id'));
        static::assertSame('app-42', $firstRequest->getHeaderLine('recouv.application.id'));
        static::assertSame('request-id-1', $first->requestId());
        static::assertSame('request-id-2', $stack->lastRequest()->getHeaderLine('x-request-id'));
        static::assertSame('request-id-2', $second->requestId());
    }

    public function testItSendsNoRequestIdByDefault(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(200, '{}');

        $response = ClientCalls::send($stack, Operation::FindAccount);

        static::assertNull($response->requestId());
        static::assertSame('request-id-1', $stack->ids->generate());
    }

    public function testItAddsTheApplicationIdAloneWhenNoRequestIdIsConfigured(): void
    {
        $stack = ClientCalls::stackWith(new ApiConfiguration(
            Environment::Sandbox,
            null,
            null,
            HttpHeader::of('recouv.application.id', 'app-42'),
        ));
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::FindAccount);

        $request = $stack->lastRequest();
        static::assertSame('app-42', $request->getHeaderLine('recouv.application.id'));
        static::assertFalse($request->hasHeader('x-request-id'));
    }

    public function testItUsesAConfiguredRequestIdHeaderName(): void
    {
        $stack = ClientCalls::stackWith(new ApiConfiguration(Environment::Sandbox, null, 'x-correlation', null));
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::FindAccount);

        $request = $stack->lastRequest();
        static::assertSame('request-id-1', $request->getHeaderLine('x-correlation'));
        static::assertFalse($request->hasHeader('x-request-id'));
        static::assertFalse($request->hasHeader('recouv.application.id'));
    }

    public function testAnInvalidGeneratedRequestIdIsRefusedBeforeAnythingIsSent(): void
    {
        $stack = ClientCalls::stackWith(new ApiConfiguration(Environment::Sandbox, null, 'x-request-id', null));
        $sent = \count($stack->requests());

        try {
            $stack->jsonClientWithIds(new SequenceIdGenerator("bad\nid-"))->send(
                Stacks::tenant(),
                $stack->tenantCredentials(),
                ApiRequests::of(Operation::FindAccount),
            );
            static::fail('An invalid request id must be refused.');
        } catch (InvalidValue $exception) {
            static::assertSame(HttpHeader::VALUE_INVALID, $exception->violationCode());
        }

        static::assertCount($sent, $stack->requests());
    }

    public function testItUsesTheProductionHostTheUserAgentAndTheBaseUriOverride(): void
    {
        $production = ClientCalls::stackWith(ApiConfiguration::forEnvironment(Environment::Production));
        $production->respond(200, '{}');
        ClientCalls::send($production, Operation::FindAccount);

        $custom = ClientCalls::stackWith(new ApiConfiguration(
            Environment::Sandbox,
            'https://proxy.example.test/base/',
            null,
            null,
            60,
            'custom-agent/1.0',
        ));
        $custom->respond(200, '{}');
        ClientCalls::send($custom, Operation::FindAccount);

        static::assertSame(
            'https://api.urssaf.fr/tiersdecl/v1/ae/comptes?siret=12345678912345',
            (string) $production->lastRequest()->getUri(),
        );
        static::assertSame(
            'https://proxy.example.test/base/tiersdecl/v1/ae/comptes?siret=12345678912345',
            (string) $custom->lastRequest()->getUri(),
        );
        static::assertSame('custom-agent/1.0', $custom->lastRequest()->getHeaderLine('User-Agent'));
    }
}
