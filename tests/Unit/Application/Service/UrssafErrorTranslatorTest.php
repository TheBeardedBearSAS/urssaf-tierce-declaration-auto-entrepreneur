<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UrssafErrorTranslator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;

final class UrssafErrorTranslatorTest extends TestCase
{
    public function testOneSubmissionErrorPerUrssafError(): void
    {
        $errors = ApiErrors::of(
            ApiError::of('ER_API_050_0070', 'Mandat inconnu', 'Detail'),
            ApiError::of('ER_API_050_0071', null, null),
        );

        $translated = UrssafErrorTranslator::submissionErrors($errors, RejectionKind::MandateMissing);

        static::assertSame(
            [
                ['ER_API_050_0070', 'mandate_missing', 'Mandat inconnu', 'Detail'],
                ['ER_API_050_0071', 'mandate_missing', null, null],
            ],
            self::flatten($translated),
        );
        static::assertSame([], UrssafErrorTranslator::submissionErrors(ApiErrors::none(), RejectionKind::Unknown));
    }

    public function testARejectionWithErrorsKeepsThem(): void
    {
        $rejection = UrssafRequestRejected::withErrors(
            Operation::Declare,
            400,
            ApiErrors::of(ApiError::of('ER_API_050_0070', 'Mandat inconnu', null)),
            RejectionKind::PeriodNotOpen,
        );

        static::assertSame([['ER_API_050_0070', 'period_not_open', 'Mandat inconnu', null]], self::flatten(UrssafErrorTranslator::rejectionErrors($rejection)));
    }

    public function testARejectionWithoutErrorsStillStoresOne(): void
    {
        $rejection = UrssafRequestRejected::withErrors(Operation::Pay, 400, ApiErrors::none(), RejectionKind::InvalidBankDetails);

        static::assertSame(
            [['urssaf.rejected', 'invalid_bank_details', 'URSSAF refused the call without error details.', null]],
            self::flatten(UrssafErrorTranslator::rejectionErrors($rejection)),
        );
    }

    public function testAnUnknownOutcomeWithErrorsKeepsThemAsTechnical(): void
    {
        $unavailable = UrssafUnavailable::withStatus(Operation::Pay, 503, null, ApiErrors::of(ApiError::of('ER_API_060_0500', 'Indisponible', 'Detail')));

        static::assertSame(
            [['ER_API_060_0500', 'technical', 'Indisponible', 'Detail']],
            self::flatten(UrssafErrorTranslator::failureErrors(OutcomeUnknown::fromUnavailable($unavailable))),
        );
    }

    public function testAnUnknownOutcomeWithoutErrorsStoresItsCause(): void
    {
        $transport = OutcomeUnknown::fromUnavailable(UrssafUnavailable::dueToTransportError(Operation::Pay, new RuntimeException('secret reset')));
        $server = OutcomeUnknown::fromUnavailable(UrssafUnavailable::withStatus(Operation::Pay, 502, null, ApiErrors::none()));
        $unreadable = OutcomeUnknown::afterUnreadableResponse(Operation::Pay, 200, $server);

        static::assertSame([['urssaf.outcome_unknown', 'technical', 'transport', null]], self::flatten(UrssafErrorTranslator::failureErrors($transport)));
        static::assertSame([['urssaf.outcome_unknown', 'technical', 'server_error', null]], self::flatten(UrssafErrorTranslator::failureErrors($server)));
        static::assertSame([['urssaf.outcome_unknown', 'technical', 'unreadable_response', null]], self::flatten(UrssafErrorTranslator::failureErrors($unreadable)));
    }

    public function testAnUnavailableUrssafWithErrorsKeepsThemAsTechnical(): void
    {
        $unavailable = UrssafUnavailable::withStatus(Operation::Declare, 503, 30, ApiErrors::of(ApiError::of('ER_API_050_0500', 'Indisponible', null)));

        static::assertSame([['ER_API_050_0500', 'technical', 'Indisponible', null]], self::flatten(UrssafErrorTranslator::failureErrors($unavailable)));
    }

    public function testAnUnavailableUrssafWithoutErrorsStoresItsClass(): void
    {
        $unavailable = UrssafUnavailable::withStatus(Operation::Declare, 503, 30, ApiErrors::none());
        $limited = RateLimitExceeded::forOperation(Operation::Declare, 5);

        static::assertSame([['urssaf.unavailable', 'technical', UrssafUnavailable::class, null]], self::flatten(UrssafErrorTranslator::failureErrors($unavailable)));
        static::assertSame([['urssaf.unavailable', 'technical', RateLimitExceeded::class, null]], self::flatten(UrssafErrorTranslator::failureErrors($limited)));
    }

    public function testADomainExceptionStoresItsViolationCodeAndClass(): void
    {
        $failure = RecordNotFound::withIdentifier('credentials', 'tenant-1');

        static::assertSame([['credentials.not_found', 'unknown', RecordNotFound::class, null]], self::flatten(UrssafErrorTranslator::failureErrors($failure)));
    }

    public function testAForeignFailureStoresItsClassAndNeverItsMessage(): void
    {
        $failure = new LogicException('FR7630001007941234567890185 secret');

        $translated = UrssafErrorTranslator::failureErrors($failure);

        static::assertSame([['urssaf.unexpected_failure', 'unknown', LogicException::class, null]], self::flatten($translated));
        static::assertStringNotContainsString('secret', serialize(self::flatten($translated)));
    }

    /**
     * @param list<SubmissionError> $errors
     *
     * @return list<array{string, string, string|null, string|null}>
     */
    private static function flatten(array $errors): array
    {
        return array_map(
            static fn(SubmissionError $error): array => [$error->code(), $error->kind(), $error->message(), $error->description()],
            $errors,
        );
    }
}
