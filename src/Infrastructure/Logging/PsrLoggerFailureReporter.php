<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Logging;

use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\FailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use Throwable;

/**
 * Logs the failures that happen after a commit. Only class names and the failure code are logged, never the failure
 * message nor an event payload.
 *
 * The failure comes from a host listener, whose message may echo personal data (ADR 0010 items 5, 7 and 8): this is the
 * same rule the outbox relay applies to the failures of a sink. (Deviation from the reference, which logs the message:
 * ADR 0000, "Smaller adaptations".)
 */
final readonly class PsrLoggerFailureReporter implements FailureReporter
{
    public function __construct(private LoggerInterface $logger = new NullLogger()) {}

    #[Override]
    public function eventPublicationFailed(Throwable $failure, array $events): void
    {
        $this->logger->error('Domain event publication failed after commit.', [
            'exception' => $failure::class,
            'code' => $failure->getCode(),
            'events' => array_map(static fn(DomainEvent $event): string => $event::class, $events),
        ]);
    }
}
