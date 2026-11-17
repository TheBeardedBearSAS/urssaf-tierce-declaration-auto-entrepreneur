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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Instants of a {@see Mandate}: notification, cancellation and last update.
 */
final readonly class MandateTimeline
{
    private function __construct(
        private Instant $notifiedAt,
        private ?Instant $cancelledAt,
        private Instant $updatedAt,
    ) {}

    public static function notified(Instant $at): self
    {
        return new self($at, null, $at);
    }

    public function notifiedAt(): Instant
    {
        return $this->notifiedAt;
    }

    public function cancelledAt(): ?Instant
    {
        return $this->cancelledAt;
    }

    public function updatedAt(): Instant
    {
        return $this->updatedAt;
    }

    public function withCancellation(Instant $at): self
    {
        return new self($this->notifiedAt, $at, $at);
    }

    public function equals(self $other): bool
    {
        return $this->notifiedAt->equals($other->notifiedAt)
            && $this->cancelledAt?->toIso8601() === $other->cancelledAt?->toIso8601()
            && $this->updatedAt->equals($other->updatedAt);
    }
}
