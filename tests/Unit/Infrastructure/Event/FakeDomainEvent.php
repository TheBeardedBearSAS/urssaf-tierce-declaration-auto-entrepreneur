<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Event;

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;

final readonly class FakeDomainEvent implements DomainEvent
{
    public function __construct(
        private string $name,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
