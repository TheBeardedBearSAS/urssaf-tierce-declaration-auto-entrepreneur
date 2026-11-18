<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;

/**
 * Lifecycle of a declaration record (ADR 0003, ADR 0009).
 *
 * pending -> submitting -> declared | rejected (final)
 *                       -> outcome_unknown -> declared (manual, certificat read) | pending (manual, not received)
 *                       -> error (proven not sent) | invalid_credentials -> pending (retry / requeue)
 *
 * Late acceptance (ADR 0009): an accepted 050 is never discarded, so "declared" is also reachable from every state but
 * declared itself (a call whose record meanwhile became pending, error, invalid_credentials, outcome_unknown, rejected
 * or was claimed again). Only declared is a dead end.
 */
enum DeclarationState: string
{
    case Pending = 'pending';
    case Submitting = 'submitting';
    case Declared = 'declared';
    case Rejected = 'rejected';
    case OutcomeUnknown = 'outcome_unknown';
    case Error = 'error';
    case InvalidCredentials = 'invalid_credentials';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Submitting, self::Declared],
            self::Submitting => [
                self::Declared,
                self::Rejected,
                self::OutcomeUnknown,
                self::Error,
                self::InvalidCredentials,
            ],
            self::OutcomeUnknown => [self::Declared, self::Pending],
            self::Error, self::InvalidCredentials => [self::Pending, self::Declared],
            self::Rejected => [self::Declared],
            self::Declared => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @throws InvalidStateTransition "declaration_record.invalid_state_transition"
     */
    public function transitionTo(self $target): self
    {
        if (!$this->canTransitionTo($target)) {
            throw InvalidStateTransition::between('declaration_record', $this->value, $target->value);
        }

        return $target;
    }

    /**
     * True for the states no operator or retry leaves: declared and rejected (the only way out of rejected is the late
     * acceptance of a call that was in flight, never a decision).
     */
    public function isFinal(): bool
    {
        return \in_array($this, [self::Declared, self::Rejected], true);
    }

    /**
     * True for the state that waits for an operator decision: an unknown outcome.
     */
    public function needsAttention(): bool
    {
        return $this === self::OutcomeUnknown;
    }
}
