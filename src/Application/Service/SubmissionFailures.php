<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use Throwable;

/**
 * Maps a failure of a call of 050 or 060 to its settlement column of ADR 0009 (amendment of 2026-11-18).
 *
 * Only what is proven to have been refused before anything was sent is retried; every doubt is an unknown outcome.
 */
final class SubmissionFailures
{
    public const CREDENTIALS_NOT_FOUND = 'credentials.not_found';

    /**
     * @param bool $mayResend true for 050 (the gateway already decided from "retry_safe_writes"), false for 060 (never resent)
     *
     * @mago-expect lint:no-boolean-flag-parameter The retry class is decided by the operation (ADR 0003).
     */
    public static function classify(Throwable $failure, bool $mayResend): SubmissionFailureKind
    {
        return match (true) {
            $failure instanceof UrssafRequestRejected => SubmissionFailureKind::Rejected,
            $failure instanceof UrssafAuthenticationFailed => SubmissionFailureKind::InvalidCredentials,
            $failure instanceof OutcomeUnknown => SubmissionFailureKind::OutcomeUnknown,
            $failure instanceof UrssafUnavailable && $failure->mayHaveReachedUrssaf() => $mayResend
                ? SubmissionFailureKind::Resendable
                : SubmissionFailureKind::OutcomeUnknown,
            $failure instanceof UrssafUnavailable, $failure instanceof RateLimitExceeded => SubmissionFailureKind::NotSent,
            $failure instanceof RecordNotFound && $failure->violationCode() === self::CREDENTIALS_NOT_FOUND => SubmissionFailureKind::LocalRefusal,
            default => SubmissionFailureKind::Unexpected,
        };
    }
}
