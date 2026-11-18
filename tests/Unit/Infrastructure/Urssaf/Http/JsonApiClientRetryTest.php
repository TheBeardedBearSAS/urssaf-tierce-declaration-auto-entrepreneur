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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ClientCalls;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use Throwable;

/**
 * The replay after a 401 (ADR 0002): once, for every operation.
 */
final class JsonApiClientRetryTest extends TestCase
{
    private const RENEWAL_RESPONSE = '{"access_token":"second-token","expires_in":3600}';

    public function testA401InvalidatesTheTokenAndReplaysTheCallExactlyOnce(): void
    {
        $stack = Stacks::configured(new ApiConfiguration(Environment::Sandbox, null, 'x-request-id', null));
        $stack->respondToken();
        $stack->respond(401, '');
        $stack->respond(200, self::RENEWAL_RESPONSE);
        $stack->respond(200, '{"ok":"retried"}');

        $response = ClientCalls::send($stack, Operation::Declare);

        static::assertSame('retried', $response->payload()->string('ok'));
        static::assertSame(200, $response->status());
        $requests = $stack->requests();
        static::assertCount(4, $requests);
        static::assertSame('Bearer fictitious-access-token-0001', $requests[1]->getHeaderLine('Authorization'));
        static::assertSame('Bearer second-token', $requests[3]->getHeaderLine('Authorization'));
        static::assertSame('request-id-1', $requests[1]->getHeaderLine('x-request-id'));
        static::assertSame('request-id-2', $requests[3]->getHeaderLine('x-request-id'));
        static::assertSame((string) $requests[1]->getBody(), (string) $requests[3]->getBody());
        static::assertSame('request-id-2', $response->requestId());
    }

    public function testTheTokenIsDeletedBetweenTheAttemptsAndFetchedAgain(): void
    {
        $cold = Stacks::sandbox();
        $cold->respondToken();
        $cold->respond(401, '');
        $cold->respond(200, self::RENEWAL_RESPONSE);
        $cold->respond(200, '{}');

        ClientCalls::send($cold, Operation::Declare);

        $warm = ClientCalls::stack();
        $warm->respond(401, '');
        $warm->respond(200, self::RENEWAL_RESPONSE);
        $warm->respond(200, '{}');

        ClientCalls::send($warm, Operation::Declare);

        static::assertSame(2, $this->tokenRequests($cold));
        // The warm-up fetch is not part of the call: only the replay fetches a token.
        static::assertSame(1, $this->tokenRequests($warm) - 1);
        static::assertCount(1, $cold->tokens->keys());
        static::assertSame('second-token', $warm->tokens->get($warm->tokens->keys()[0])?->value());
    }

    public function testTheReplayAcquiresTheRateLimiterAgain(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(401, '');
        $stack->respond(200, self::RENEWAL_RESPONSE);
        $stack->respond(200, '{}');

        ClientCalls::send($stack, Operation::Declare);

        static::assertSame(['tenant-1:050', 'tenant-1:050'], $stack->rateLimiter->acquired());
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function provideEveryOperation(): iterable
    {
        foreach (Operation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }

    #[DataProvider('provideEveryOperation')]
    public function testEveryOperationIsReplayedAfterA401(Operation $operation): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(401, '');
        $stack->respond(200, self::RENEWAL_RESPONSE);
        $stack->respond(200, '{}');

        $response = ClientCalls::send($stack, $operation);

        static::assertSame(200, $response->status());
        static::assertCount(4, $stack->requests());
        static::assertSame([\sprintf('tenant-1:%s', $operation->value), \sprintf('tenant-1:%s', $operation->value)], $stack->rateLimiter->acquired());
    }

    public function testASecond401FailsAuthenticationWithoutAThirdAttempt(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(401, '');
        $stack->respond(200, self::RENEWAL_RESPONSE);
        $stack->respond(401, '');

        try {
            ClientCalls::send($stack, Operation::Declare);
            static::fail('Expected an authentication failure.');
        } catch (UrssafAuthenticationFailed $exception) {
            static::assertSame(401, $exception->httpStatus());
        }
        static::assertCount(4, $stack->requests());
        static::assertSame(['tenant-1:050', 'tenant-1:050'], $stack->rateLimiter->acquired());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNeverReplayedStatuses(): iterable
    {
        yield 'forbidden' => [403];
        yield 'bad request' => [400];
        yield 'quota' => [429];
        yield 'server error' => [500];
        yield 'security gateway' => [512];
    }

    #[DataProvider('provideNeverReplayedStatuses')]
    public function testOtherStatusesAreNeverReplayed(int $status): void
    {
        $stack = ClientCalls::stack();
        $stack->respond($status, '');

        try {
            ClientCalls::send($stack, Operation::FindAccount);
            static::fail('Expected a failure.');
        } catch (Throwable) {
            static::assertCount(2, $stack->requests());
            static::assertCount(1, $stack->tokens->keys());
            static::assertCount(1, $stack->rateLimiter->acquired());
        }
    }

    private function tokenRequests(Stack $stack): int
    {
        return \count(array_filter(
            $stack->requests(),
            static fn(RequestInterface $request): bool => $request->getUri()->getPath() === '/api/oauth/v1/token',
        ));
    }
}
