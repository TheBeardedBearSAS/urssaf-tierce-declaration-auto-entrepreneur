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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use Throwable;

/**
 * Converts the errors reported by URSSAF, and the failures stored on a record, into Domain {@see SubmissionError}s.
 *
 * A foreign message is never stored, only its class (ADR 0010 item 5). The URSSAF message and description are kept in the
 * {@see SubmissionError}, which masks them.
 */
final class UrssafErrorTranslator
{
    public const REJECTED_WITHOUT_ERRORS = 'urssaf.rejected';
    public const OUTCOME_UNKNOWN = 'urssaf.outcome_unknown';
    public const UNAVAILABLE = 'urssaf.unavailable';
    public const UNEXPECTED_FAILURE = 'urssaf.unexpected_failure';

    private const KIND_TECHNICAL = 'technical';
    private const KIND_UNKNOWN = 'unknown';

    /**
     * @return list<SubmissionError> one per URSSAF error: its code, the kind, its message and description
     */
    public static function submissionErrors(ApiErrors $errors, RejectionKind $kind): array
    {
        return array_map(
            static fn(ApiError $error): SubmissionError => SubmissionError::of(
                $error->code(),
                $kind->value,
                $error->message(),
                $error->description(),
            ),
            $errors->all(),
        );
    }

    /**
     * @return list<SubmissionError> never empty
     */
    public static function rejectionErrors(UrssafRequestRejected $rejection): array
    {
        if (!$rejection->errors()->isEmpty()) {
            return self::submissionErrors($rejection->errors(), $rejection->kind());
        }

        return [
            SubmissionError::of(self::REJECTED_WITHOUT_ERRORS, $rejection->kind()->value, 'URSSAF refused the call without error details.', null),
        ];
    }

    /**
     * The errors stored with the "error" and "outcome_unknown" states.
     *
     * @return list<SubmissionError>
     */
    public static function failureErrors(Throwable $failure): array
    {
        return match (true) {
            $failure instanceof OutcomeUnknown => self::unknownOutcomeErrors($failure),
            $failure instanceof UrssafUnavailable => self::unavailableErrors($failure),
            $failure instanceof RateLimitExceeded => self::classOnly(self::UNAVAILABLE, self::KIND_TECHNICAL, $failure),
            $failure instanceof DomainException => self::classOnly($failure->violationCode(), self::KIND_UNKNOWN, $failure),
            default => self::classOnly(self::UNEXPECTED_FAILURE, self::KIND_UNKNOWN, $failure),
        };
    }

    /**
     * @return list<SubmissionError>
     */
    private static function unknownOutcomeErrors(OutcomeUnknown $failure): array
    {
        if (!$failure->errors()->isEmpty()) {
            return self::submissionErrors($failure->errors(), RejectionKind::Technical);
        }

        return [SubmissionError::of(self::OUTCOME_UNKNOWN, self::KIND_TECHNICAL, $failure->cause()->value, null)];
    }

    /**
     * @return list<SubmissionError>
     */
    private static function unavailableErrors(UrssafUnavailable $failure): array
    {
        if (!$failure->errors()->isEmpty()) {
            return self::submissionErrors($failure->errors(), RejectionKind::Technical);
        }

        return self::classOnly(self::UNAVAILABLE, self::KIND_TECHNICAL, $failure);
    }

    /**
     * A foreign failure is stored by its class only (ADR 0010 item 5).
     *
     * @return list<SubmissionError>
     */
    private static function classOnly(string $code, string $kind, Throwable $failure): array
    {
        return [SubmissionError::of($code, $kind, $failure::class, null)];
    }
}
