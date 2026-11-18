<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\ApiErrorCode;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorClassifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorPayloadParser;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;

/**
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:kan-defect
 */
final class ErrorClassifierTest extends TestCase
{
    private const SEPA = [Operation::RegisterSepaMandate, Operation::RevokeSepaMandate, Operation::ListSepaMandates];
    private const NOT_SEPA = [
        Operation::FindAccount,
        Operation::NotifyMandate,
        Operation::CancelMandate,
        Operation::Estimate,
        Operation::Declare,
        Operation::Pay,
    ];

    /**
     * One representative code per kind that a code can give.
     */
    private const CODE_OF_KIND = [
        'sepa_mandate_already_exists' => 'ERREUR_MANDAT_ALREADYEXIST',
        'mandate_held_elsewhere' => 'SIRET_DEJA_MANDATE',
        'account_unknown' => 'CPTE_INCONNU',
        'no_active_user' => 'ABN_INCONNU',
        'mandate_missing' => 'MDT_INCONNU',
        'mandate_out_of_period' => 'MDT_HORS_PERIODE',
        'period_not_open' => 'APPEL_INCONNU',
        'periodicity_mismatch' => 'PERIODICITE_INVALIDE',
        'invalid_bank_details' => 'ERREUR_IBAN_INVALIDE',
        'unknown_bank' => 'BANQUE_INCONNUE',
        'invalid_parameter' => 'PARAM_INVALIDE',
        'technical' => 'ERR_TECHNIQUE',
    ];

    private static function errors(string ...$codes): ApiErrors
    {
        return ApiErrors::of(...array_map(static fn(string $code): ApiError => ApiError::of($code, null, null), $codes));
    }

    /**
     * @return iterable<string, array{Operation, int, bool}>
     */
    public static function provideRejectionTable(): iterable
    {
        foreach (Operation::cases() as $operation) {
            $sepa = \in_array($operation, self::SEPA, true);

            foreach ([200, 201, 204, 301, 399, 401, 402, 403, 405, 408, 410, 422, 429, 500, 502, 503, 504] as $status) {
                yield $operation->value . ' ' . $status => [$operation, $status, false];
            }

            yield $operation->value . ' 400' => [$operation, 400, true];
            yield $operation->value . ' 404' => [$operation, 404, $sepa];
            yield $operation->value . ' 409' => [$operation, 409, $operation === Operation::RegisterSepaMandate];
        }
    }

    #[DataProvider('provideRejectionTable')]
    public function testRejects(Operation $operation, int $status, bool $expected): void
    {
        static::assertSame($expected, ErrorClassifier::rejects($operation, $status));
    }

    /**
     * @return iterable<string, array{Operation, int}>
     */
    public static function provideNonRejections(): iterable
    {
        foreach (self::provideRejectionTable() as $name => [$operation, $status, $rejects]) {
            if ($rejects) {
                continue;
            }

            yield $name => [$operation, $status];
        }
    }

    #[DataProvider('provideNonRejections')]
    public function testClassifyRefusesWhatIsNotARejection(Operation $operation, int $status): void
    {
        self::assertNotARejection($operation, $status, self::errors('PARAM_INVALIDE'));
        self::assertNotARejection($operation, $status, ApiErrors::none());
    }

    private static function assertNotARejection(Operation $operation, int $status, ApiErrors $errors): void
    {
        $exception = DomainAssertions::assertViolationCode(
            'error_classifier.not_a_rejection',
            static fn(): RejectionKind => ErrorClassifier::classify($operation, $status, $errors),
        );

        static::assertSame('The HTTP status is not a rejection of this operation.', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, RejectionKind}>
     */
    public static function provideFixtureKinds(): iterable
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
        yield 'error_sepa_mandate_already_exists' => ['error_sepa_mandate_already_exists', RejectionKind::SepaMandateAlreadyExists];
        yield 'error_siret_deja_mandate' => ['error_siret_deja_mandate', RejectionKind::MandateHeldElsewhere];
        yield 'error_technique' => ['error_technique', RejectionKind::Technical];
    }

    public function testEveryErrorFixtureIsCovered(): void
    {
        static::assertSame(Fixtures::names('error_'), array_keys(iterator_to_array(self::provideFixtureKinds(), true)));
    }

