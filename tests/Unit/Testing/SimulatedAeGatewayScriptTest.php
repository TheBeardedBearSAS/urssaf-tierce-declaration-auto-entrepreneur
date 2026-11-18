<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknownCause;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\ApiErrorCode;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorClassifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;

/**
 * The scripting API of the simulator: failures, refusals and lost answers.
 *
 * @mago-expect lint:too-many-methods One test per scripting path.
 */
final class SimulatedAeGatewayScriptTest extends TestCase
{
    public function testAScriptedFailureIsThrownBeforeAnythingIsProcessed(): void
    {
        $gateway = self::gateway();
        $failure = UrssafUnavailable::withStatus(Operation::NotifyMandate, 503, null, ApiErrors::none());
        $gateway->failNext(Operation::NotifyMandate, $failure);

        $thrown = SimulatorSamples::failure(
            static fn() => $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()),
        );

        static::assertSame($failure, $thrown);
        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
        static::assertSame(['030'], $gateway->calls());
    }

    public function testScriptedFailuresAreThrownInOrderThenTheRulesApply(): void
    {
        $gateway = self::gateway();
        $first = UrssafAuthenticationFailed::withStatus(401);
        $second = UrssafUnavailable::withStatus(Operation::FindAccount, 503, null, ApiErrors::none());
        $gateway->failNext(Operation::FindAccount, $first);
        $gateway->failNext(Operation::FindAccount, $second);

        $one = SimulatorSamples::failure(static fn(): AccountStatus => $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret()));
        $two = SimulatorSamples::failure(static fn(): AccountStatus => $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret()));
        $status = $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertSame($first, $one);
        static::assertSame($second, $two);
        static::assertFalse($status->mandatePresent());
    }

    public function testAFailureConcernsOnlyItsOperation(): void
    {
        $gateway = self::gateway();
        $gateway->failNext(Operation::Pay, UrssafUnavailable::withStatus(Operation::Pay, 503, null, ApiErrors::none()));

        $status = $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertFalse($status->mandatePresent());
    }

    public function testAScriptedRefusalReplacesTheRules(): void
    {
        $gateway = self::gateway();
        $gateway->refuseNext(Operation::FindAccount, ApiErrorCode::NoActiveUser->value, 'Aucun utilisateur', 'Description');

        $refusal = SimulatorSamples::refusal(static fn(): AccountStatus => $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret()));

        static::assertSame(400, $refusal->httpStatus());
        static::assertSame(Operation::FindAccount, $refusal->operation());
        static::assertSame(RejectionKind::NoActiveUser, $refusal->kind());
        static::assertSame(['ABN_INCONNU'], $refusal->errors()->codes());
        $error = $refusal->errors()->first();
        static::assertNotNull($error);
        static::assertSame('Aucun utilisateur', $error->message());
        static::assertSame('Description', $error->description());
        static::assertFalse($gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret())->mandatePresent());
    }

    public function testRefusalsAreAnsweredInOrderAndWithoutADescription(): void
    {
        $gateway = self::gateway();
        $gateway->refuseNext(Operation::Estimate, ApiErrorCode::InvalidParameter->value, 'First', null);
        $gateway->refuseNext(Operation::Estimate, ApiErrorCode::TechnicalError->value, 'Second', 'Detail');
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $first = SimulatorSamples::refusal(static fn(): mixed => SimulatorSamples::call($gateway, Operation::Estimate));
        $second = SimulatorSamples::refusal(static fn(): mixed => SimulatorSamples::call($gateway, Operation::Estimate));

        static::assertSame(RejectionKind::InvalidParameter, $first->kind());
        static::assertNull($first->errors()->first()?->description());
        static::assertSame(RejectionKind::Technical, $second->kind());
        static::assertSame('Second', $second->errors()->first()?->message());
        static::assertSame(220, $gateway->estimateContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::turnover())->amounts()->total()->toInt());
    }

    public function testAScriptedFailureWinsOverAScriptedRefusal(): void
    {
        $gateway = self::gateway();
        $failure = UrssafUnavailable::withStatus(Operation::FindAccount, 503, null, ApiErrors::none());
        $gateway->refuseNext(Operation::FindAccount, ApiErrorCode::NoActiveUser->value, 'Refused', null);
        $gateway->failNext(Operation::FindAccount, $failure);

        $thrown = SimulatorSamples::failure(static fn(): AccountStatus => $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret()));
        $refusal = SimulatorSamples::refusal(static fn(): AccountStatus => $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret()));

        static::assertSame($failure, $thrown);
        static::assertSame(['ABN_INCONNU'], $refusal->errors()->codes());
    }

    public function testALostAnswerKeepsTheStateChange(): void
    {
        $gateway = self::gateway();
        $gateway->loseNextAnswer(Operation::NotifyMandate);

        $thrown = SimulatorSamples::failure(static fn() => $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()));

        static::assertInstanceOf(OutcomeUnknown::class, $thrown);
        static::assertSame(Operation::NotifyMandate, $thrown->operation());
        static::assertSame(504, $thrown->httpStatus());
        static::assertSame(OutcomeUnknownCause::ServerError, $thrown->cause());
        static::assertTrue($thrown->errors()->isEmpty());
        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
    }

    public function testALostAnswerOnADeclarationConsumesItsIdentifier(): void
    {
        $gateway = self::gateway();
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());
        $gateway->loseNextAnswer(Operation::Declare);

        $thrown = SimulatorSamples::failure(static fn(): mixed => SimulatorSamples::call($gateway, Operation::Declare));
        $next = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declaration());

        static::assertInstanceOf(OutcomeUnknown::class, $thrown);
        static::assertSame('SIMDECL0000000000000002', $next->declarationId());
        static::assertSame('00000001', $next->certificate()->value());
    }

    public function testALostAnswerOnAPaymentKeepsItsCertificate(): void
    {
        $gateway = self::gateway();
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());
        $gateway->loseNextAnswer(Operation::Pay);

        $thrown = SimulatorSamples::failure(static fn(): mixed => SimulatorSamples::call($gateway, Operation::Pay));
        $next = $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::order());

        static::assertInstanceOf(OutcomeUnknown::class, $thrown);
        static::assertSame('SIMPAY00000000000000002', $next->paymentId());
    }

    public function testALostAnswerOnASepaWriteIsAllowed(): void
    {
        $gateway = self::gateway();
        $gateway->loseNextAnswer(Operation::RegisterSepaMandate);
        $gateway->loseNextAnswer(Operation::RevokeSepaMandate);
        $gateway->loseNextAnswer(Operation::CancelMandate);
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $registered = SimulatorSamples::failure(static fn(): SepaMandate => $gateway->registerSepaMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::newMandate()));
        $revoked = SimulatorSamples::failure(static fn() => $gateway->revokeSepaMandate(SimulatorSamples::tenant(), SepaRevocation::of(SimulatorSamples::siret(), SimulatorSamples::rum('SIMRUM00000001'), null)));
        $cancelled = SimulatorSamples::failure(static fn() => $gateway->cancelMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()));

        static::assertInstanceOf(OutcomeUnknown::class, $registered);
        static::assertInstanceOf(OutcomeUnknown::class, $revoked);
        static::assertInstanceOf(OutcomeUnknown::class, $cancelled);
        static::assertSame([], $gateway->listSepaMandates(SimulatorSamples::tenant(), SimulatorSamples::siret()));
        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
    }

    public function testALostAnswerSwallowsARefusal(): void
    {
        $gateway = self::gateway();
        $gateway->loseNextAnswer(Operation::NotifyMandate);
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::otherTenant());

        $thrown = SimulatorSamples::failure(static fn() => $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()));

        static::assertInstanceOf(OutcomeUnknown::class, $thrown);
        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::otherTenant()));
    }

    public function testALostAnswerSwallowsAScriptedRefusal(): void
    {
        $gateway = self::gateway();
        $gateway->loseNextAnswer(Operation::NotifyMandate);
        $gateway->refuseNext(Operation::NotifyMandate, ApiErrorCode::InvalidParameter->value, 'Refused', null);

        $thrown = SimulatorSamples::failure(static fn() => $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference()));
        static::assertInstanceOf(OutcomeUnknown::class, $thrown);
        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));

        $gateway->notifyMandate(SimulatorSamples::tenant(), SimulatorSamples::reference());

        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
    }

    public function testEachLostAnswerIsConsumedOnce(): void
    {
        $gateway = self::gateway();
        $gateway->loseNextAnswer(Operation::NotifyMandate);
        $gateway->loseNextAnswer(Operation::NotifyMandate);

        $first = SimulatorSamples::failure(static fn(): mixed => SimulatorSamples::call($gateway, Operation::NotifyMandate));
        $second = SimulatorSamples::failure(static fn(): mixed => SimulatorSamples::call($gateway, Operation::NotifyMandate));
        SimulatorSamples::call($gateway, Operation::NotifyMandate);

        static::assertInstanceOf(OutcomeUnknown::class, $first);
        static::assertInstanceOf(OutcomeUnknown::class, $second);
        static::assertSame(['030', '030', '030'], $gateway->calls());
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function provideSafeOperations(): iterable
    {
        yield '020' => [Operation::FindAccount];
        yield '040' => [Operation::Estimate];
        yield '075' => [Operation::ListSepaMandates];
    }

    #[DataProvider('provideSafeOperations')]
    public function testAnAnswerCannotBeLostOnASafeOperation(Operation $operation): void
    {
        $gateway = self::gateway();

        $failure = SimulatorSamples::failure(static fn() => $gateway->loseNextAnswer($operation));

        static::assertInstanceOf(LogicException::class, $failure);
        static::assertSame(
            \sprintf('Only the writes 030, 031, 050, 060, 070 and 071 have an unknown outcome; method %s does not.', $operation->value),
            $failure->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function provideOperations(): iterable
    {
        foreach (Operation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }

    #[DataProvider('provideOperations')]
    public function testScriptedRefusalsAreClassifiedLikeTheRealApi(Operation $operation): void
    {
        $gateway = self::gateway();
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());
        $errors = ApiErrors::of(ApiError::of('PARAM_INVALIDE', 'Message', null));
        $gateway->refuseNext($operation, 'PARAM_INVALIDE', 'Message', null);

        $refusal = SimulatorSamples::refusal(static fn(): mixed => SimulatorSamples::call($gateway, $operation));

        static::assertSame(ErrorClassifier::classify($operation, 400, $errors), $refusal->kind());
    }

    public function testTheCallsAreRecordedInOrderWhateverTheirOutcome(): void
    {
        $gateway = self::gateway();
        $gateway->failNext(Operation::Estimate, UrssafUnavailable::withStatus(Operation::Estimate, 503, null, ApiErrors::none()));
        $gateway->refuseNext(Operation::Pay, 'PARAM_INVALIDE', 'Refused', null);
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());
        $failure = SimulatorSamples::failure(static fn(): mixed => SimulatorSamples::call($gateway, Operation::Estimate));
        $refusal = SimulatorSamples::refusal(static fn(): mixed => SimulatorSamples::call($gateway, Operation::Pay));

        static::assertInstanceOf(UrssafUnavailable::class, $failure);
        static::assertSame(Operation::Pay, $refusal->operation());
        static::assertSame(['020', '040', '060'], $gateway->calls());
    }

    private static function gateway(): SimulatedAeGateway
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());

        return $gateway;
    }
}
