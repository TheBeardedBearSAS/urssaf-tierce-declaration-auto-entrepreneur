<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\ClientCalls;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use Throwable;

/**
 * Status table of the client (ADR 0002, ADR 0003) on a read (020) and on the SEPA statuses of 070, 071 and 075.
 *
 * One method per status family.
 *
 * @mago-expect lint:too-many-methods
 */
final class JsonApiClientStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function provideSuccessStatuses(): iterable
    {
        yield '200' => [200];
        yield '201' => [201];
        yield '204' => [204];
        yield '299' => [299];
    }

    #[DataProvider('provideSuccessStatuses')]
    public function testTwoHundredStatusesKeepTheStatusAndTheRawBody(int $status): void
    {
        $response = ClientCalls::answer(Operation::FindAccount, $status, '{"k":"v"}');

        static::assertInstanceOf(ApiResponse::class, $response);
        static::assertSame($status, $response->status());
        static::assertSame('{"k":"v"}', $response->body());
    }

    public function testASuccessIsNotDecodedByTheClient(): void
    {
        $response = ClientCalls::answer(Operation::FindAccount, 200, 'not json');

        static::assertInstanceOf(ApiResponse::class, $response);
        static::assertSame('not json', $response->body());
    }

    /**
     * @return iterable<string, array{string, RejectionKind}>
     */
    public static function provideErrorFixtures(): iterable
    {
        yield 'error_abn_inconnu' => ['error_abn_inconnu', RejectionKind::NoActiveUser];
        yield 'error_appel_inconnu' => ['error_appel_inconnu', RejectionKind::PeriodNotOpen];
        yield 'error_cpte_inconnu' => ['error_cpte_inconnu', RejectionKind::AccountUnknown];
        yield 'error_mdt_hors_periode' => ['error_mdt_hors_periode', RejectionKind::MandateOutOfPeriod];
        yield 'error_mdt_inconnu' => ['error_mdt_inconnu', RejectionKind::MandateMissing];
        yield 'error_param_invalide' => ['error_param_invalide', RejectionKind::InvalidParameter];
        yield 'error_param_multiples' => ['error_param_multiples', RejectionKind::InvalidParameter];
        yield 'error_periodicite_invalide' => ['error_periodicite_invalide', RejectionKind::PeriodicityMismatch];
        yield 'error_sepa_generic' => ['error_sepa_generic', RejectionKind::Technical];
        yield 'error_sepa_mandate_already_exists' => [
            'error_sepa_mandate_already_exists',
            RejectionKind::SepaMandateAlreadyExists,
        ];
        yield 'error_siret_deja_mandate' => ['error_siret_deja_mandate', RejectionKind::MandateHeldElsewhere];
        yield 'error_technique' => ['error_technique', RejectionKind::Technical];
    }

    #[DataProvider('provideErrorFixtures')]
    public function testA400IsClassifiedByItsErrorCode(string $fixture, RejectionKind $kind): void
    {
        $exception = ClientCalls::answer(Operation::FindAccount, 400, Fixtures::load($fixture));

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertSame(400, $exception->httpStatus());
        static::assertSame(Operation::FindAccount, $exception->operation());
        static::assertSame($kind, $exception->kind());
        static::assertFalse($exception->errors()->isEmpty());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEmptyOrUnreadableErrorBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'html' => ['<html>Bad Request</html>'];
    }

    #[DataProvider('provideEmptyOrUnreadableErrorBodies')]
    public function testA400WithoutAReadableBodyIsARejectionWithoutErrors(string $body): void
    {
        $exception = ClientCalls::answer(Operation::FindAccount, 400, $body);

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertTrue($exception->errors()->isEmpty());
        static::assertSame(RejectionKind::Unknown, $exception->kind());
    }

    /**
     * @return iterable<string, array{Operation, int, RejectionKind}>
     */
    public static function provideSepaStatuses(): iterable
    {
        yield '075 404' => [Operation::ListSepaMandates, 404, RejectionKind::NotFound];
        yield '071 404' => [Operation::RevokeSepaMandate, 404, RejectionKind::NotFound];
        yield '070 404' => [Operation::RegisterSepaMandate, 404, RejectionKind::NotFound];
        yield '070 409' => [Operation::RegisterSepaMandate, 409, RejectionKind::SepaMandateAlreadyExists];
    }

    #[DataProvider('provideSepaStatuses')]
    public function testSepaStatusesAreClassifiedWithoutABody(Operation $operation, int $status, RejectionKind $kind): void
    {
        $exception = ClientCalls::answer($operation, $status);

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertSame($status, $exception->httpStatus());
        static::assertSame($operation, $exception->operation());
        static::assertSame($kind, $exception->kind());
    }

    public function testA409OnTheSepaRegistrationKeepsItsBody(): void
    {
        $exception = ClientCalls::answer(
            Operation::RegisterSepaMandate,
            409,
            Fixtures::load('error_sepa_mandate_already_exists'),
        );

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertTrue($exception->is(RejectionKind::SepaMandateAlreadyExists));
        static::assertSame(['ERREUR_MANDAT_ALREADYEXIST'], $exception->errors()->codes());
    }

    /**
     * @return iterable<string, array{Operation, int}>
     */
    public static function provideNonRejectionSepaStatuses(): iterable
    {
        yield '020 404' => [Operation::FindAccount, 404];
        yield '040 404' => [Operation::Estimate, 404];
        yield '050 404' => [Operation::Declare, 404];
        yield '060 404' => [Operation::Pay, 404];
        yield '030 404' => [Operation::NotifyMandate, 404];
        yield '031 404' => [Operation::CancelMandate, 404];
        yield '020 409' => [Operation::FindAccount, 409];
        yield '050 409' => [Operation::Declare, 409];
        yield '071 409' => [Operation::RevokeSepaMandate, 409];
        yield '075 409' => [Operation::ListSepaMandates, 409];
    }

    #[DataProvider('provideNonRejectionSepaStatuses')]
    public function testA404Or409OutsideTheSepaRulesIsUnexpected(Operation $operation, int $status): void
    {
        $exception = ClientCalls::answer($operation, $status);

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame($status, $exception->getCode());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideAuthenticationStatuses(): iterable
    {
        yield 'unauthorized after the replay' => [401];
        yield 'forbidden' => [403];
    }

    #[DataProvider('provideAuthenticationStatuses')]
    public function testSecurityStatusesFailAuthentication(int $status): void
    {
        $exception = ClientCalls::answer(Operation::FindAccount, $status);

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame($status, $exception->httpStatus());
    }

    /**
     * @return iterable<string, array{int, array<string, string>, int|null}>
     */
    public static function provideRetryableStatuses(): iterable
    {
        yield 'quota with seconds' => [429, ['Retry-After' => '12'], 12];
        yield 'quota without header' => [429, [], null];
        yield 'quota with http date' => [429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null];
        yield 'technical error' => [500, [], null];
        yield 'bad gateway' => [502, ['Retry-After' => '1'], 1];
        yield 'unavailable with seconds' => [503, ['Retry-After' => '3'], 3];
        yield 'gateway timeout' => [504, [], null];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('provideRetryableStatuses')]
    public function testQuotaAndListedServerErrorsAreRetryable(int $status, array $headers, ?int $retryAfter): void
    {
        $exception = ClientCalls::answer(Operation::FindAccount, $status, Fixtures::load('error_technique'), $headers);

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertSame($status, $exception->httpStatus());
        static::assertSame($retryAfter, $exception->retryAfterSeconds());
        static::assertSame($status === 429, $exception->isQuotaExceeded());
        static::assertSame(Operation::FindAccount, $exception->operation());
        static::assertSame($status !== 429, $exception->mayHaveReachedUrssaf());
        static::assertFalse($exception->whileAuthenticating());
        static::assertSame(['ERR_TECHNIQUE'], $exception->errors()->codes());
    }

    public function testAnUnreadableErrorBodyGivesNoErrors(): void
    {
        $exception = ClientCalls::answer(Operation::FindAccount, 503, '<html>');

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertTrue($exception->errors()->isEmpty());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideUnexpectedStatuses(): iterable
    {
        yield 'informational' => [199];
        yield 'multiple choices' => [300];
        yield 'redirect' => [302];
        yield 'not modified' => [304];
        yield 'payment required' => [402];
        yield 'method not allowed' => [405];
        yield 'teapot' => [418];
        yield 'unprocessable' => [422];
        yield 'not implemented' => [501];
        yield 'http version' => [505];
        yield 'security gateway' => [512];
        yield 'last 5xx' => [599];
        yield 'out of range' => [600];
    }

    #[DataProvider('provideUnexpectedStatuses')]
    public function testAnyOtherStatusIsUnexpectedOnASafeOperation(int $status): void
    {
        $exception = ClientCalls::answer(Operation::FindAccount, $status, '{}');

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame($status, $exception->getCode());
        static::assertStringContainsString(\sprintf('HTTP %d', $status), $exception->getMessage());
    }

    public function testTheFiveHundredTwelveIsNotSpecial(): void
    {
        $safe = ClientCalls::answer(Operation::FindAccount, 512);
        $pay = ClientCalls::answer(Operation::Pay, 512);

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $safe);
        static::assertInstanceOf(OutcomeUnknown::class, $pay);
    }

    public function testNoStatusOfASafeOperationBecomesAnUnknownOutcome(): void
    {
        foreach ([400, 401, 403, 429, 500, 501, 503, 512, 599] as $status) {
            $exception = ClientCalls::answer(Operation::Estimate, $status);

            static::assertInstanceOf(Throwable::class, $exception);
            static::assertNotInstanceOf(OutcomeUnknown::class, $exception, 'HTTP ' . $status);
        }
    }
}
