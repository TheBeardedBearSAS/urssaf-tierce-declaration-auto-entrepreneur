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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection\ConfigurationRules;

/**
 * The predicates of the configuration tree, tested without a container (each one is true when the value is invalid).
 *
 * @mago-expect lint:too-many-methods One test per predicate.
 */
final class ConfigurationRulesTest extends TestCase
{
    #[DataProvider('staleThresholds')]
    public function testTheStaleThresholdMustExceedTwiceTheHttpTimeout(bool $invalid, string $stale, int|float|string|null $timeout): void
    {
        static::assertSame($invalid, ConfigurationRules::staleSubmissionNotAboveHttpTimeout(['http_timeout' => $timeout, 'workflow' => ['stale_submission_after' => $stale]]));
    }

    /**
     * @return iterable<string, array{bool, string, int|float|string|null}>
     */
    public static function staleThresholds(): iterable
    {
        yield 'longer' => [false, 'PT15M', 15.0];
        yield 'one second above twice the timeout' => [false, 'PT31S', 15.0];
        yield 'twice the timeout' => [true, 'PT30S', 15.0];
        yield 'one second below twice the timeout' => [true, 'PT29S', 15.0];
        yield 'the timeout alone' => [true, 'PT15S', 15.0];
        yield 'integer timeout' => [true, 'PT2M', 60];
        yield 'integer timeout, one second above' => [false, 'PT121S', 60];
        yield 'a month is counted in days' => [false, 'P1M', 3600.0];
        yield 'a day is counted in hours' => [true, 'P1D', 43_200.0];
        yield 'a placeholder timeout cannot be compared' => [false, 'PT1S', '%env(float:URSSAF_TIMEOUT)%'];
        yield 'a null timeout cannot be compared' => [false, 'PT1S', null];
    }

