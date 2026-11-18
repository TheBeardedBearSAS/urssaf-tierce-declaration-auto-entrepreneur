<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Logging;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Logging\PsrLoggerFailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Event\FakeDomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\CapturingLogger;

final class PsrLoggerFailureReporterTest extends TestCase
{
    public function testItLogsTheFailureAndTheEventClassesAtErrorLevel(): void
    {
        $logger = new CapturingLogger();
        $at = new DateTimeImmutable('2026-01-01T10:00:00+00:00');

        (new PsrLoggerFailureReporter($logger))->eventPublicationFailed(
            new RuntimeException('listener exploded', 7),
            [new FakeDomainEvent('first', $at), new FakeDomainEvent('second', $at)],
        );

        static::assertSame([[
            'level' => 'error',
            'message' => 'Domain event publication failed after commit.',
            'context' => [
                'exception' => RuntimeException::class,
                'code' => 7,
                'events' => [FakeDomainEvent::class, FakeDomainEvent::class],
            ],
        ]], $logger->records());
    }

    public function testItNeverLogsTheFailureMessageNorTheEventPayload(): void
    {
        $logger = new CapturingLogger();

        (new PsrLoggerFailureReporter($logger))->eventPublicationFailed(
            new LogicException('SIRET 12345678901234 unknown'),
            [new FakeDomainEvent('jane.doe@example.com', new DateTimeImmutable('2026-01-01T10:00:00+00:00'))],
        );

        static::assertStringNotContainsString('12345678901234', $logger->dump());
        static::assertStringNotContainsString('unknown', $logger->dump());
        static::assertStringNotContainsString('jane.doe@example.com', $logger->dump());
    }

    public function testItWorksWithoutLogger(): void
    {
        (new PsrLoggerFailureReporter())->eventPublicationFailed(new LogicException('boom'), []);

        $this->expectNotToPerformAssertions();
    }
}
