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

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountEventTranslator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationEventTranslator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IntegrationEventFacts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IntegrationEventTranslator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateEventTranslator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentEventTranslator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DomainEventSamples;

/**
 * The mapping table of ADR 0014: one domain event gives at most one integration event, with an id derived from the
 * tenant, the type, the aggregate, the instant and the facts.
 *
 * @mago-expect lint:too-many-methods One scenario per mapping row.
 */
final class EventTranslatorsTest extends TestCase
{
    private const AT = '2026-11-15T10:00:00Z';

    public function testAnAccountChangeKeepsThePreviousAndTheCurrentValues(): void
    {
        $domain = DomainEventSamples::accountSynced();

        $events = IntegrationEventTranslator::translate($domain);

        static::assertEquals(
            [
                new AccountSynced(
                    self::id(AccountSynced::TYPE, 'account-1', ['0', '1', 'T', 'M']),
                    self::AT,
                    'tenant-1',
                    'account-1',
                    null,
                    false,
                    true,
                    'T',
                    'M',
                ),
            ],
            $events,
        );
        static::assertEquals($events, AccountEventTranslator::translate($domain));
    }

    public function testTheFirstReadOfAnAccountHasNoPreviousValue(): void
    {
        $events = AccountEventTranslator::translate(DomainEventSamples::accountSyncedFirstRead());

        static::assertEquals(
            [
                new AccountSynced(
                    self::id(AccountSynced::TYPE, 'account-1', [null, '0', null, 'T']),
                    self::AT,
                    'tenant-1',
                    'account-1',
                    null,
                    null,
                    false,
                    null,
                    'T',
                ),
            ],
            $events,
        );
    }

    public function testThePreviousMandateFlagIsPartOfTheFact(): void
    {
        $gone = AccountEventTranslator::translate(DomainEventSamples::accountSynced())[0] ?? null;
        $first = AccountEventTranslator::translate(DomainEventSamples::accountSyncedFirstRead())[0] ?? null;

        static::assertInstanceOf(IntegrationEvent::class, $gone);
        static::assertInstanceOf(IntegrationEvent::class, $first);
        static::assertNotSame($gone->eventId, $first->eventId);
    }

    public function testAMandateThatWasPresentIsEncodedAsOneInTheFacts(): void
    {
        $events = AccountEventTranslator::translate(DomainEventSamples::accountSyncedWithMandateRemoved());

        static::assertEquals(
            [
                new AccountSynced(
                    self::id(AccountSynced::TYPE, 'account-1', ['1', '0', 'M', 'T']),
                    self::AT,
                    'tenant-1',
                    'account-1',
                    null,
                    true,
                    false,
                    'M',
                    'T',
                ),
            ],
            $events,
        );
    }

    public function testADeclarationWithAnUnknownOutcomeMayHaveNoAccount(): void
    {
        $events = DeclarationEventTranslator::translate(DomainEventSamples::declarationOutcomeUnknownWithoutAccount());

        static::assertEquals(
            [
                new DeclarationOutcomeUnknown(
                    self::id(DeclarationOutcomeUnknown::TYPE, 'declaration-1', ['stale_submission']),
                    self::AT,
                    'tenant-1',
                    'declaration-1',
                    null,
                    null,
                    'M',
                    2026,
                    10,
                    'stale_submission',
                ),
            ],
            $events,
        );
    }

    public function testAPaymentWithAnUnknownOutcomeMayHaveNoAccount(): void
    {
        $events = PaymentEventTranslator::translate(DomainEventSamples::paymentOutcomeUnknownWithoutAccount());

        static::assertCount(1, $events);
        static::assertInstanceOf(PaymentOutcomeUnknown::class, $events[0]);
        static::assertNull($events[0]->accountId);
    }

    public function testAnErasureListsTheErasedIdsInEachFamily(): void
    {
        $events = AccountEventTranslator::translate(DomainEventSamples::accountForgotten());

        static::assertEquals(
            [
                new AccountForgotten(
                    self::id(AccountForgotten::TYPE, 'account-1', ['mandate-1', 'mandate-2', '/', 'declaration-1', '/', 'payment-1', 'payment-2']),
                    self::AT,
                    'tenant-1',
                    'account-1',
                    null,
                    ['mandate-1', 'mandate-2'],
                    ['declaration-1'],
                    ['payment-1', 'payment-2'],
                ),
            ],
            $events,
        );
    }