    #[DataProvider('durations')]
    public function testADurationIsMeasuredFromAFixedInstant(string $interval, int $seconds): void
    {
        static::assertSame($seconds, ConfigurationRules::seconds($interval));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function durations(): iterable
    {
        yield 'a minute' => ['PT60S', 60];
        yield 'fifteen minutes' => ['PT15M', 900];
        yield 'a day' => ['P1D', 86_400];
        yield 'a month is 31 days' => ['P1M', 2_678_400];
    }

    #[DataProvider('windows')]
    public function testARateLimiterWindowIsAtLeastOneSecond(mixed $window, bool $invalid): void
    {
        static::assertSame($invalid, ConfigurationRules::isInvalidPositiveSecondsInterval($window));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function windows(): iterable
    {
        yield 'a minute' => ['PT60S', false];
        yield 'one second' => ['PT1S', false];
        yield 'no duration' => ['PT0S', true];
        yield 'no duration in days' => ['P0D', true];
        yield 'not a duration' => ['a minute', true];
        yield 'an integer' => [60, true];
        yield 'null' => [null, true];
    }

    public function testTheHeaderNamesAreComparedWithoutRegardToTheCase(): void
    {
        static::assertTrue(ConfigurationRules::duplicateHeaderNames(['request_id' => 'x-id', 'application_id' => ['name' => 'X-Id']]));
        static::assertTrue(ConfigurationRules::duplicateHeaderNames(['request_id' => 'X-Id', 'application_id' => ['name' => 'x-id']]));
        static::assertFalse(ConfigurationRules::duplicateHeaderNames(['request_id' => 'X-Id', 'application_id' => ['name' => 'X-Application']]));
        static::assertFalse(ConfigurationRules::duplicateHeaderNames(['request_id' => null, 'application_id' => ['name' => 'X-Id']]));
        static::assertFalse(ConfigurationRules::duplicateHeaderNames(['request_id' => 'X-Id', 'application_id' => ['name' => null]]));
    }

    public function testTheDefaultPageSizeMayEqualTheMaximum(): void
    {
        static::assertFalse(ConfigurationRules::defaultPageAboveMax(['default_page_size' => 50, 'max_page_size' => 50]));
        static::assertFalse(ConfigurationRules::defaultPageAboveMax(['default_page_size' => 10, 'max_page_size' => 50]));
        static::assertTrue(ConfigurationRules::defaultPageAboveMax(['default_page_size' => 51, 'max_page_size' => 50]));
    }

    public function testTheCustomDriverNeedsEachOfTheFourRepositories(): void
    {
        $complete = ['driver' => 'custom', 'account_repository' => 'a', 'mandate_repository' => 'm', 'declaration_record_repository' => 'd', 'payment_record_repository' => 'p'];

        static::assertFalse(ConfigurationRules::customDriverWithoutRepositories($complete));
        static::assertFalse(ConfigurationRules::customDriverWithoutRepositories([...$complete, 'driver' => 'in_memory', 'mandate_repository' => null]));
        foreach (['account_repository', 'mandate_repository', 'declaration_record_repository', 'payment_record_repository'] as $missing) {
            static::assertTrue(ConfigurationRules::customDriverWithoutRepositories([...$complete, $missing => null]), $missing);
        }
    }

    public function testAHostRateLimiterNeedsTheLimiterToBeEnabled(): void
    {
        static::assertTrue(ConfigurationRules::rateLimiterServiceWhileDisabled(['enabled' => false, 'service' => 'my.limiter']));
        static::assertFalse(ConfigurationRules::rateLimiterServiceWhileDisabled(['enabled' => true, 'service' => 'my.limiter']));
        static::assertFalse(ConfigurationRules::rateLimiterServiceWhileDisabled(['enabled' => false, 'service' => null]));
        static::assertFalse(ConfigurationRules::rateLimiterServiceWhileDisabled(['enabled' => true, 'service' => null]));
    }

    public function testTheApplicationHeaderNeedsBothItsNameAndItsValueOrNeither(): void
    {
        static::assertFalse(ConfigurationRules::incompleteApplicationIdHeader(['name' => null, 'value' => null]));
        static::assertFalse(ConfigurationRules::incompleteApplicationIdHeader(['name' => '', 'value' => '']));
        static::assertFalse(ConfigurationRules::incompleteApplicationIdHeader(['name' => 'X-Application', 'value' => 'my-application']));
        static::assertTrue(ConfigurationRules::incompleteApplicationIdHeader(['name' => 'X-Application', 'value' => null]));
        static::assertTrue(ConfigurationRules::incompleteApplicationIdHeader(['name' => null, 'value' => 'my-application']));
        static::assertTrue(ConfigurationRules::incompleteApplicationIdHeader(['name' => '', 'value' => 'my-application']));
    }

    public function testTheCredentialSourcesAreMutuallyExclusiveAndRequired(): void
    {
        static::assertTrue(ConfigurationRules::hasBothCredentialSources(['credentials' => ['t' => []], 'credentials_provider' => 'p']));
        static::assertFalse(ConfigurationRules::hasBothCredentialSources(['credentials' => [], 'credentials_provider' => 'p']));
        static::assertFalse(ConfigurationRules::hasBothCredentialSources(['credentials' => ['t' => []], 'credentials_provider' => null]));
        static::assertTrue(ConfigurationRules::hasNoCredentialSource(['credentials' => [], 'credentials_provider' => null]));
        static::assertFalse(ConfigurationRules::hasNoCredentialSource(['credentials' => ['t' => []], 'credentials_provider' => null]));
        static::assertFalse(ConfigurationRules::hasNoCredentialSource(['credentials' => [], 'credentials_provider' => 'p']));
    }

    public function testTheOutboxNeedsAServiceWithTheCustomDriver(): void
    {
        $outbox = ['enabled' => true, 'service' => null];

        static::assertTrue(ConfigurationRules::outboxWithoutStorage(['outbox' => $outbox, 'persistence' => ['driver' => 'custom']]));
        static::assertFalse(ConfigurationRules::outboxWithoutStorage(['outbox' => $outbox, 'persistence' => ['driver' => 'in_memory']]));
        static::assertFalse(ConfigurationRules::outboxWithoutStorage(['outbox' => [...$outbox, 'service' => 'mine'], 'persistence' => ['driver' => 'custom']]));
        static::assertFalse(ConfigurationRules::outboxWithoutStorage(['outbox' => ['enabled' => false, 'service' => null], 'persistence' => ['driver' => 'custom']]));
    }
}
