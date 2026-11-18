<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;

final class DeclarationStateTest extends TestCase
{
    /**
     * @return iterable<string, array{DeclarationState, list<DeclarationState>}>
     */
    public static function transitions(): iterable
    {
        yield 'pending' => [DeclarationState::Pending, [DeclarationState::Submitting, DeclarationState::Declared]];
        yield 'submitting' => [DeclarationState::Submitting, [
            DeclarationState::Declared,
            DeclarationState::Rejected,
            DeclarationState::OutcomeUnknown,
            DeclarationState::Error,
            DeclarationState::InvalidCredentials,
        ]];
        yield 'outcome_unknown' => [DeclarationState::OutcomeUnknown, [DeclarationState::Declared, DeclarationState::Pending]];
        yield 'error' => [DeclarationState::Error, [DeclarationState::Pending, DeclarationState::Declared]];
        yield 'invalid_credentials' => [DeclarationState::InvalidCredentials, [DeclarationState::Pending, DeclarationState::Declared]];
        yield 'declared' => [DeclarationState::Declared, []];
        yield 'rejected' => [DeclarationState::Rejected, [DeclarationState::Declared]];
    }

    /**
     * @param list<DeclarationState> $allowed
     */
    #[DataProvider('transitions')]
    public function testEveryTransitionOfTheAdr(DeclarationState $from, array $allowed): void
    {
        static::assertSame($allowed, $from->allowedTransitions());
        static::assertSame(\in_array($from, [DeclarationState::Declared, DeclarationState::Rejected], true), $from->isFinal());
        static::assertSame($from === DeclarationState::OutcomeUnknown, $from->needsAttention());
        foreach (DeclarationState::cases() as $target) {
            $expected = \in_array($target, $allowed, true);
            static::assertSame($expected, $from->canTransitionTo($target));
            if ($expected) {
                static::assertSame($target, $from->transitionTo($target));

                continue;
            }
            $exception = DomainAssertions::assertViolationCode(
                'declaration_record.invalid_state_transition',
                static fn(): DeclarationState => $from->transitionTo($target),
            );
            static::assertInstanceOf(InvalidStateTransition::class, $exception);
            static::assertSame('declaration_record', $exception->aggregate());
            static::assertSame($from->value, $exception->from());
            static::assertSame($target->value, $exception->to());
        }
    }

    public function testBackedValues(): void
    {
        static::assertSame(
            ['pending', 'submitting', 'declared', 'rejected', 'outcome_unknown', 'error', 'invalid_credentials'],
            array_map(static fn(DeclarationState $state): string => $state->value, DeclarationState::cases()),
        );
    }
}