    public function testAnErasureWithoutATrackedAccountHasNoAggregate(): void
    {
        $events = IntegrationEventTranslator::translate(DomainEventSamples::accountForgottenWithoutAccount());

        static::assertEquals(
            [
                new AccountForgotten(
                    self::id(AccountForgotten::TYPE, null, ['/', '/']),
                    self::AT,
                    'tenant-1',
                    null,
                    null,
                    [],
                    [],
                    [],
                ),
            ],
            $events,
        );
    }

    public function testTheErasedFamiliesAreSeparatedInTheId(): void
    {
        $withMandate = AccountEventTranslator::translate(DomainEventSamples::accountForgotten())[0] ?? null;
        $empty = AccountEventTranslator::translate(DomainEventSamples::accountForgottenWithoutAccount())[0] ?? null;

        static::assertInstanceOf(IntegrationEvent::class, $withMandate);
        static::assertInstanceOf(IntegrationEvent::class, $empty);
        static::assertNotSame($withMandate->eventId, $empty->eventId);
    }

    public function testANotifiedMandate(): void
    {
        $events = IntegrationEventTranslator::translate(DomainEventSamples::mandateNotified());

        static::assertEquals(
            [new MandateNotified(self::id(MandateNotified::TYPE, 'mandate-1', ['notified']), self::AT, 'tenant-1', 'mandate-1', null, 'account-1', 'notified')],
            $events,
        );
        static::assertEquals($events, MandateEventTranslator::translate(DomainEventSamples::mandateNotified()));
    }

    public function testAnImportedMandateOfAnUntrackedAccountHasNoAccountId(): void
    {
        $events = MandateEventTranslator::translate(DomainEventSamples::mandateNotifiedOfUntrackedAccount());

        static::assertEquals(
            [new MandateNotified(self::id(MandateNotified::TYPE, 'mandate-1', ['imported']), self::AT, 'tenant-1', 'mandate-1', null, null, 'imported')],
            $events,
        );
    }

    public function testACancelledMandate(): void
    {
        $events = MandateEventTranslator::translate(DomainEventSamples::mandateCancelled());

        static::assertEquals(
            [new MandateCancelled(self::id(MandateCancelled::TYPE, 'mandate-1', ['cancelled']), self::AT, 'tenant-1', 'mandate-1', null, 'account-1', 'cancelled')],
            $events,
        );
    }

    public function testARecordedCancellationOfAnUntrackedAccountHasNoAccountId(): void
    {
        $events = IntegrationEventTranslator::translate(DomainEventSamples::mandateCancelledOfUntrackedAccount());

        static::assertEquals(
            [new MandateCancelled(self::id(MandateCancelled::TYPE, 'mandate-1', ['recorded']), self::AT, 'tenant-1', 'mandate-1', null, null, 'recorded')],
            $events,
        );
    }

    public function testADeclaredDeclarationCarriesTheCertificateAndTheDeclarationId(): void
    {
        $events = IntegrationEventTranslator::translate(DomainEventSamples::declarationDeclared());

        static::assertEquals(
            [
                new DeclarationDeclared(
                    self::id(DeclarationDeclared::TYPE, 'declaration-1', ['12345678', 'decl-9']),
                    self::AT,
                    'tenant-1',
                    'declaration-1',
                    null,
                    'account-1',
                    'M',
                    2026,
                    10,
                    '12345678',
                    'decl-9',
                ),
            ],
            $events,
        );
        static::assertEquals($events, DeclarationEventTranslator::translate(DomainEventSamples::declarationDeclared()));
    }

    public function testADeclaredDeclarationResolvedByAnOperatorHasNoDeclarationId(): void
    {
        $events = DeclarationEventTranslator::translate(DomainEventSamples::declarationDeclaredWithoutId());

        static::assertEquals(
            [
                new DeclarationDeclared(
                    self::id(DeclarationDeclared::TYPE, 'declaration-1', ['12345678', null]),
                    self::AT,
                    'tenant-1',
                    'declaration-1',
                    null,
                    null,
                    'M',
                    2026,
                    10,
                    '12345678',
                    null,
                ),
            ],
            $events,
        );
    }

