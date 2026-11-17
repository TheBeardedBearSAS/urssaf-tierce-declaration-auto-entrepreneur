<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Mandate;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateCancellationOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateOrigin;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateState;

final class MandateStateTest extends TestCase
{
    public function testValues(): void
    {
        static::assertSame(['notified', 'cancelled'], array_map(static fn(MandateState $state): string => $state->value, MandateState::cases()));
        static::assertSame(['notified', 'imported'], array_map(static fn(MandateOrigin $origin): string => $origin->value, MandateOrigin::cases()));
        static::assertSame(['cancelled', 'recorded'], array_map(static fn(MandateCancellationOrigin $origin): string => $origin->value, MandateCancellationOrigin::cases()));
    }

    public function testAllowedTransitions(): void
    {
        static::assertSame([MandateState::Cancelled], MandateState::Notified->allowedTransitions());
        static::assertSame([], MandateState::Cancelled->allowedTransitions());
    }

    public function testCanTransitionTo(): void
    {
        static::assertTrue(MandateState::Notified->canTransitionTo(MandateState::Cancelled));
        static::assertFalse(MandateState::Notified->canTransitionTo(MandateState::Notified));
        static::assertFalse(MandateState::Cancelled->canTransitionTo(MandateState::Notified));
        static::assertFalse(MandateState::Cancelled->canTransitionTo(MandateState::Cancelled));
    }

    public function testTransitionTo(): void
    {
        static::assertSame(MandateState::Cancelled, MandateState::Notified->transitionTo(MandateState::Cancelled));
    }

    public function testRefusedTransition(): void
    {
        try {
            MandateState::Cancelled->transitionTo(MandateState::Notified);
            static::fail('Expected an InvalidStateTransition.');
        } catch (InvalidStateTransition $exception) {
            static::assertSame('mandate.invalid_state_transition', $exception->violationCode());
            static::assertSame('mandate', $exception->aggregate());
            static::assertSame('cancelled', $exception->from());
            static::assertSame('notified', $exception->to());
        }
    }

    public function testActiveAndFinal(): void
    {
        static::assertTrue(MandateState::Notified->isActive());
        static::assertFalse(MandateState::Notified->isFinal());
        static::assertFalse(MandateState::Cancelled->isActive());
        static::assertTrue(MandateState::Cancelled->isFinal());
    }
}
