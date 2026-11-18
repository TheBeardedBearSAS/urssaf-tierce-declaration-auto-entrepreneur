<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\Event;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;

/**
 * Identifiers, periods, states and codes only (ADR 0010 item 8, ADR 0014 item 3).
 */
final class DeclarationEventsTest extends TestCase
{
    public function testDeclarationRecorded(): void
    {
        $event = new DeclarationRecorded(
            DeclarationSamples::recordId('record-2'),
            DeclarationSamples::tenantId(),
            null,
            DeclarationSamples::period(),
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::instant(),
        );

        static::assertSame('record-2', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertNull($event->accountId());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('record-1', $event->supersedes()?->value());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testDeclarationDeclared(): void
    {
        $event = new DeclarationDeclared(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::accountId(),
            DeclarationSamples::period(),
            DeclarationSamples::certificate(),
            null,
            DeclarationSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('12345678', $event->certificate()->value());
        static::assertNull($event->declarationId());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testDeclarationRejected(): void
    {
        $event = new DeclarationRejected(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::accountId(),
            DeclarationSamples::period(),
            ['ER_1', 'ER_2'],
            DeclarationSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame(['ER_1', 'ER_2'], $event->errorCodes());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testDeclarationOutcomeUnknown(): void
    {
        $event = new DeclarationOutcomeUnknown(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            null,
            DeclarationSamples::period(),
            OutcomeUnknownReason::StaleSubmission,
            DeclarationSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertNull($event->accountId());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame(OutcomeUnknownReason::StaleSubmission, $event->reason());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testDeclarationSubmissionFailed(): void
    {
        $event = new DeclarationSubmissionFailed(
            DeclarationSamples::recordId('record-1'),
            DeclarationSamples::tenantId(),
            ['ER_429'],
            DeclarationSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(['ER_429'], $event->errorCodes());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testDeclarationCredentialsRejected(): void
    {
        $event = new DeclarationCredentialsRejected(DeclarationSamples::recordId('record-1'), DeclarationSamples::tenantId(), DeclarationSamples::instant());

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testDeclarationRequeued(): void
    {
        $event = new DeclarationRequeued(DeclarationSamples::recordId('record-1'), DeclarationSamples::tenantId(), DeclarationState::Error, DeclarationSamples::instant());

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(DeclarationState::Error, $event->from());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }
}
