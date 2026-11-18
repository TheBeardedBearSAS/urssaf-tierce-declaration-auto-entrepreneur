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

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ClientCalls;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;
use Throwable;

/**
 * Log lines of the client (ADR 0002, ADR 0006, ADR 0010, ADR 0012): exact levels, messages and context keys, and no
 * personal data.
 *
 * One method per case.
 *
 * @mago-expect lint:too-many-methods
 */
final class JsonApiClientLoggingTest extends TestCase
{
    public function testItLogsTheCallWithoutARequestIdNorSiretByDefault(): void
    {
        $stack = Stacks::stepping(250_000);
        $stack->respondToken();
        $stack->respond(200, '{"nom":"FICTIF"}');

        ClientCalls::send($stack, Operation::FindAccount);

        $records = $stack->logger->records();
        static::assertCount(2, $records);
        static::assertSame('info', $records[1]['level']);
        static::assertSame('URSSAF call.', $records[1]['message']);
        static::assertSame(
            ['tenant' => 'tenant-1', 'operation' => '020', 'method' => 'GET', 'status' => 200, 'duration_ms' => 250],
            $records[1]['context'],
        );
    }

    public function testItLogsTheRequestIdAndTheSiretWhenConfigured(): void
    {
        $stack = ClientCalls::stackWith(new ApiConfiguration(
            Environment::Sandbox,
            null,
            'x-request-id',
            null,
            ApiConfiguration::DEFAULT_TOKEN_SAFETY_MARGIN_SECONDS,
            ApiConfiguration::DEFAULT_USER_AGENT,
            new DateTimeZone('UTC'),
            [],
            true,
        ));
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::Declare);