    #[DataProvider('provideFixtureKinds')]
    public function testEveryErrorFixtureIsClassifiedOnA400(string $name, RejectionKind $expected): void
    {
        $parser = new ErrorPayloadParser();

        foreach ([Fixtures::load($name), '[' . Fixtures::load($name) . ']'] as $body) {
            foreach (Operation::cases() as $operation) {
                static::assertSame($expected, ErrorClassifier::classify($operation, 400, $parser->parseBody($body)), $name);
            }
        }
    }

    /**
     * @return iterable<string, array{string, RejectionKind}>
     */
    public static function provideCodeKinds(): iterable
    {
        yield 'PARAM_INVALIDE' => ['PARAM_INVALIDE', RejectionKind::InvalidParameter];
        yield 'ERR_PARAM_MULTIPLES' => ['ERR_PARAM_MULTIPLES', RejectionKind::InvalidParameter];
        yield 'CPTE_INCONNU' => ['CPTE_INCONNU', RejectionKind::AccountUnknown];
        yield 'ABN_INCONNU' => ['ABN_INCONNU', RejectionKind::NoActiveUser];
        yield 'SIRET_DEJA_MANDATE' => ['SIRET_DEJA_MANDATE', RejectionKind::MandateHeldElsewhere];
        yield 'MDT_INCONNU' => ['MDT_INCONNU', RejectionKind::MandateMissing];
        yield 'MDT_HORS_PERIODE' => ['MDT_HORS_PERIODE', RejectionKind::MandateOutOfPeriod];
        yield 'APPEL_INCONNU' => ['APPEL_INCONNU', RejectionKind::PeriodNotOpen];
        yield 'PERIODICITE_INVALIDE' => ['PERIODICITE_INVALIDE', RejectionKind::PeriodicityMismatch];
        yield 'ERREUR_MANDAT_ALREADYEXIST' => ['ERREUR_MANDAT_ALREADYEXIST', RejectionKind::SepaMandateAlreadyExists];
        yield 'ERREUR_IBAN_INVALIDE' => ['ERREUR_IBAN_INVALIDE', RejectionKind::InvalidBankDetails];
        yield 'ERREUR_RIB_INVALIDE' => ['ERREUR_RIB_INVALIDE', RejectionKind::InvalidBankDetails];
        yield 'BANQUE_INCONNUE' => ['BANQUE_INCONNUE', RejectionKind::UnknownBank];
        yield 'ERR_TECH' => ['ERR_TECH', RejectionKind::Technical];
        yield 'ERR_TECHNIQUE' => ['ERR_TECHNIQUE', RejectionKind::Technical];
        yield 'unknown' => ['SOMETHING_NEW', RejectionKind::Unknown];
        yield 'wrong case' => ['param_invalide', RejectionKind::Unknown];
    }

    #[DataProvider('provideCodeKinds')]
    public function testEveryCodeMapsToItsKind(string $code, RejectionKind $expected): void
    {
        static::assertSame($expected, ErrorClassifier::classify(Operation::Declare, 400, self::errors($code)));
        static::assertSame($expected, ErrorClassifier::classify(Operation::RegisterSepaMandate, 400, self::errors($code)));
    }

    public function testEveryCatalogueCodeIsCovered(): void
    {
        $covered = array_map(static fn(array $row): string => $row[0], iterator_to_array(self::provideCodeKinds(), false));

        foreach (ApiErrorCode::cases() as $case) {
            static::assertContains($case->value, $covered);
        }
    }

    public function testACodeIsNormalisedBeforeItIsMapped(): void
    {
        static::assertSame(
            RejectionKind::MandateMissing,
            ErrorClassifier::classify(Operation::Pay, 400, self::errors(" MDT_ INCONNU\n")),
        );
    }

    public function testNoErrorOrOnlyUnknownCodesIsUnknown(): void
    {
        static::assertSame(RejectionKind::Unknown, ErrorClassifier::classify(Operation::Pay, 400, ApiErrors::none()));
        static::assertSame(RejectionKind::Unknown, ErrorClassifier::classify(Operation::Pay, 400, self::errors('A', 'B')));
    }

    public function testAKnownCodeWinsOverAnUnknownOne(): void
    {
        static::assertSame(RejectionKind::PeriodNotOpen, ErrorClassifier::classify(Operation::Declare, 400, self::errors('A', 'APPEL_INCONNU', 'B')));
        static::assertSame(RejectionKind::PeriodNotOpen, ErrorClassifier::classify(Operation::Declare, 400, self::errors('APPEL_INCONNU', 'A')));
    }

