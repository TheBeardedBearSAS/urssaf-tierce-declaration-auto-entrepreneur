<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class PaymentStateTest extends TestCase
{
    /**
     * @return iterable<string, array{PaymentState, list<PaymentState>}>
     */
    public static function transitions(): iterable
    {
        yield 'pending' => [PaymentState::Pending, [PaymentState::Submitting, PaymentState::Paid]];
        yield 'submitting' => [PaymentState::Submitting, [
            PaymentState::Paid,
            PaymentState::Rejected,
            PaymentState::OutcomeUnknown,
            PaymentState::Error,
            PaymentState::InvalidCredentials,
        ]];
        yield 'outcome_unknown' => [PaymentState::OutcomeUnknown, [PaymentState::Paid, PaymentState::Pending]];
        yield 'error' => [PaymentState::Error, [PaymentState::Pending, PaymentState::Paid]];
        yield 'invalid_credentials' => [PaymentState::InvalidCredentials, [PaymentState::Pending, PaymentState::Paid]];
        yield 'paid' => [PaymentState::Paid, []];
        yield 'rejected' => [PaymentState::Rejected, [PaymentState::Paid]];
    }

    /**
     * @param list<PaymentState> $allowed
     */
    #[DataProvider('transitions')]
    public function testEveryTransitionOfTheAdr(PaymentState $from, array $allowed): void
    {
        static::assertSame($allowed, $from->allowedTransitions());
        static::assertSame(\in_array($from, [PaymentState::Paid, PaymentState::Rejected], true), $from->isFinal());
        static::assertSame($from === PaymentState::OutcomeUnknown, $from->needsAttention());
        foreach (PaymentState::cases() as $target) {
            $expected = \in_array($target, $allowed, true);
            static::assertSame($expected, $from->canTransitionTo($target));
            if ($expected) {
                static::assertSame($target, $from->transitionTo($target));

                continue;
            }
            $exception = DomainAssertions::assertViolationCode(
                'payment_record.invalid_state_transition',
                static fn(): PaymentState => $from->transitionTo($target),
            );
            static::assertInstanceOf(InvalidStateTransition::class, $exception);
            static::assertSame('payment_record', $exception->aggregate());
            static::assertSame($from->value, $exception->from());
            static::assertSame($target->value, $exception->to());
        }
    }

    public function testBackedValues(): void
    {
        static::assertSame(
            ['pending', 'submitting', 'paid', 'rejected', 'outcome_unknown', 'error', 'invalid_credentials'],
            array_map(static fn(PaymentState $state): string => $state->value, PaymentState::cases()),
        );
    }
}