        static::assertSame(
            [
                'tenant' => 'tenant-1',
                'operation' => '050',
                'method' => 'POST',
                'status' => 200,
                'duration_ms' => 0,
                'request_id' => 'request-id-1',
                'siret' => WireSamples::siret()->toString(),
            ],
            $stack->logger->records()[0]['context'],
        );
    }

    public function testTheSiretIsNeverLoggedWhenTheRequestCarriesNone(): void
    {
        $stack = ClientCalls::stackWith(Stacks::configurationLoggingSiret());
        $stack->respond(200, '{}');

        $stack->jsonClient()->send(
            Stacks::tenant(),
            $stack->tenantCredentials(),
            ApiRequest::get(Operation::FindAccount, '/comptes'),
        );

        static::assertSame(
            ['tenant' => 'tenant-1', 'operation' => '020', 'method' => 'GET', 'status' => 200, 'duration_ms' => 0],
            $stack->logger->records()[0]['context'],
        );
    }

    public function testTheSiretIsNotLoggedByDefault(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::FindAccount);

        static::assertArrayNotHasKey('siret', $stack->logger->records()[0]['context']);
        static::assertStringNotContainsString(WireSamples::siret()->toString(), $stack->logger->dump());
    }

    public function testItLogsTheReplayAfterA401(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(401, '');
        $stack->respondToken();
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::Declare);

        $records = $stack->logger->records();
        static::assertCount(4, $records);
        static::assertSame('URSSAF call.', $records[0]['message']);
        static::assertSame(
            ['tenant' => 'tenant-1', 'operation' => '050', 'method' => 'POST', 'status' => 401, 'duration_ms' => 0],
            $records[0]['context'],
        );
        static::assertSame('info', $records[1]['level']);
        static::assertSame('URSSAF call answered 401: token invalidated, replaying once.', $records[1]['message']);
        static::assertSame(['tenant' => 'tenant-1', 'operation' => '050'], $records[1]['context']);
        static::assertSame('URSSAF token request.', $records[2]['message']);
        static::assertSame('URSSAF call.', $records[3]['message']);
        static::assertSame(
            ['tenant' => 'tenant-1', 'operation' => '050', 'method' => 'POST', 'status' => 200, 'duration_ms' => 0],
            $records[3]['context'],
        );
    }

    public function testItLogsAWarningWithTheErrorCodesOfAnErrorAnswer(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(400, Fixtures::load('error_param_invalide'));

        try {
            ClientCalls::send($stack, Operation::FindAccount);
            static::fail('Expected a rejection.');
        } catch (Throwable) {
            $records = $stack->logger->records();
            static::assertCount(2, $records);
            static::assertSame('info', $records[0]['level']);
            static::assertSame('warning', $records[1]['level']);
            static::assertSame('URSSAF call failed.', $records[1]['message']);
            static::assertSame(
                ['tenant' => 'tenant-1', 'operation' => '020', 'status' => 400, 'error_codes' => ['PARAM_INVALIDE']],
                $records[1]['context'],
            );
            static::assertStringNotContainsString('SIRET ne doit pas', $stack->logger->dump());
            static::assertStringNotContainsString('invalide', $stack->logger->dump());
        }
    }

    public function testTheErrorCodesAreEmptyWhenTheBodyIsUnreadable(): void
    {
        $stack = ClientCalls::stack();
        $stack->respond(503, '<html>');

        try {
            ClientCalls::send($stack, Operation::FindAccount);
            static::fail('Expected an unavailable exception.');
        } catch (UrssafUnavailable) {
            static::assertSame(
                ['tenant' => 'tenant-1', 'operation' => '020', 'status' => 503, 'error_codes' => []],
                $stack->logger->records()[1]['context'],
            );
        }
    }

    public function testTheFailureWarningIsLoggedForEveryNonTwoHundredAnswer(): void
    {
        foreach ([403, 418, 429, 500, 512] as $status) {
            $stack = ClientCalls::stack();
            $stack->respond($status, '');

            try {
                ClientCalls::send($stack, Operation::Pay);
                static::fail('Expected a failure.');
            } catch (Throwable) {
                $records = $stack->logger->records();
                static::assertCount(2, $records, 'HTTP ' . $status);
                static::assertSame('URSSAF call failed.', $records[1]['message']);
                static::assertSame($status, $records[1]['context']['status']);
            }
        }
    }

    public function testNeitherPathQueryBodyTokenNorSecretIsLogged(): void
    {
        $stack = Stacks::stepping(1000);
        $stack->respondToken();
        $stack->respond(200, '{"nomNaissance":"FICTIF"}');
        $stack->respond(200, '{"nomNaissance":"FICTIF"}');
        $stack->respond(400, Fixtures::load('error_sepa_mandate_already_exists'));

        ClientCalls::send($stack, Operation::Estimate);
        ClientCalls::send($stack, Operation::RegisterSepaMandate);
        static::assertInstanceOf(UrssafRequestRejected::class, $this->failureOf($stack, Operation::RevokeSepaMandate));

        $dump = $stack->logger->dump();
        foreach ([
            StaticCredentialsProvider::SHARED_VALUE,
            StaticCredentialsProvider::CLIENT_ID,
            'fictitious-access-token-0001',
            'Bearer',
            WireSamples::PAYMENT_IBAN,
            WireSamples::RUM,
            WireSamples::siret()->toString(),
            '1975-10-01',
            '2020-07-20',
            'FICTIF',
            '/sepa/',
            '/estimer',
            'dateNaissance',
            'iban',
            '?',
            'https://',
            'déjà actif',
            'Mandat',
        ] as $forbidden) {
            static::assertStringNotContainsString($forbidden, $dump);
        }
    }

    public function testItLogsTransportFailuresAsWarningsWithoutTheMessage(): void
    {
        $stack = ClientCalls::stackWith(new ApiConfiguration(Environment::Sandbox, null, 'x-request-id', null));
        $stack->fail(new TransportFailure('timeout for https://h/comptes?siret=12345678912345'));

        $exception = $this->failureOf($stack, Operation::FindAccount);

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        $records = $stack->logger->records();
        static::assertCount(1, $records);
        static::assertSame('warning', $records[0]['level']);
        static::assertSame('URSSAF call failed: transport error.', $records[0]['message']);
        static::assertSame(
            [
                'tenant' => 'tenant-1',
                'operation' => '020',
                'method' => 'GET',
                'duration_ms' => 0,
                'exception' => TransportFailure::class,
                'request_id' => 'request-id-1',
            ],
            $records[0]['context'],
        );
        static::assertStringNotContainsString('12345678912345', $stack->logger->dump());
        static::assertNoMessageContains('12345678912345', $exception);
    }

    public function testTheTransportWarningOfAnUnsafeOperationHasNoRequestIdNorSiretByDefault(): void
    {
        $stack = Stacks::stepping(250_000);
        $stack->warmUpToken();
        $stack->fail(new TransportFailure('timeout for https://h/sepa/mandats/12345678912345/RUM'));

        $exception = $this->failureOf($stack, Operation::RevokeSepaMandate);

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(
            [
                'tenant' => 'tenant-1',
                'operation' => '071',
                'method' => 'DELETE',
                'duration_ms' => 250,
                'exception' => TransportFailure::class,
            ],
            $stack->logger->records()[0]['context'],
        );
        static::assertNoMessageContains('https://h', $exception);
    }

    public function testTheTransportWarningCarriesTheSiretWhenConfigured(): void
    {
        $stack = ClientCalls::stackWith(Stacks::configurationLoggingSiret());
        $stack->fail(new TransportFailure('down'));

        static::assertInstanceOf(UrssafUnavailable::class, $this->failureOf($stack, Operation::FindAccount));
        static::assertSame(
            WireSamples::siret()->toString(),
            $stack->logger->records()[0]['context']['siret'],
        );
    }

    private function failureOf(Stack $stack, Operation $operation): Throwable
    {
        try {
            ClientCalls::send($stack, $operation);
        } catch (Throwable $exception) {
            return $exception;
        }

        static::fail('Expected a failure.');
    }

    private static function assertNoMessageContains(string $needle, Throwable $exception): void
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            static::assertStringNotContainsString($needle, $current->getMessage());
        }
    }
}
