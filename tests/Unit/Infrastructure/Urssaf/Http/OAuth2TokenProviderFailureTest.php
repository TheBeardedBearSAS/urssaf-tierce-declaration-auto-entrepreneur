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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\TransportError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;

final class OAuth2TokenProviderFailureTest extends TestCase
{
    private function fetch(Stack $stack): string
    {
        return $stack->tokenProvider()->accessToken(
            Credentials::of(StaticCredentialsProvider::CLIENT_ID, StaticCredentialsProvider::SHARED_VALUE),
            Operation::Declare,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function authenticationStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
    }


    #[DataProvider('authenticationStatuses')]
    public function testBadCredentialsFailAuthentication(int $status): void
    {
        $stack = Stacks::sandbox();
        $stack->respond($status, '{"error":"invalid_client"}');

        try {
            $this->fetch($stack);
            static::fail('Expected an authentication failure.');
        } catch (UrssafAuthenticationFailed $exception) {
            static::assertSame($status, $exception->httpStatus());
        }
        static::assertSame([], $stack->tokens->keys());
    }

    /**
     * @return iterable<string, array{int, array<string, string>, int|null}>
     */
    public static function unavailableStatuses(): iterable
    {
        yield 'quota with Retry-After' => [429, ['Retry-After' => '17'], 17];
        yield 'quota without Retry-After' => [429, [], null];
        yield 'server error' => [500, [], null];
        yield 'bad gateway' => [502, [], null];
        yield 'service unavailable' => [503, ['Retry-After' => '5'], 5];
        yield 'security gateway' => [512, [], null];
        yield 'last server status' => [599, [], null];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('unavailableStatuses')]
    public function testQuotaAndServerErrorsAreRetryable(int $status, array $headers, ?int $retryAfter): void
    {
        $stack = Stacks::sandbox();
        $stack->respond($status, '', $headers);

        try {
            $this->fetch($stack);
            static::fail('Expected an unavailable exception.');
        } catch (UrssafUnavailable $exception) {
            static::assertSame($status, $exception->httpStatus());
            static::assertSame($retryAfter, $exception->retryAfterSeconds());
            static::assertSame(Operation::Declare, $exception->operation());
            static::assertFalse($exception->mayHaveReachedUrssaf());
        }
    }

    public function testTransportErrorsAreRetryableAndKeepTheCause(): void
    {
        $stack = Stacks::sandbox();
        $stack->fail(new TransportFailure('connection refused https://h/x?siret=12345678901234'));

        try {
            $this->fetch($stack);
            static::fail('Expected an unavailable exception.');
        } catch (UrssafUnavailable $exception) {
            static::assertNull($exception->httpStatus());
            static::assertInstanceOf(TransportError::class, $exception->getPrevious());
            static::assertStringNotContainsString('12345678901234', $exception->getPrevious()->getMessage());
            static::assertSame(Operation::Declare, $exception->operation());
            static::assertFalse($exception->mayHaveReachedUrssaf());
        }
    }
}
