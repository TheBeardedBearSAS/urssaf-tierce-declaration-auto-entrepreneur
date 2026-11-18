<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use DateTimeZone;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OperationRetryClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;

/**
 * The columns of the retry-class matrix (ADR 0003): every operation, with the unspecified writes also listed in
 * `retrySafeWrites`. The expectation is written from the operation class, not from `ApiConfiguration`.
 */
final class RetryMatrix
{
    public const RETRYABLE = 'retryable';
    public const UNKNOWN = 'unknown';

    private function __construct() {}

    /**
     * @return iterable<string, array{Operation, ApiConfiguration, string}> operation, configuration, expectation
     */
    public static function columns(): iterable
    {
        foreach (Operation::cases() as $operation) {
            $retryClass = $operation->retryClass();

            yield $operation->value . ' unlisted' => [
                $operation,
                self::configuration([]),
                $retryClass === OperationRetryClass::Safe ? self::RETRYABLE : self::UNKNOWN,
            ];

            if ($retryClass === OperationRetryClass::UnspecifiedWrite) {
                yield $operation->value . ' listed' => [$operation, self::configuration([$operation]), self::RETRYABLE];
            }
        }
    }

    /**
     * @param list<Operation> $retrySafeWrites
     */
    public static function configuration(array $retrySafeWrites): ApiConfiguration
    {
        return new ApiConfiguration(
            Environment::Sandbox,
            null,
            null,
            null,
            ApiConfiguration::DEFAULT_TOKEN_SAFETY_MARGIN_SECONDS,
            ApiConfiguration::DEFAULT_USER_AGENT,
            new DateTimeZone('UTC'),
            $retrySafeWrites,
        );
    }
}
