<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OperationRetryClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use Throwable;

/**
 * Validation predicates of the configuration tree (each one is true when the value is invalid).
 *
 * One small predicate per rule of the tree, hence the method count and the total complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
final class ConfigurationRules
{
    /**
     * @param array<array-key, mixed> $value
     */
    public static function hasBothCredentialSources(array $value): bool
    {
        /** @var array{credentials: array<array-key, mixed>, credentials_provider: ?string} $config */
        $config = $value;

        return $config['credentials'] !== [] && $config['credentials_provider'] !== null;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function hasNoCredentialSource(array $value): bool
    {
        /** @var array{credentials: array<array-key, mixed>, credentials_provider: ?string} $config */
        $config = $value;

        return $config['credentials'] === [] && $config['credentials_provider'] === null;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function simulatedGatewayInProduction(array $value): bool
    {
        /** @var array{environment: string, gateway: array{simulated: bool}} $config */
        $config = $value;

        return $config['gateway']['simulated'] && $config['environment'] === Environment::Production->value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function lockEnabledWithoutComponent(array $value): bool
    {
        return self::lockRequiredButMissing($value, class_exists(LockFactory::class));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function lockRequiredButMissing(array $value, bool $componentInstalled): bool
    {
        /** @var array{lock: array{enabled: bool|string}} $config */
        $config = $value;

        return $config['lock']['enabled'] === true && !$componentInstalled;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function schedulerEnabledWithoutComponent(array $value): bool
    {
        return self::schedulerRequiredButMissing($value, interface_exists(ScheduleProviderInterface::class));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function schedulerRequiredButMissing(array $value, bool $componentInstalled): bool
    {
        /** @var array{scheduler: array{enabled: bool}} $config */
        $config = $value;

        return $config['scheduler']['enabled'] && !$componentInstalled;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function outboxWithoutStorage(array $value): bool
    {
        /** @var array{outbox: array{enabled: bool, service: ?string}, persistence: array{driver: string}} $config */
        $config = $value;

        return $config['outbox']['enabled'] && $config['outbox']['service'] === null
            && $config['persistence']['driver'] === BundleConfiguration::DRIVER_CUSTOM;
    }

    /**
     * Mirrors the "duplicate header" check of ApiConfiguration, caught at build time (header names are case
     * insensitive). Receives the "headers" node.
     *
     * @param array<array-key, mixed> $value
     */
    public static function duplicateHeaderNames(array $value): bool
    {
        /** @var array{request_id: ?string, application_id: array{name: ?string}} $headers */
        $headers = $value;
        $requestId = $headers['request_id'];
        $applicationId = $headers['application_id']['name'];

        return $requestId !== null && $applicationId !== null && strtolower($requestId) === strtolower($applicationId);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function incompleteApplicationIdHeader(array $value): bool
    {
        /** @var array{name: ?string, value: ?string} $header */
        $header = $value;

        $nameMissing = $header['name'] === null || $header['name'] === '';
        $valueMissing = $header['value'] === null || $header['value'] === '';

        return $nameMissing !== $valueMissing;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function defaultPageAboveMax(array $value): bool
    {
        /** @var array{default_page_size: int, max_page_size: int} $workflow */
        $workflow = $value;

        return $workflow['default_page_size'] > $workflow['max_page_size'];
    }

    /**
     * ADR 0003: a record left "submitting" for longer than "workflow.stale_submission_after" is reconciled (moved to
     * "outcome_unknown"), so a threshold that does not exceed four times "http_timeout" would reconcile submissions
     * that are still in flight: a token call and the API call, each bounded by "http_timeout", and twice because of the
     * single replay after a 401 (ADR 0002, ADR 0003, ADR 0015 amendment lot 6). The threshold is measured by adding it
     * to a fixed instant (see {@see seconds()}), so that "P1M" counts 31 days.
     *
     * An "http_timeout" that is not a number (an "%env(float:...)%" placeholder, resolved at runtime) cannot be compared
     * at build time: the rule is skipped.
     *
     * @param array<array-key, mixed> $value
     */
    public static function staleSubmissionNotAboveHttpTimeout(array $value): bool
    {
        /** @var array{http_timeout: mixed, workflow: array{stale_submission_after: string}} $config */
        $config = $value;
        // @mago-expect analysis:mixed-assignment An environment placeholder is a string while the container is built.
        $timeout = $config['http_timeout'];
        if (!\is_int($timeout) && !\is_float($timeout)) {
            return false;
        }

        return self::seconds($config['workflow']['stale_submission_after']) <= 4 * $timeout;
    }

    /**
     * The "doctrine" driver needs DoctrineBundle (its entity manager services), checked by the bundle while the
     * container is prepared (the per-extension container used when loading cannot tell whether a bundle is registered),
     * ADR 0015 item 4.
     */
    public static function doctrineDriverWithoutBundle(string $driver, bool $bundleRegistered): bool
    {
        return $driver === BundleConfiguration::DRIVER_DOCTRINE && !$bundleRegistered;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function customDriverWithoutRepositories(array $value): bool
    {
        /** @var array{driver: string, account_repository: ?string, mandate_repository: ?string, declaration_record_repository: ?string, payment_record_repository: ?string} $persistence */
        $persistence = $value;

        return $persistence['driver'] === BundleConfiguration::DRIVER_CUSTOM
            && ($persistence['account_repository'] === null
                || $persistence['mandate_repository'] === null
                || $persistence['declaration_record_repository'] === null
                || $persistence['payment_record_repository'] === null);
    }

    /**
     * A host limiter replaces the built-in one: naming it while the limiter is off is a mistake, not a no-op. Receives
     * the "rate_limiter" node.
     *
     * @param array<array-key, mixed> $value
     */
    public static function rateLimiterServiceWhileDisabled(array $value): bool
    {
        /** @var array{enabled: bool, service: ?string} $rateLimiter */
        $rateLimiter = $value;

        return !$rateLimiter['enabled'] && $rateLimiter['service'] !== null;
    }

    /**
     * "060" (the payment order) is never resent, whatever the host believes (ADR 0003).
     */
    public static function isNeverResentOperation(mixed $operation): bool
    {
        return $operation === Operation::Pay->value;
    }

    /**
     * The writes of unspecified idempotency (030, 031, 050, 070, 071) are the only ones that can be declared safe to
     * retry (ADR 0003); mirrors the check of ApiConfiguration.
     */
    public static function isNotRetrySafeWriteCandidate(mixed $operation): bool
    {
        return !\is_string($operation) || Operation::tryFrom($operation)?->retryClass() !== OperationRetryClass::UnspecifiedWrite;
    }

    /**
     * The empty string is the dummy value of an environment variable placeholder while the container is validated:
     * an empty variable fails at runtime instead (Environment::from()).
     */
    public static function isUnknownEnvironment(mixed $environment): bool
    {
        return !\in_array($environment, ['', ...array_map(static fn(Environment $case): string => $case->value, Environment::cases())], true);
    }

    public static function isUnknownTimeZone(mixed $zone): bool
    {
        return !\is_string($zone) || !\in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    public static function isInvalidOptionalInterval(mixed $value): bool
    {
        return $value !== null && self::isInvalidInterval($value);
    }

    public static function isInvalidInterval(mixed $value): bool
    {
        try {
            new DateInterval(\is_string($value) ? $value : '');
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /**
     * A valid ISO 8601 duration of at least one second (measured as {@see seconds()}).
     */
    public static function isInvalidPositiveSecondsInterval(mixed $value): bool
    {
        return !\is_string($value) || self::isInvalidInterval($value) || self::seconds($value) < 1;
    }

    /**
     * Seconds of an ISO 8601 duration, measured by adding it to the fixed instant 2026-01-01T00:00:00+00:00 so that
     * "P1M" counts 31 days whatever the day the container is built. Shared by the stale rule, the validation of
     * "rate_limiter.window" and its conversion to the window of the built-in limiter.
     */
    public static function seconds(string $interval): int
    {
        $origin = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return $origin->add(new DateInterval($interval))->getTimestamp() - $origin->getTimestamp();
    }
}
