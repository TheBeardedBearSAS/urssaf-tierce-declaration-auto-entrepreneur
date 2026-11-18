<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use Throwable;

/**
 * The failures of method 050 that the settlement tests queue, by name (the columns of the ADR 0009 settlement table).
 */
final class DeclareAnswers
{
    /**
     * Every failure name.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['rejected', 'unknown', 'transport', 'unavailable', 'throttled', 'token', 'rate_limit', 'forbidden', 'credentials', 'not_found', 'domain', 'foreign'];
    }

    public static function failure(string $name): Throwable
    {
        return match ($name) {
            'rejected' => UrssafRequestRejected::withErrors(
                Operation::Declare,
                400,
                ApiErrors::of(ApiError::of('ER_API_050_0010', 'Periode non ouverte', 'Detail')),
                RejectionKind::PeriodNotOpen,
            ),
            'unknown' => OutcomeUnknown::fromUnavailable(UrssafUnavailable::withStatus(Operation::Declare, 500, null, ApiErrors::none())),
            'transport' => OutcomeUnknown::fromUnavailable(UrssafUnavailable::dueToTransportError(Operation::Declare, new RuntimeException('timeout'))),
            'unavailable' => UrssafUnavailable::withStatus(Operation::Declare, 503, 30, ApiErrors::none()),
            'throttled' => UrssafUnavailable::withStatus(Operation::Declare, 429, 5, ApiErrors::none()),
            'token' => UrssafUnavailable::duringAuthentication(Operation::Declare, 503, null, null),
            'rate_limit' => RateLimitExceeded::forOperation(Operation::Declare, 5),
            'forbidden' => UrssafAuthenticationFailed::withStatus(403),
            'credentials' => UrssafAuthenticationFailed::withStatus(401),
            'not_found' => RecordNotFound::withIdentifier('credentials', 'tenant-1'),
            'domain' => InvariantViolation::because('gateway.broken', 'The gateway is broken.'),
            default => new RuntimeException('foreign gateway failure'),
        };
    }
}
