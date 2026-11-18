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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpHeader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;

final class OAuth2TokenProviderTest extends TestCase
{
    private const ISSUED = 'fictitious-access-token-0001';

    private function credentials(string $clientId = StaticCredentialsProvider::CLIENT_ID): Credentials
    {
        return Credentials::of($clientId, StaticCredentialsProvider::SHARED_VALUE);
    }

    public function testItRequestsATokenWithTheClientCredentialsGrant(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();

        $token = $stack->tokenProvider()->accessToken($this->credentials(), Operation::FindAccount);

        static::assertSame(self::ISSUED, $token);
        $request = $stack->lastRequest();
        static::assertSame('POST', $request->getMethod());
        static::assertSame('https://api-edi.urssaf.fr/api/oauth/v1/token', (string) $request->getUri());
        static::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        static::assertSame('application/json', $request->getHeaderLine('Accept'));
        static::assertSame(ApiConfiguration::DEFAULT_USER_AGENT, $request->getHeaderLine('User-Agent'));
        $form = [];
        parse_str((string) $request->getBody(), $form);
        static::assertSame(
            [
                'grant_type' => 'client_credentials',
                'client_id' => StaticCredentialsProvider::CLIENT_ID,
                'client_secret' => StaticCredentialsProvider::SHARED_VALUE,
                'scope' => 'tiersdecl.ae',
            ],
            $form,
        );
    }

    public function testItUsesTheProductionHostWhenConfigured(): void
    {
        $stack = Stacks::configured(ApiConfiguration::forEnvironment(Environment::Production));
        $stack->respondToken();

        $stack->tokenProvider()->accessToken($this->credentials(), Operation::FindAccount);

        static::assertSame('https://api.urssaf.fr/api/oauth/v1/token', (string) $stack->lastRequest()->getUri());
    }

    public function testItUsesTheBaseUriOverrideAndNeverTheApiBasePath(): void
    {
        $stack = Stacks::configured(new ApiConfiguration(Environment::Sandbox, 'https://proxy.example.test/base/', null, null));
        $stack->respondToken();

        $stack->tokenProvider()->accessToken($this->credentials(), Operation::FindAccount);

        static::assertSame('https://proxy.example.test/base/api/oauth/v1/token', (string) $stack->lastRequest()->getUri());
    }

    public function testItSendsNoConfiguredOptionalHeaderOnTheTokenCall(): void
    {
        $stack = Stacks::configured(new ApiConfiguration(
            Environment::Sandbox,
            null,
            'x-correlation',
            HttpHeader::of('recouv.application.id', 'app-42'),
            60,
            'custom-agent/1.0',
        ));
        $stack->respondToken();

        $stack->tokenProvider()->accessToken($this->credentials(), Operation::FindAccount);

        $request = $stack->lastRequest();
        static::assertSame('custom-agent/1.0', $request->getHeaderLine('User-Agent'));
        static::assertFalse($request->hasHeader('x-correlation'));
        static::assertFalse($request->hasHeader('recouv.application.id'));
    }

    public function testItRefreshesAnExpiredToken(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, '{"access_token":"second-token","expires_in":3600}');
        $provider = $stack->tokenProvider();
        $provider->accessToken($this->credentials(), Operation::FindAccount);

        $stack->clock->advanceSeconds(3600);
        $token = $provider->accessToken($this->credentials(), Operation::FindAccount);

        static::assertSame('second-token', $token);
        static::assertCount(2, $stack->requests());
    }

    public function testItRefreshesTheTokenWithinTheSafetyMargin(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $stack->respond(200, '{"access_token":"second-token","expires_in":3600}');
        $provider = $stack->tokenProvider();
        $provider->accessToken($this->credentials(), Operation::FindAccount);

        $stack->clock->advanceSeconds(3540);

        static::assertSame('second-token', $provider->accessToken($this->credentials(), Operation::FindAccount));
    }

    public function testItKeepsTheTokenJustBeforeTheSafetyMargin(): void
    {
        $stack = Stacks::sandbox();
        $stack->respondToken();
        $provider = $stack->tokenProvider();
        $provider->accessToken($this->credentials(), Operation::FindAccount);

        $stack->clock->advanceSeconds(3539);

        static::assertSame(self::ISSUED, $provider->accessToken($this->credentials(), Operation::FindAccount));
        static::assertCount(1, $stack->requests());
    }

    public function testTheSafetyMarginIsConfigurable(): void
    {
        $stack = Stacks::configured(new ApiConfiguration(Environment::Sandbox, null, null, null, 600));
        $stack->respondToken();
        $stack->respond(200, '{"access_token":"second-token","expires_in":3600}');
        $provider = $stack->tokenProvider();
        $provider->accessToken($this->credentials(), Operation::FindAccount);

        $stack->clock->advanceSeconds(3000);

        static::assertSame('second-token', $provider->accessToken($this->credentials(), Operation::FindAccount));
    }
}
