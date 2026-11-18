<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Override;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\FailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use Throwable;

final class RecordingFailureReporter implements FailureReporter
{
    /** @var list<array{Throwable, list<DomainEvent>}> */
    private array $reports = [];

    #[Override]
    public function eventPublicationFailed(Throwable $failure, array $events): void
    {
        $this->reports[] = [$failure, $events];
    }

    /**
     * @return list<array{Throwable, list<DomainEvent>}>
     */
    public function reports(): array
    {
        return $this->reports;
    }
}
