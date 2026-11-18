<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Contract;

use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Clock\SystemClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\RandomUuidGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\SecureRandomBytes;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\JsonApiClient;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\OAuth2TokenProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafHttpGateway;

/**
 * Wires the real HTTP gateway against the URSSAF sandbox ("environnement Editeur") from environment variables, by hand
 * (no Symfony, no builder: the contract tests exercise the very classes a host application wires):
 *
 * - URSSAF_SANDBOX_CLIENT_ID / URSSAF_SANDBOX_CLIENT_SECRET (required, otherwise the suite is skipped);
 * - URSSAF_SANDBOX_BASE_URI (optional host override);
 * - URSSAF_SANDBOX_SIRET / URSSAF_SANDBOX_BIRTH_DATE (the sandbox account to test with: test data, never in the code);
 * - URSSAF_SANDBOX_WRITE=1 (opt in to the tests that write sandbox state and e-mail the auto-entrepreneur).
 */
final readonly class SandboxGateway
{
    public const TENANT = 'contract-tests';

    private const HTTP_TIMEOUT_SECONDS = 30;

    private function __construct(
        public UrssafHttpGateway $gateway,
        public OAuth2TokenProvider $tokenProvider,
        public Credentials $credentials,
        public TenantId $tenantId,
        public SandboxHttpClient $http,
    ) {}

    /**
     * Null when the required environment variables are missing.
     */
    public static function fromEnvironment(): ?self
    {
        $clientId = self::env('URSSAF_SANDBOX_CLIENT_ID');
        $clientSecret = self::env('URSSAF_SANDBOX_CLIENT_SECRET');
        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        $tenantId = TenantId::fromString(self::TENANT);
        $credentials = Credentials::of($clientId, $clientSecret);
        $configuration = new ApiConfiguration(Environment::Sandbox, self::env('URSSAF_SANDBOX_BASE_URI'), null, null);

        $factory = new Psr17Factory();
        $http = new SandboxHttpClient(
            new Psr18Client(HttpClient::create(['timeout' => self::HTTP_TIMEOUT_SECONDS]), $factory, $factory),
            $factory,
        );
        $clock = new SystemClock(new NativeClock());
        $tokenProvider = new OAuth2TokenProvider($http, $factory, $factory, new InMemoryTokenStore(), $clock, $configuration);
        $client = new JsonApiClient(
            $http,
            $factory,
            $factory,
            $tokenProvider,
            new NoopRateLimiter(),
            new RandomUuidGenerator(new SecureRandomBytes()),
            $clock,
            $configuration,
        );

        return new self(
            new UrssafHttpGateway(new InMemoryCredentialsProvider([$tenantId->value() => $credentials]), $client),
            $tokenProvider,
            $credentials,
            $tenantId,
            $http,
        );
    }

    /**
     * Trimmed value of an environment variable; null when it is unset or empty.
     */
    public static function env(string $name): ?string
    {
        $value = getenv($name);
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @throws SandboxMisconfigured when URSSAF_SANDBOX_SIRET is set but is not 14 digits
     */
    public static function siret(): ?Siret
    {
        $value = self::env('URSSAF_SANDBOX_SIRET');
        if ($value === null) {
            return null;
        }

        try {
            return Siret::fromString($value);
        } catch (InvalidValue $invalid) {
            throw new SandboxMisconfigured('URSSAF_SANDBOX_SIRET must be 14 digits.', 0, $invalid);
        }
    }

    /**
     * @throws SandboxMisconfigured when URSSAF_SANDBOX_BIRTH_DATE is set but is not a Y-m-d date
     */
    public static function birthDate(): ?CalendarDate
    {
        $value = self::env('URSSAF_SANDBOX_BIRTH_DATE');
        if ($value === null) {
            return null;
        }

        try {
            return CalendarDate::fromString($value);
        } catch (InvalidValue $invalid) {
            throw new SandboxMisconfigured('URSSAF_SANDBOX_BIRTH_DATE must be a Y-m-d date.', 0, $invalid);
        }
    }

    public static function writesAllowed(): bool
    {
        return self::env('URSSAF_SANDBOX_WRITE') === '1';
    }
}
