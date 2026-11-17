<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;

/**
 * State of a {@see Mandate} (ADR 0009): notified (method 030 succeeded, or imported) or cancelled (method 031 succeeded,
 * or the cancellation recorded without a call; final).
 */
enum MandateState: string
{
    case Notified = 'notified';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Notified => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @throws InvalidStateTransition "mandate.invalid_state_transition"
     */
    public function transitionTo(self $target): self
    {
        if (!$this->canTransitionTo($target)) {
            throw InvalidStateTransition::between('mandate', $this->value, $target->value);
        }

        return $target;
    }

    /**
     * Not cancelled.
     */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
