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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

/**
 * ResolvePaymentOutcome: the operator's finding on a record in outcome_unknown (ADR 0003). Nothing is sent.
 *
 * @mago-expect lint:too-many-methods
 */
final class ResolvePaymentOutcomeHandlerTest extends TestCase
{
    public function testAnUnknownOutcomeResolvedAsPaidKeepsTheCertificat(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::OutcomeUnknown);

        $view = $this->resolve($world, ResolvePaymentOutcome::asPaid(PaymentWorld::tenant(), self::byId(), '87654321'));

        static::assertSame('paid', $view->status->state);
        static::assertSame('87654321', $view->status->result?->certificat);
        static::assertNull($view->status->result->idTelepaiement);
        static::assertNull($view->status->result->dueDate);
        static::assertNull($view->status->result->periodicity);
        static::assertNull($view->status->result->periodYear);
        static::assertNull($view->status->result->periodNumber);
        static::assertTrue($view->status->result->resolved);
        static::assertSame(PaymentWorld::NOW, $view->status->updatedAt);
        static::assertSame(['PaymentOrdered'], $world->publisher->names());
        $event = $world->publisher->events()[0];
        static::assertInstanceOf(PaymentOrdered::class, $event);
        static::assertNull($event->paymentId());
        static::assertSame('87654321', $event->certificate()->value());
        static::assertSame(PaymentState::Paid, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
        static::assertSame([], $world->gateway->calls());
    }

    public function testAnUnknownOutcomeResolvedAsNotReceivedIsPendingAgain(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::OutcomeUnknown, 2);

        $view = $this->resolve($world, ResolvePaymentOutcome::asNotReceived(PaymentWorld::tenant(), self::byId()));

        static::assertSame('pending', $view->status->state);
        static::assertSame(2, $view->status->attempts);
        static::assertSame(PaymentState::Pending, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
        static::assertSame([], $world->gateway->calls());
        // Nothing is resent: the host alone decides to call PayContributions again.
        static::assertNotContains('call:060', $world->journal->entries());
    }

    public function testTheRecordCanBeDesignatedByReference(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::OutcomeUnknown);

        $view = $this->resolve($world, ResolvePaymentOutcome::asPaid(
            PaymentWorld::tenant(),
            PaymentSelector::byReference(PaymentWorld::reference()),
            '87654321',
        ));

        static::assertSame('record-1', $view->recordId);
        static::assertSame('paid', $view->status->state);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCertificates(): iterable
    {
        yield 'blank' => ['   ', 'certificate.invalid_format'];
        yield 'malformed' => ['ABC', 'certificate.invalid_format'];
    }

    #[DataProvider('invalidCertificates')]
    public function testAnInvalidCertificatIsRefusedAndNothingIsSaved(string $certificat, string $code): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::OutcomeUnknown);

        try {
            $this->resolve($world, ResolvePaymentOutcome::asPaid(PaymentWorld::tenant(), self::byId(), $certificat));
            static::fail('Expected InvalidValue.');
        } catch (InvalidValue $refusal) {
            static::assertSame($code, $refusal->violationCode());
        }
        static::assertNotContains('save:record-1', $world->journal->entries());
        static::assertSame(PaymentState::OutcomeUnknown, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
    }

    /**
     * @return iterable<string, array{PaymentState}>
     */
    public static function otherStates(): iterable
    {
        foreach ([PaymentState::Pending, PaymentState::Submitting, PaymentState::Paid, PaymentState::Rejected, PaymentState::Error, PaymentState::InvalidCredentials] as $state) {
            yield $state->value => [$state];
        }
    }

    #[DataProvider('otherStates')]
    public function testOnlyAnUnknownOutcomeCanBeResolved(PaymentState $state): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', $state);

        foreach ([
            ResolvePaymentOutcome::asPaid(PaymentWorld::tenant(), self::byId(), '87654321'),
            ResolvePaymentOutcome::asNotReceived(PaymentWorld::tenant(), self::byId()),
        ] as $command) {
            try {
                $this->resolve($world, $command);
                static::fail('Expected InvalidStateTransition.');
            } catch (InvalidStateTransition $refusal) {
                static::assertSame('payment_record', $refusal->aggregate());
                static::assertSame($state->value, $refusal->from());
            }
        }
        static::assertSame($state, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
        static::assertSame([], $world->publisher->names());
    }

    public function testARecordChangedSinceItWasReadIsAConflictAndNothingIsSaved(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::OutcomeUnknown);
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $other = $inner->get(PaymentRecordId::fromString('record-1'));
            $other->resolveAsNotReceived(Instant::fromIso8601(PaymentWorld::NOW));
            $inner->save($other);
        });

        try {
            $this->resolve($world, ResolvePaymentOutcome::asPaid(PaymentWorld::tenant(), self::byId(), '87654321'));
            static::fail('Expected the conflict.');
        } catch (InvariantViolation $conflict) {
            static::assertSame('payment_record.concurrent_modification', $conflict->violationCode());
        }
        static::assertSame(PaymentState::Pending, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
        static::assertSame([], $world->publisher->names());
    }

    public function testAnUnknownOrForeignRecordIsNotFound(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::OutcomeUnknown);

        foreach ([
            ResolvePaymentOutcome::asNotReceived(PaymentWorld::tenant(), PaymentSelector::byRecordId(PaymentRecordId::fromString('unknown'))),
            ResolvePaymentOutcome::asNotReceived(PaymentWorld::tenant('tenant-2'), self::byId()),
        ] as $command) {
            try {
                $this->resolve($world, $command);
                static::fail('Expected RecordNotFound.');
            } catch (RecordNotFound $refusal) {
                static::assertSame('payment_record.not_found', $refusal->violationCode());
            }
        }
        static::assertSame(PaymentState::OutcomeUnknown, $world->payments->get(PaymentRecordId::fromString('record-1'))->state());
    }

    private static function byId(): PaymentSelector
    {
        return PaymentSelector::byRecordId(PaymentRecordId::fromString('record-1'));
    }

    private function resolve(PaymentWorld $world, ResolvePaymentOutcome $command): PaymentView
    {
        return (new ResolvePaymentOutcomeHandler($world->locator, $world->payments, $world->clock, $world->unitOfWork))($command);
    }
}
