<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\ApiErrorCode;

/**
 * Translates an HTTP error status and URSSAF error codes into a {@see RejectionKind}, so that the application never
 * compares wire strings (ADR 0000, ADR 0005). A code outside the catalogue degrades to {@see RejectionKind::Unknown},
 * never to a wrong meaning.
 *
 * Only some statuses are definitive rejections (see {@see self::rejects()}): the others are authentication,
 * unavailability or unexpected answers, handled by the gateway. A 404 on a SEPA operation and a 409 on 070 are
 * classified by their status, whatever the body (ADR 0002, ADR 0005). Otherwise several errors may be returned
 * together: the kind with the highest priority wins (see {@see self::PRIORITY}).
 */
final class ErrorClassifier
{
    private const BAD_REQUEST = 400;
    private const NOT_FOUND = 404;
    private const CONFLICT = 409;

    /**
     * Highest priority first: the order of {@see RejectionKind::cases()} without `NotFound` and `Unknown`, which are
     * never derived from a code.
     *
     * @var list<RejectionKind>
     */
    private const PRIORITY = [
        RejectionKind::SepaMandateAlreadyExists,
        RejectionKind::MandateHeldElsewhere,
        RejectionKind::AccountUnknown,
        RejectionKind::NoActiveUser,
        RejectionKind::MandateMissing,
        RejectionKind::MandateOutOfPeriod,
        RejectionKind::PeriodNotOpen,
        RejectionKind::PeriodicityMismatch,
        RejectionKind::InvalidBankDetails,
        RejectionKind::UnknownBank,
        RejectionKind::InvalidParameter,
        RejectionKind::Technical,
    ];

    /**
     * True when the status of this operation is a definitive rejection of the request ({@see UrssafRequestRejected}):
     * 400 for every operation, 409 for 070 and 404 for 070, 071 and 075.
     */
    public static function rejects(Operation $operation, int $httpStatus): bool
    {
        return match ($httpStatus) {
            self::BAD_REQUEST => true,
            self::CONFLICT => $operation === Operation::RegisterSepaMandate,
            self::NOT_FOUND => $operation->isSepa(),
            default => false,
        };
    }

    /**
     * @throws InvariantViolation "error_classifier.not_a_rejection" when the status is not a rejection of this
     *                            operation
     */
    public static function classify(Operation $operation, int $httpStatus, ApiErrors $errors): RejectionKind
    {
        if (!self::rejects($operation, $httpStatus)) {
            throw InvariantViolation::because(
                'error_classifier.not_a_rejection',
                'The HTTP status is not a rejection of this operation.',
            );
        }

        return match ($httpStatus) {
            self::CONFLICT => RejectionKind::SepaMandateAlreadyExists,
            self::NOT_FOUND => RejectionKind::NotFound,
            default => self::classifyCodes($errors),
        };
    }

    private static function classifyCodes(ApiErrors $errors): RejectionKind
    {
        $kinds = array_map(self::kindOf(...), $errors->all());

        foreach (self::PRIORITY as $kind) {
            if (\in_array($kind, $kinds, true)) {
                return $kind;
            }
        }

        return RejectionKind::Unknown;
    }

    private static function kindOf(ApiError $error): RejectionKind
    {
        $code = ApiErrorCode::fromCode($error->code());

        return match ($code) {
            null => RejectionKind::Unknown,
            ApiErrorCode::SepaMandateAlreadyExists => RejectionKind::SepaMandateAlreadyExists,
            ApiErrorCode::SiretAlreadyMandated => RejectionKind::MandateHeldElsewhere,
            ApiErrorCode::AccountUnknown => RejectionKind::AccountUnknown,
            ApiErrorCode::NoActiveUser => RejectionKind::NoActiveUser,
            ApiErrorCode::MandateUnknown => RejectionKind::MandateMissing,
            ApiErrorCode::MandateOutOfPeriod => RejectionKind::MandateOutOfPeriod,
            ApiErrorCode::PeriodNotOpen => RejectionKind::PeriodNotOpen,
            ApiErrorCode::PeriodicityInvalid => RejectionKind::PeriodicityMismatch,
            ApiErrorCode::IbanInvalid, ApiErrorCode::RibInvalid => RejectionKind::InvalidBankDetails,
            ApiErrorCode::BankUnknown => RejectionKind::UnknownBank,
            ApiErrorCode::InvalidParameter, ApiErrorCode::MultipleIdentifiers => RejectionKind::InvalidParameter,
            ApiErrorCode::SepaTechnicalError, ApiErrorCode::TechnicalError => RejectionKind::Technical,
        };
    }
}