    public function testARejectedDeclarationCarriesTheCodes(): void
    {
        $events = DeclarationEventTranslator::translate(DomainEventSamples::declarationRejected());

        static::assertEquals(
            [
                new DeclarationRejected(
                    self::id(DeclarationRejected::TYPE, 'declaration-1', ['ERR_A', 'ERR_B']),
                    self::AT,
                    'tenant-1',
                    'declaration-1',
                    null,
                    'account-1',
                    'M',
                    2026,
                    10,
                    ['ERR_A', 'ERR_B'],
                ),
            ],
            $events,
        );
    }

    public function testAQuarterlyPeriodIsEncodedWithItsQuarterNumber(): void
    {
        $events = DeclarationEventTranslator::translate(DomainEventSamples::declarationRejectedOfUntrackedAccount());

        static::assertEquals(
            [
                new DeclarationRejected(
                    self::id(DeclarationRejected::TYPE, 'declaration-1', ['ERR_A']),
                    self::AT,
                    'tenant-1',
                    'declaration-1',
                    null,
                    null,
                    'T',
                    2026,
                    3,
                    ['ERR_A'],
                ),
            ],
            $events,
        );
    }

    public function testADeclarationWithAnUnknownOutcomeCarriesTheReason(): void
    {
        $events = DeclarationEventTranslator::translate(DomainEventSamples::declarationOutcomeUnknown());

        static::assertEquals(
            [
                new DeclarationOutcomeUnknown(
                    self::id(DeclarationOutcomeUnknown::TYPE, 'declaration-1', ['stale_submission']),
                    self::AT,
                    'tenant-1',
                    'declaration-1',
                    null,
                    'account-1',
                    'M',
                    2026,
                    10,
                    'stale_submission',
                ),
            ],
            $events,
        );
    }

    public function testAnOrderedPaymentCarriesTheReferenceTheCertificateTheIdAndTheRum(): void
    {
        $events = IntegrationEventTranslator::translate(DomainEventSamples::paymentOrdered());

        static::assertEquals(
            [
                new PaymentOrdered(
                    self::id(PaymentOrdered::TYPE, 'payment-1', ['12345678', 'pay-9', 'RUM-1']),
                    self::AT,
                    'tenant-1',
                    'payment-1',
                    'payment-ref-1',
                    'account-1',
                    'M',
                    2026,
                    10,
                    '12345678',
                    'pay-9',
                    'RUM-1',
                ),
            ],
            $events,
        );
        static::assertEquals($events, PaymentEventTranslator::translate(DomainEventSamples::paymentOrdered()));
    }

    public function testAnOrderedPaymentWithoutIdOrRumOfAnUntrackedAccount(): void
    {
        $events = PaymentEventTranslator::translate(DomainEventSamples::paymentOrderedWithoutIdOrRum());

        static::assertEquals(
            [
                new PaymentOrdered(
                    self::id(PaymentOrdered::TYPE, 'payment-1', ['12345678', null, null]),
                    self::AT,
                    'tenant-1',
                    'payment-1',
                    'payment-ref-1',
                    null,
                    'M',
                    2026,
                    10,
                    '12345678',
                    null,
                    null,
                ),
            ],
            $events,
        );
    }

    public function testARejectedPaymentCarriesTheCodes(): void
    {
        $events = PaymentEventTranslator::translate(DomainEventSamples::paymentRejected());

        static::assertEquals(
            [
                new PaymentRejected(
                    self::id(PaymentRejected::TYPE, 'payment-1', ['ERR_A', 'ERR_B']),
                    self::AT,
                    'tenant-1',
                    'payment-1',
                    'payment-ref-1',
                    'account-1',
                    'M',
                    2026,
                    10,
                    ['ERR_A', 'ERR_B'],
                ),
            ],
            $events,
        );
    }

