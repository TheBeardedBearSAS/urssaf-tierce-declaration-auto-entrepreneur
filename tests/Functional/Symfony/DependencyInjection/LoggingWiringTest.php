<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\DependencyInjection;

use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\FailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Logging\PsrLoggerFailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\ThrowingListener;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\CapturingLogger;

/**
 * The booted application reports a failure after a commit and logs the URSSAF calls through its "logger" service.
 */
final class LoggingWiringTest extends TestCase
{
    public function testTheFailureReporterIsThePsrLoggerOne(): void
    {
        $harness = Harness::boot();

        static::assertInstanceOf(PsrLoggerFailureReporter::class, $harness->privateService(FailureReporter::class, FailureReporter::class));
    }

    public function testAnEventPublicationFailureAfterCommitIsLoggedAndTheStateIsKept(): void
    {
        $harness = Harness::boot();
        $harness->privateService('event_dispatcher', EventDispatcherInterface::class)->addListener(
            MandateNotified::class,
            new ThrowingListener(),
        );

        $mandate = Scenario::importedMandate($harness);

        static::assertNotSame('', $mandate->mandateId);
        $records = $harness->privateService('logger', CapturingLogger::class)->records();
        static::assertCount(1, $records);
        static::assertSame('error', $records[0]['level']);
        static::assertSame('Domain event publication failed after commit.', $records[0]['message']);
        static::assertSame(
            ['exception' => LogicException::class, 'code' => 0, 'events' => [MandateNotified::class]],
            $records[0]['context'],
        );
    }

    public function testTheGatewayLogsThroughThePackageLogger(): void
    {
        $harness = Harness::boot();
        $logger = $harness->privateService('logger', CapturingLogger::class);

        Scenario::notifiedMandate($harness);

        static::assertNotSame([], $logger->records());
        static::assertStringNotContainsString(Scenario::SIRET, $logger->dump());
    }
}