    public function testThePriorityIsTheOrderOfTheKindsWithoutNotFoundAndUnknown(): void
    {
        $expected = array_values(array_filter(
            RejectionKind::cases(),
            static fn(RejectionKind $kind): bool => $kind !== RejectionKind::NotFound && $kind !== RejectionKind::Unknown,
        ));

        static::assertSame($expected, (new ReflectionClass(ErrorClassifier::class))->getConstant('PRIORITY'));
        static::assertSame(array_map(static fn(RejectionKind $kind): string => $kind->value, $expected), array_keys(self::CODE_OF_KIND));
    }

    public function testTheKindWithTheHighestPriorityWinsWhateverTheOrder(): void
    {
        $kinds = array_keys(self::CODE_OF_KIND);

        foreach ($kinds as $i => $higher) {
            foreach (\array_slice($kinds, $i + 1) as $lower) {
                $expected = RejectionKind::from($higher);

                static::assertSame($expected, ErrorClassifier::classify(Operation::Declare, 400, self::errors(self::CODE_OF_KIND[$higher], self::CODE_OF_KIND[$lower])), $higher . ' over ' . $lower);
                static::assertSame($expected, ErrorClassifier::classify(Operation::Declare, 400, self::errors(self::CODE_OF_KIND[$lower], self::CODE_OF_KIND[$higher])), $higher . ' over ' . $lower);
            }
        }
    }

    public function testTheDocumentedPriorityExamples(): void
    {
        static::assertSame(RejectionKind::MandateMissing, ErrorClassifier::classify(Operation::Estimate, 400, self::errors('PARAM_INVALIDE', 'MDT_INCONNU')));
        static::assertSame(RejectionKind::InvalidParameter, ErrorClassifier::classify(Operation::Estimate, 400, self::errors('ERR_TECHNIQUE', 'PARAM_INVALIDE')));
    }

    /**
     * @return iterable<string, array{ApiErrors}>
     */
    public static function provideAnyBody(): iterable
    {
        yield 'none' => [ApiErrors::none()];
        yield 'unknown code' => [self::errors('SOMETHING_NEW')];
        yield 'other known code' => [self::errors('PARAM_INVALIDE')];
        yield 'its own code' => [self::errors('ERREUR_MANDAT_ALREADYEXIST')];
        yield 'technical code' => [self::errors('ERR_TECH')];
        yield 'several codes' => [self::errors('CPTE_INCONNU', 'ERREUR_IBAN_INVALIDE')];
    }

    #[DataProvider('provideAnyBody')]
    public function testA409On070IsAlreadyExistsWhateverTheBody(ApiErrors $errors): void
    {
        static::assertSame(RejectionKind::SepaMandateAlreadyExists, ErrorClassifier::classify(Operation::RegisterSepaMandate, 409, $errors));
    }

    #[DataProvider('provideAnyBody')]
    public function testA404OnASepaOperationIsNotFoundWhateverTheBody(ApiErrors $errors): void
    {
        foreach (self::SEPA as $operation) {
            static::assertSame(RejectionKind::NotFound, ErrorClassifier::classify($operation, 404, $errors), $operation->value);
        }
    }

    #[DataProvider('provideAnyBody')]
    public function testA404OrA409OnAnotherOperationIsNotARejection(ApiErrors $errors): void
    {
        foreach (self::NOT_SEPA as $operation) {
            self::assertNotARejection($operation, 404, $errors);
            self::assertNotARejection($operation, 409, $errors);
        }
        foreach ([Operation::RevokeSepaMandate, Operation::ListSepaMandates] as $operation) {
            self::assertNotARejection($operation, 409, $errors);
        }
    }

    public function testA400IsClassifiedByItsCodesOnEveryOperation(): void
    {
        foreach (Operation::cases() as $operation) {
            static::assertSame(RejectionKind::Unknown, ErrorClassifier::classify($operation, 400, ApiErrors::none()), $operation->value);
            static::assertSame(
                RejectionKind::SepaMandateAlreadyExists,
                ErrorClassifier::classify($operation, 400, self::errors('ERREUR_MANDAT_ALREADYEXIST')),
                $operation->value,
            );
        }
    }
}
