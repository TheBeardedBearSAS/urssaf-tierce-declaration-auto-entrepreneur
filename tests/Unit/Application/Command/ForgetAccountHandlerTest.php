<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ForgetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ForgetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\AccountErasureRefused;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\LateAccounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RacingMandates;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RacingPayments;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RacingRecords;

/**
 * @mago-expect lint:too-many-methods One test per refusal, opt-in, race and shape of the erasure (ADR 0017).
 * @mago-expect lint:cyclomatic-complexity The same table of cases.
 */
final class ForgetAccountHandlerTest extends TestCase
{
    // -- the erasure

    public function testTheAccountItsMandatesAndItsRecordsAreErased(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->cancelledMandate('mandate-1');
        $world->activeMandateOf($account, 'mandate-2');
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Declared, 9), AccountWorld::declarationRecord('declaration-2', DeclarationState::Rejected, 10));
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::Paid, 9));

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-1')), true, null));

        static::assertSame('account-1', $erasure->accountId);
        static::assertSame(['mandate-1', 'mandate-2'], $erasure->mandateIds);
        static::assertSame(['declaration-1', 'declaration-2'], $erasure->declarationRecordIds);
        static::assertSame(['payment-1'], $erasure->paymentRecordIds);
        static::assertSame(
            [
                'begin',
                'deleteBySiret:2',
                'deleteBySiret:1',
                'delete:mandate-1',
                'delete:mandate-2',
                'delete:account-1',
                'append:urssaf.tdae.account.forgotten',
                'commit',
                'publish:AccountForgotten',
            ],
            $world->journal->entries(),
        );
        static::assertNull($world->accounts->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame([], $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame(0, array_sum($world->declarations->countBySiret(AccountWorld::tenant(), AccountWorld::siret())));
        static::assertSame(0, array_sum($world->payments->countBySiret(AccountWorld::tenant(), AccountWorld::siret())));
    }

    public function testTheEventCarriesTheIdentifiersOnly(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->activeMandateOf($account);
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Declared));
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::Paid));

        self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), true, null));

        $events = $world->publisher->events();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(AccountForgotten::class, $event);
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(['mandate-1'], array_map(static fn(MandateId $id): string => $id->value(), $event->mandateIds()));
        static::assertSame(['declaration-1'], array_map(static fn(DeclarationRecordId $id): string => $id->value(), $event->declarationRecordIds()));
        static::assertSame(['payment-1'], array_map(static fn(PaymentRecordId $id): string => $id->value(), $event->paymentRecordIds()));
        static::assertSame('2026-11-17T09:00:00+00:00', $event->occurredAt()->format('c'));
        static::assertCount(1, $world->outbox->appended());
        static::assertSame('urssaf.tdae.account.forgotten', $world->outbox->appended()[0]->type());
    }

    public function testASiretWithoutAnAccountIsErasedWithoutAccountId(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Declared));
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::Error));

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), true, null));

        static::assertNull($erasure->accountId);
        static::assertSame(['mandate-1'], $erasure->mandateIds);
        static::assertSame(['declaration-1'], $erasure->declarationRecordIds);
        static::assertSame(['payment-1'], $erasure->paymentRecordIds);
        $event = $world->publisher->events()[0];
        static::assertInstanceOf(AccountForgotten::class, $event);
        static::assertNull($event->accountId());
        static::assertSame(['mandate-1'], array_map(static fn(MandateId $id): string => $id->value(), $event->mandateIds()));
        static::assertSame(['AccountForgotten'], $world->publishedEvents());
        static::assertNotContains('delete:account-1', $world->journal->entries());
    }

    public function testASiretWithOnlyRecordsIsErased(): void
    {
        $world = new AccountWorld();
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::Paid));

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));

        static::assertSame(['payment-1'], $erasure->paymentRecordIds);
        static::assertSame([], $erasure->declarationRecordIds);
        static::assertSame([], $erasure->mandateIds);
    }

    public function testASiretWithOnlyDeclarationsIsErased(): void
    {
        $world = new AccountWorld();
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Pending));

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));

        static::assertSame(['declaration-1'], $erasure->declarationRecordIds);
    }

    public function testASiretWithDeclarationsAndPaymentsOnlyIsErased(): void
    {
        $world = new AccountWorld();
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Declared));
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::Paid));

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));

        static::assertSame(['declaration-1'], $erasure->declarationRecordIds);
        static::assertSame(['payment-1'], $erasure->paymentRecordIds);
    }

    public function testASiretWithOnlyAMandateIsErased(): void
    {
        $world = new AccountWorld();
        $world->cancelledMandate();

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));

        static::assertSame(['mandate-1'], $erasure->mandateIds);
    }

    public function testTheOtherSiretsAndTenantsAreLeftAlone(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->trackedAccount('account-2', AccountWorld::BIRTH_DATE, false, Periodicity::Monthly, AccountWorld::OTHER_SIRET);
        $world->activeMandate('mandate-2', AccountWorld::OTHER_SIRET);
        $world->declarations->add(AccountWorld::declarationRecord('declaration-2', DeclarationState::Declared, 10, AccountWorld::OTHER_SIRET));
        $world->payments->add(AccountWorld::paymentRecord('payment-2', PaymentState::Paid, 10, AccountWorld::OTHER_SIRET));

        self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));

        static::assertSame('account-2', $world->accounts->get(AccountId::fromString('account-2'))->id()->value());
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret(AccountWorld::OTHER_SIRET)));
        static::assertSame(1, array_sum($world->declarations->countBySiret(AccountWorld::tenant(), AccountWorld::siret(AccountWorld::OTHER_SIRET))));
        static::assertSame(1, array_sum($world->payments->countBySiret(AccountWorld::tenant(), AccountWorld::siret(AccountWorld::OTHER_SIRET))));
    }

    // -- not found

    public function testNothingKnownForTheSiretIsNotFound(): void
    {
        $world = new AccountWorld();
        $world->activeMandate('mandate-other', AccountWorld::OTHER_SIRET);

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));
            static::fail('Nothing is known for the SIRET.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('ae_account.not_found', $notFound->violationCode());
            static::assertSame('***', $notFound->identifier());
        }
        static::assertSame(['begin', 'rollback'], $world->journal->entries());
        static::assertSame([], $world->publishedEvents());
    }

    public function testAnUnknownAccountIdIsNotFound(): void
    {
        $world = new AccountWorld();

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::byAccountId(AccountId::fromString('account-9')), null, null));
            static::fail('The account does not exist.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('account-9', $notFound->identifier());
        }
    }

    public function testAnAccountOfAnotherTenantIsNotFound(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();

        $this->expectException(RecordNotFound::class);

        self::handler($world)(new ForgetAccount(AccountWorld::tenant('tenant-2'), AccountSelector::byAccountId(AccountId::fromString('account-1')), null, null));
    }

    // -- refusals

    public function testAnActiveMandateRefusesTheErasure(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->activeMandateOf($account);
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Declared));

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));
            static::fail('The erasure must be refused.');
        } catch (AccountErasureRefused $refusal) {
            static::assertSame('ae_account.forget_active_mandate', $refusal->violationCode());
            static::assertSame(['account_id' => 'account-1', 'count' => 1], $refusal->params());
        }
        static::assertSame(['begin', 'rollback'], $world->journal->entries());
        static::assertSame([], $world->publishedEvents());
        static::assertSame([], $world->outbox->appended());
        static::assertSame(1, array_sum($world->declarations->countBySiret(AccountWorld::tenant(), AccountWorld::siret())));
    }

    public function testAnActiveMandateOfAnUntrackedSiretRefusesTheErasureWithoutAccountId(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));
            static::fail('The erasure must be refused.');
        } catch (AccountErasureRefused $refusal) {
            static::assertSame(['count' => 1], $refusal->params());
        }
    }

    public function testACancelledMandateDoesNotRefuseTheErasure(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->cancelledMandate();

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), false, null));

        static::assertSame(['mandate-1'], $erasure->mandateIds);
    }

    public function testASubmissionInFlightIsAlwaysRefused(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Submitting, 9), AccountWorld::declarationRecord('declaration-2', DeclarationState::Declared, 10));
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::Submitting));

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), true, true));
            static::fail('The erasure must be refused.');
        } catch (AccountErasureRefused $refusal) {
            static::assertSame('ae_account.forget_submission_in_flight', $refusal->violationCode());
            static::assertSame(['account_id' => 'account-1', 'count' => 2], $refusal->params());
        }
        static::assertSame(['begin', 'rollback'], $world->journal->entries());
    }

    public function testASubmittingPaymentAloneIsRefused(): void
    {
        $world = new AccountWorld();
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::Submitting));

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), true, true));
            static::fail('The erasure must be refused.');
        } catch (AccountErasureRefused $refusal) {
            static::assertSame(['count' => 1], $refusal->params());
        }
    }

    public function testAnUnknownOutcomeRefusesTheErasure(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::OutcomeUnknown));
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::OutcomeUnknown), AccountWorld::paymentRecord('payment-2', PaymentState::OutcomeUnknown, 9));

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));
            static::fail('The erasure must be refused.');
        } catch (AccountErasureRefused $refusal) {
            static::assertSame('ae_account.forget_unknown_outcome', $refusal->violationCode());
            static::assertSame(['account_id' => 'account-1', 'count' => 3], $refusal->params());
        }
        static::assertSame(['begin', 'rollback'], $world->journal->entries());
    }

    public function testAnUnknownDeclarationOutcomeAloneRefusesTheErasure(): void
    {
        $world = new AccountWorld();
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::OutcomeUnknown));

        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));
            static::fail('The erasure must be refused.');
        } catch (AccountErasureRefused $refusal) {
            static::assertSame(['count' => 1], $refusal->params());
        }
    }

    public function testTheRefusalsAreCheckedInOrder(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->activeMandateOf($account);
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Submitting, 9), AccountWorld::declarationRecord('declaration-2', DeclarationState::OutcomeUnknown, 10));

        // Active mandate first.
        $codes = [self::refusalCode($world, null, null)];
        // Then the submission in flight, whatever the opt-ins.
        $codes[] = self::refusalCode($world, true, null);
        $codes[] = self::refusalCode($world, true, true);
        // The unknown outcome comes last: without the submission it is the refusal.
        $other = new AccountWorld();
        $other->activeMandateOf($other->trackedAccount());
        $other->declarations->add(AccountWorld::declarationRecord('declaration-2', DeclarationState::OutcomeUnknown, 10));
        $codes[] = self::refusalCode($other, true, null);

        static::assertSame(
            ['ae_account.forget_active_mandate', 'ae_account.forget_submission_in_flight', 'ae_account.forget_submission_in_flight', 'ae_account.forget_unknown_outcome'],
            $codes,
        );
    }

    // -- the opt-ins

    /**
     * @return iterable<string, array{bool, ?bool, bool}> the policy, the command, whether the active mandate is erased
     */
    public static function activeMandateOptIns(): iterable
    {
        yield 'policy refuses, command silent' => [false, null, false];
        yield 'policy allows, command silent' => [true, null, true];
        yield 'policy refuses, command allows' => [false, true, true];
        yield 'policy allows, command refuses' => [true, false, false];
    }

    #[DataProvider('activeMandateOptIns')]
    public function testTheActiveMandateOptIn(bool $policyAllows, ?bool $commandAllows, bool $erased): void
    {
        $policy = (new WorkflowPolicy())->withAllowErasureWithActiveMandate($policyAllows);
        $world = new AccountWorld(true, $policy);
        $account = $world->trackedAccount();
        $world->activeMandateOf($account);

        try {
            $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), $commandAllows, null));
            static::assertTrue($erased);
            static::assertSame(['mandate-1'], $erasure->mandateIds);
        } catch (AccountErasureRefused $refusal) {
            static::assertFalse($erased);
            static::assertSame('ae_account.forget_active_mandate', $refusal->violationCode());
        }
    }

    /**
     * @return iterable<string, array{bool, ?bool, bool}> the policy, the command, whether the unknown outcomes are erased
     */
    public static function unknownOutcomeOptIns(): iterable
    {
        yield 'policy refuses, command silent' => [false, null, false];
        yield 'policy allows, command silent' => [true, null, true];
        yield 'policy refuses, command allows' => [false, true, true];
        yield 'policy allows, command refuses' => [true, false, false];
    }

    #[DataProvider('unknownOutcomeOptIns')]
    public function testTheUnknownOutcomeOptIn(bool $policyAllows, ?bool $commandAllows, bool $erased): void
    {
        $policy = (new WorkflowPolicy())->withAllowErasureWithUnknownOutcomes($policyAllows);
        $world = new AccountWorld(true, $policy);
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::OutcomeUnknown));
        $world->payments->add(AccountWorld::paymentRecord('payment-1', PaymentState::OutcomeUnknown));

        try {
            $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, $commandAllows));
            static::assertTrue($erased);
            static::assertSame(['declaration-1'], $erasure->declarationRecordIds);
            static::assertSame(['payment-1'], $erasure->paymentRecordIds);
        } catch (AccountErasureRefused $refusal) {
            static::assertFalse($erased);
            static::assertSame('ae_account.forget_unknown_outcome', $refusal->violationCode());
        }
    }

    public function testTheErasableStatesAreEveryStateButSubmittingAndUnknownOutcomes(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $declarations = new RacingRecords($world->declarations, null);
        $payments = new RacingPayments($world->payments, null);

        self::handlerOver($world, $world->mandates, $declarations, $payments, $world->accounts)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));

        static::assertSame(
            [DeclarationState::Pending, DeclarationState::Declared, DeclarationState::Rejected, DeclarationState::Error, DeclarationState::InvalidCredentials],
            $declarations->requestedStates(),
        );
        static::assertSame(
            [PaymentState::Pending, PaymentState::Paid, PaymentState::Rejected, PaymentState::Error, PaymentState::InvalidCredentials],
            $payments->requestedStates(),
        );
    }

    public function testTheErasableStatesIncludeUnknownOutcomesWhenAllowed(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $declarations = new RacingRecords($world->declarations, null);
        $payments = new RacingPayments($world->payments, null);

        self::handlerOver($world, $world->mandates, $declarations, $payments, $world->accounts)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, true));

        static::assertSame(
            [DeclarationState::Pending, DeclarationState::Declared, DeclarationState::Rejected, DeclarationState::OutcomeUnknown, DeclarationState::Error, DeclarationState::InvalidCredentials],
            $declarations->requestedStates(),
        );
        static::assertSame(
            [PaymentState::Pending, PaymentState::Paid, PaymentState::Rejected, PaymentState::OutcomeUnknown, PaymentState::Error, PaymentState::InvalidCredentials],
            $payments->requestedStates(),
        );
    }

    public function testEveryErasableStateIsErasedAndAllowedUnknownOutcomesToo(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $month = 0;
        foreach ([DeclarationState::Pending, DeclarationState::Declared, DeclarationState::Rejected, DeclarationState::OutcomeUnknown, DeclarationState::Error, DeclarationState::InvalidCredentials] as $state) {
            ++$month;
            $world->declarations->add(AccountWorld::declarationRecord('declaration-' . $month, $state, $month));
        }
        $month = 0;
        foreach ([PaymentState::Pending, PaymentState::Paid, PaymentState::Rejected, PaymentState::OutcomeUnknown, PaymentState::Error, PaymentState::InvalidCredentials] as $state) {
            ++$month;
            $world->payments->add(AccountWorld::paymentRecord('payment-' . $month, $state, $month));
        }

        $erasure = self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, true));

        static::assertCount(6, $erasure->declarationRecordIds);
        static::assertCount(6, $erasure->paymentRecordIds);
    }

    // -- the race

    public function testAMandateCreatedWhileErasingRollsEverythingBack(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $world->cancelledMandate('mandate-1');
        $world->declarations->add(AccountWorld::declarationRecord('declaration-1', DeclarationState::Declared));
        $intruder = Mandate::notify(MandateId::fromString('mandate-intruder'), AccountWorld::tenant(), AccountWorld::siret(), null, Instant::fromIso8601('2026-11-16T08:00:00Z'));
        $mandates = new RacingMandates($world->mandates, $intruder);

        try {
            self::handlerOver($world, $mandates, $world->declarations, $world->payments, $world->accounts)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::byAccountId($account->id()), null, null));
            static::fail('The concurrent change must be detected.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
            static::assertSame(['id' => 'account-1'], $violation->params());
            static::assertSame(
                'The auto-entrepreneur was changed by another process while it was being erased: nothing was erased, run the erasure again.',
                $violation->getMessage(),
            );
        }
        static::assertContains('rollback', $world->journal->entries());
        static::assertNotContains('commit', $world->journal->entries());
        static::assertSame([], $world->publishedEvents());
        static::assertSame([], $world->outbox->appended());
        static::assertSame('account-1', $world->accounts->get($account->id())->id()->value());
        static::assertSame(1, array_sum($world->declarations->countBySiret(AccountWorld::tenant(), AccountWorld::siret())));
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testADeclarationCreatedWhileErasingRollsEverythingBack(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $records = new RacingRecords($world->declarations, AccountWorld::declarationRecord('declaration-intruder', DeclarationState::Pending, 3));

        try {
            self::handlerOver($world, $world->mandates, $records, $world->payments, $world->accounts)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));
            static::fail('The concurrent change must be detected.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertNotNull($world->accounts->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame(0, array_sum($world->declarations->countBySiret(AccountWorld::tenant(), AccountWorld::siret())));
        static::assertSame([], $world->publishedEvents());
    }

    public function testAPaymentCreatedWhileErasingRollsEverythingBack(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $payments = new RacingPayments($world->payments, AccountWorld::paymentRecord('payment-intruder', PaymentState::Pending, 3));

        try {
            self::handlerOver($world, $world->mandates, $world->declarations, $payments, $world->accounts)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), null, null));
            static::fail('The concurrent change must be detected.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertNotNull($world->accounts->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame([], $world->outbox->appended());
    }

    public function testAnAccountTrackedWhileErasingAnUntrackedSiretRollsEverythingBack(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();
        $world->trackedAccount();
        $late = new LateAccounts($world->accounts);

        try {
            self::handlerOver($world, $world->mandates, $world->declarations, $world->payments, $late)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), true, null));
            static::fail('The concurrent change must be detected.');
        } catch (InvariantViolation $violation) {
            static::assertSame(AeAccountRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
            static::assertSame([], $violation->params());
        }
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame([], $world->publishedEvents());
    }

    private static function refusalCode(AccountWorld $world, ?bool $allowActiveMandate, ?bool $allowUnknownOutcomes): string
    {
        try {
            self::handler($world)(new ForgetAccount(AccountWorld::tenant(), AccountSelector::bySiret(AccountWorld::siret()), $allowActiveMandate, $allowUnknownOutcomes));
        } catch (AccountErasureRefused $refusal) {
            return $refusal->violationCode();
        }

        return 'erased';
    }

    private static function handler(AccountWorld $world): ForgetAccountHandler
    {
        return self::handlerOver($world, $world->mandates, $world->declarations, $world->payments, $world->accounts);
    }

    private static function handlerOver(
        AccountWorld $world,
        MandateRepository $mandates,
        DeclarationRecordRepository $declarations,
        PaymentRecordRepository $payments,
        AeAccountRepository $accounts,
    ): ForgetAccountHandler {
        return new ForgetAccountHandler(
            new AccountLocator($accounts),
            $accounts,
            $mandates,
            $declarations,
            $payments,
            $world->clock,
            $world->unitOfWork,
            $world->policy,
        );
    }
}