    public function testARejectedQuarterlyPaymentOfAnUntrackedAccount(): void
    {
        $events = PaymentEventTranslator::translate(DomainEventSamples::paymentRejectedOfUntrackedAccount());

        static::assertEquals(
            [
                new PaymentRejected(
                    self::id(PaymentRejected::TYPE, 'payment-1', ['ERR_A']),
                    self::AT,
                    'tenant-1',
                    'payment-1',
                    'payment-ref-1',
                    null,
                    'T',
                    2026,
                    3,
                    ['ERR_A'],
                ),
            ],
            $events,
        );
    }

    public function testAPaymentWithAnUnknownOutcomeCarriesTheReason(): void
    {
        $events = PaymentEventTranslator::translate(DomainEventSamples::paymentOutcomeUnknown());

        static::assertEquals(
            [
                new PaymentOutcomeUnknown(
                    self::id(PaymentOutcomeUnknown::TYPE, 'payment-1', ['may_have_reached_urssaf']),
                    self::AT,
                    'tenant-1',
                    'payment-1',
                    'payment-ref-1',
                    'account-1',
                    'M',
                    2026,
                    10,
                    'may_have_reached_urssaf',
                ),
            ],
            $events,
        );
    }

    /**
     * @return iterable<string, array{DomainEvent}>
     */
    public static function provideEventsWithoutIntegrationEvent(): iterable
    {
        yield 'declaration recorded' => [DomainEventSamples::declarationRecorded()];
        yield 'declaration requeued' => [DomainEventSamples::declarationRequeued()];
        yield 'declaration submission failed' => [DomainEventSamples::declarationSubmissionFailed()];
        yield 'declaration credentials rejected' => [DomainEventSamples::declarationCredentialsRejected()];
        yield 'payment recorded' => [DomainEventSamples::paymentRecorded()];
        yield 'payment requeued' => [DomainEventSamples::paymentRequeued()];
        yield 'payment submission failed' => [DomainEventSamples::paymentSubmissionFailed()];
        yield 'payment credentials rejected' => [DomainEventSamples::paymentCredentialsRejected()];
    }

    #[DataProvider('provideEventsWithoutIntegrationEvent')]
    public function testSomeDomainEventsHaveNoIntegrationEvent(DomainEvent $event): void
    {
        static::assertSame([], IntegrationEventTranslator::translate($event));
        static::assertSame([], AccountEventTranslator::translate($event));
        static::assertSame([], MandateEventTranslator::translate($event));
        static::assertSame([], DeclarationEventTranslator::translate($event));
        static::assertSame([], PaymentEventTranslator::translate($event));
    }

    public function testNothingIsTranslatedWithoutDomainEvents(): void
    {
        static::assertSame([], IntegrationEventTranslator::translate());
    }

    public function testTheReleaseOrderIsKeptAcrossFamilies(): void
    {
        $events = IntegrationEventTranslator::translate(
            DomainEventSamples::paymentRejected(),
            DomainEventSamples::declarationRecorded(),
            DomainEventSamples::mandateNotified(),
            DomainEventSamples::accountSynced(),
            DomainEventSamples::declarationDeclared(),
            DomainEventSamples::accountForgotten(),
            DomainEventSamples::paymentOrdered(),
        );

        static::assertSame(
            [
                'urssaf.tdae.payment.rejected',
                'urssaf.tdae.mandate.notified',
                'urssaf.tdae.account.synced',
                'urssaf.tdae.declaration.declared',
                'urssaf.tdae.account.forgotten',
                'urssaf.tdae.payment.ordered',
            ],
            array_map(static fn(IntegrationEvent $event): string => $event->type(), $events),
        );
    }

    public function testTheSameFactOfTwoTenantsHasTwoIds(): void
    {
        $first = IntegrationEventFacts::id(MandateNotified::TYPE, 'tenant-1', 'mandate-1', new DateTimeImmutable(self::AT), ['notified']);
        $second = IntegrationEventFacts::id(MandateNotified::TYPE, 'tenant-2', 'mandate-1', new DateTimeImmutable(self::AT), ['notified']);

        static::assertNotSame($first, $second);
    }

    /**
     * @param list<?string> $facts
     */
    private static function id(string $type, ?string $aggregateId, array $facts): string
    {
        return IntegrationEventFacts::id($type, 'tenant-1', $aggregateId, new DateTimeImmutable(self::AT), $facts);
    }
}
