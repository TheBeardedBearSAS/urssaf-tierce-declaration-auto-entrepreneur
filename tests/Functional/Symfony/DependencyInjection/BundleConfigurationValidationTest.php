<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;

/**
 * Every rule of the configuration tree and every built-in constraint is rejected at build time (ADR 0015).
 */
final class BundleConfigurationValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidRules')]
    #[DataProvider('invalidAdapterRules')]
    #[DataProvider('invalidSafetyRules')]
    #[DataProvider('invalidConstraints')]
    public function testRejectsAnInvalidConfiguration(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        Harness::boot($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'unknown environment' => [['environment' => 'staging'], 'Unknown environment "staging": use "sandbox" or "production".'];
        yield 'unknown time zone' => [['business_timezone' => 'Mars/Phobos'], 'Unknown time zone "Mars\/Phobos".'];
        yield 'invalid retention' => [['outbox' => ['retention' => 'a month']], 'Invalid ISO 8601 duration "a month".'];
        yield 'invalid stale duration' => [['workflow' => ['stale_submission_after' => 'soon']], 'Invalid ISO 8601 duration "soon".'];
        yield 'invalid sync frequency' => [['scheduler' => ['frequencies' => ['sync_accounts' => 'often']]], 'Invalid ISO 8601 duration "often".'];
        yield 'invalid declarations frequency' => [['scheduler' => ['frequencies' => ['reconcile_declarations' => 'often']]], 'Invalid ISO 8601 duration "often".'];
        yield 'invalid payments frequency' => [['scheduler' => ['frequencies' => ['reconcile_payments' => 'often']]], 'Invalid ISO 8601 duration "often".'];
        yield 'invalid relay frequency' => [['scheduler' => ['frequencies' => ['relay_outbox' => 'often']]], 'Invalid ISO 8601 duration "often".'];
        yield 'invalid purge frequency' => [['scheduler' => ['frequencies' => ['purge_outbox' => 'often']]], 'Invalid ISO 8601 duration "often".'];
        yield 'credentials and provider' => [['credentials_provider' => 'my.provider'], '"credentials" and "credentials_provider" are mutually exclusive.'];
        yield 'no credentials source' => [['credentials' => null], 'Configure either "credentials" or "credentials_provider".'];
        yield 'simulated gateway in production' => [['environment' => 'production', 'gateway' => ['simulated' => true]], '"gateway.simulated" is meant for tests and cannot be used with "environment: production".'];
        yield 'default page above max' => [['workflow' => ['default_page_size' => 200, 'max_page_size' => 100]], '"default_page_size" must not be greater than "max_page_size".'];
        yield 'same header names' => [['headers' => ['request_id' => 'X-Id', 'application_id' => ['name' => 'x-id', 'value' => 'v']]], '"headers.request_id" and "headers.application_id.name" must be different headers.'];
        yield 'header name only' => [['headers' => ['application_id' => ['name' => 'X-Application']]], '"headers.application_id" needs both "name" and "value", or neither.'];
        yield 'header value only' => [['headers' => ['application_id' => ['value' => 'my-application']]], '"headers.application_id" needs both "name" and "value", or neither.'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidAdapterRules(): iterable
    {
        yield 'invalid rate limiter window' => [['rate_limiter' => ['window' => 'a minute']], 'Invalid ISO 8601 duration "a minute" (at least one second).'];
        yield 'rate limiter window of no duration' => [['rate_limiter' => ['window' => 'PT0S']], 'Invalid ISO 8601 duration "PT0S" (at least one second).'];
        yield 'rate limiter window as an integer' => [['rate_limiter' => ['window' => 60]], 'Invalid ISO 8601 duration 60 (at least one second).'];
        yield 'rate limiter service while disabled' => [['rate_limiter' => ['service' => 'my.limiter']], '"rate_limiter.service" requires "rate_limiter.enabled: true".'];
        yield 'outbox without storage' => [
            [
                'credentials' => null,
                'credentials_provider' => 'test.credentials',
                'outbox' => ['enabled' => true],
                'persistence' => ['driver' => 'custom', 'account_repository' => 'a', 'mandate_repository' => 'm', 'declaration_record_repository' => 'd', 'payment_record_repository' => 'p'],
            ],
            'The "custom" persistence driver requires "outbox.service" when the outbox is enabled.',
        ];
        yield 'custom driver without repositories' => [['persistence' => ['driver' => 'custom']], 'The "custom" driver requires "account_repository", "mandate_repository", "declaration_record_repository" and "payment_record_repository".'];
        yield 'custom driver with one repository' => [['persistence' => ['driver' => 'custom', 'account_repository' => 'a']], 'The "custom" driver requires "account_repository"'];
        yield 'custom driver without the account repository' => [['persistence' => ['driver' => 'custom', 'mandate_repository' => 'm', 'declaration_record_repository' => 'd', 'payment_record_repository' => 'p']], 'The "custom" driver requires'];
        yield 'custom driver without the mandate repository' => [['persistence' => ['driver' => 'custom', 'account_repository' => 'a', 'declaration_record_repository' => 'd', 'payment_record_repository' => 'p']], 'The "custom" driver requires'];
        yield 'custom driver without the declaration repository' => [['persistence' => ['driver' => 'custom', 'account_repository' => 'a', 'mandate_repository' => 'm', 'payment_record_repository' => 'p']], 'The "custom" driver requires'];
        yield 'custom driver without the payment repository' => [['persistence' => ['driver' => 'custom', 'account_repository' => 'a', 'mandate_repository' => 'm', 'declaration_record_repository' => 'd']], 'The "custom" driver requires'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSafetyRules(): iterable
    {
        yield 'stale submission shorter than the timeout' => [['http_timeout' => 120.0, 'workflow' => ['stale_submission_after' => 'PT1M']], '"workflow.stale_submission_after" must be longer than twice "http_timeout"'];
        yield 'stale submission equal to the timeout' => [['http_timeout' => 60.0, 'workflow' => ['stale_submission_after' => 'PT1M']], '"workflow.stale_submission_after" must be longer than twice "http_timeout"'];
        yield 'stale submission equal to twice the timeout' => [['http_timeout' => 30.0, 'workflow' => ['stale_submission_after' => 'PT1M']], '"workflow.stale_submission_after" must be longer than twice "http_timeout"'];
        yield 'safe retry of a read' => [['retry_safe_writes' => ['020']], 'Unknown operation "020": only "030", "031", "050", "070" and "071" can be declared safe to retry'];
        yield 'safe retry of the estimate' => [['retry_safe_writes' => ['040']], 'Unknown operation "040"'];
        yield 'safe retry of the payment order' => [['retry_safe_writes' => ['060']], '"060" is never resent (ADR 0003): it cannot be declared safe to retry.'];
        yield 'safe retry of an unknown method' => [['retry_safe_writes' => ['999']], 'Unknown operation "999"'];
        yield 'safe retry given as an integer (unquoted YAML)' => [['retry_safe_writes' => [30]], 'only "030", "031", "050", "070" and "071" can be declared safe to retry'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConstraints(): iterable
    {
        yield 'negative safety margin' => [['token_safety_margin' => -1], 'token_safety_margin'];
        yield 'http timeout below 0.1' => [['http_timeout' => 0.05], 'http_timeout'];
        yield 'empty user agent' => [['user_agent' => ''], 'user_agent'];
        yield 'rate limit below 1' => [['rate_limiter' => ['limit' => 0]], 'rate_limiter.limit'];
        yield 'lock mode' => [['lock' => ['enabled' => 'sometimes']], 'lock.enabled'];
        yield 'lock ttl below 1' => [['lock' => ['ttl' => 0.5]], 'lock.ttl'];
        yield 'empty lock factory' => [['lock' => ['factory' => '']], 'lock.factory'];
        yield 'empty outbox sink' => [['outbox' => ['sink' => '']], 'outbox.sink'];
        yield 'empty outbox bus' => [['outbox' => ['bus' => '']], 'outbox.bus'];
        yield 'empty schedule name' => [['scheduler' => ['name' => '']], 'scheduler.name'];
        yield 'empty messenger bus' => [['messenger' => ['bus' => '']], 'messenger.bus'];
        yield 'empty cache pool' => [['token_store' => ['cache_pool' => '']], 'token_store.cache_pool'];
        yield 'empty tenant' => [['tenants' => ['']], 'tenants'];
        yield 'no attempts' => [['workflow' => ['max_submission_attempts' => 0]], 'max_submission_attempts'];
        yield 'no default page size' => [['workflow' => ['default_page_size' => 0]], 'default_page_size'];
        yield 'no max page size' => [['workflow' => ['default_page_size' => 1, 'max_page_size' => 0]], 'workflow.max_page_size'];
        yield 'unknown driver' => [['persistence' => ['driver' => 'mongo']], 'persistence.driver'];
        yield 'empty entity manager' => [['persistence' => ['entity_manager' => '']], 'persistence.entity_manager'];
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        yield 'empty client id' => [['credentials' => ['tenant-1' => ['client_id' => '', 'client_secret' => 'x']]], 'client_id'];
        yield 'empty client secret' => [['credentials' => ['tenant-1' => ['client_id' => 'x', 'client_secret' => '']]], 'client_secret'];
        yield 'option of another package' => [['request_id_header' => 'X-Id'], 'request_id_header'];
        yield 'predeclaration of another package' => [['workflow' => ['predeclare_before_declaring' => true]], 'predeclare_before_declaring'];
    }

    public function testTheDoctrineDriverNeedsDoctrineBundle(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The "doctrine" persistence driver requires DoctrineBundle (doctrine/doctrine-bundle): register it, or set "persistence.driver" to "in_memory" or "custom".');

        Harness::boot(['persistence' => ['driver' => 'doctrine']]);
    }

    public function testTheEnvironmentIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('environment');

        (new TestKernel(['environment' => null]))->boot();
    }
}
