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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;

final class OAuth2TokenCacheTest extends TestCase
{
    private function credentials(string $clientId = StaticCredentialsProvider::CLIENT_ID): Credentials
    {
        return Credentials::of($clientId, StaticCredentialsProvider::SHARED_VALUE);
    }

    private const ISSUED = 'fictitious-access-token-0001';

    public function testItCachesTheTokenUnderAPsr6SafeKeyWithoutSecrets(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $provider = $stack->tokenProvider();

        $provider->accessToken($this->credentials(), Operation::FindAccount);
        $second = $provider->accessToken($this->credentials(), Operation::Estimate);

        static::assertSame(self::ISSUED, $second);
        static::assertCount(1, $stack->requests());
        $expectedKey = 'urssaf_tdae.' . hash('sha256', 'sandbox|https://api-edi.urssaf.fr|' . StaticCredentialsProvider::CLIENT_ID);
        static::assertSame([$expectedKey], $stack->tokens->keys());
        static::assertSame($expectedKey, $provider->cacheKey($this->credentials()));
        static::assertMatchesRegularExpression('/^urssaf_tdae\.[0-9a-f]{64}$/', $expectedKey);
        static::assertSame(76, \strlen($expectedKey));
        static::assertStringNotContainsString(StaticCredentialsProvider::SHARED_VALUE, $expectedKey);
    }

    public function testTheCacheKeyDependsOnClientEnvironmentAndBaseUri(): void
    {
        $keys = [];
        $variants = [
            [ApiConfiguration::forEnvironment(Environment::Sandbox), 'client-a'],
            [ApiConfiguration::forEnvironment(Environment::Sandbox), 'client-b'],
            [ApiConfiguration::forEnvironment(Environment::Production), 'client-a'],
            [new ApiConfiguration(Environment::Sandbox, 'https://proxy.example.test', null, null), 'client-a'],
            [new ApiConfiguration(Environment::Sandbox, 'https://other-proxy.example.test', null, null), 'client-a'],
        ];
        foreach ($variants as [$configuration, $clientId]) {
            $stack = Stacks::configured($configuration);
            $stack->respondToken();
            $stack->tokenProvider()->accessToken($this->credentials($clientId), Operation::FindAccount);
            $keys[] = $stack->tokens->keys()[0];
        }

        static::assertCount(5, array_unique($keys));
    }

    public function testInvalidateForcesANewToken(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, '{"access_token":"second-token","expires_in":3600}');
        $provider = $stack->tokenProvider();
        $provider->accessToken($this->credentials(), Operation::FindAccount);

        $provider->invalidate($this->credentials());

        static::assertSame([], $stack->tokens->keys());
        static::assertSame('second-token', $provider->accessToken($this->credentials(), Operation::FindAccount));
    }

    public function testInvalidateOnlyAffectsTheGivenClient(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respondToken();
        $provider = $stack->tokenProvider();
        $provider->accessToken($this->credentials('client-a'), Operation::FindAccount);
        $provider->accessToken($this->credentials('client-b'), Operation::FindAccount);

        $provider->invalidate($this->credentials('client-a'));

        static::assertSame([$provider->cacheKey($this->credentials('client-b'))], $stack->tokens->keys());
    }

    public function testACachedTokenSendsNoRequest(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $provider = $stack->tokenProvider();
        $provider->accessToken($this->credentials(), Operation::FindAccount);
        $stack->logger->clear();

        $provider->accessToken($this->credentials(), Operation::FindAccount);

        static::assertCount(1, $stack->requests());
        static::assertSame([], $stack->logger->records());
    }
}
