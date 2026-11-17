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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\Event\DeclarationRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;

/**
 * Re-entry of a period: the new record supersedes the previous one (ADR 0009).
 */
final class DeclarationRecordSupersessionTest extends TestCase
{
    public function testSupersedeCreatesTheNewCurrentRecord(): void
    {
        $previous = DeclarationSamples::record(DeclarationSamples::accountId());

        $next = $previous->supersede(DeclarationSamples::recordId('record-2'), DeclarationSamples::otherFingerprint(), DeclarationSamples::later(5));

        static::assertSame('record-2', $next->id()->value());
        static::assertSame('tenant-1', $next->tenantId()->value());
        static::assertSame('12345678901234', $next->siret()->toString());
        static::assertSame('2026-10', $next->period()->toString());
        static::assertSame('account-1', $next->accountId()?->value());
        static::assertTrue($next->fingerprint()->equals(DeclarationSamples::otherFingerprint()));
        static::assertSame('record-1', $next->supersedes()?->value());
        static::assertNull($next->supersededBy());
        static::assertTrue($next->isCurrent());
        static::assertFalse($next->isAcceptedAfterSupersession());
        static::assertSame(DeclarationState::Pending, $next->state());
        static::assertSame(0, $next->attempts());
        static::assertEquals(DeclarationSamples::later(5), $next->createdAt());
        static::assertEquals(DeclarationSamples::later(5), $next->updatedAt());

        $events = $next->releaseEvents();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DeclarationRecorded::class, $event);
        static::assertSame('record-2', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('record-1', $event->supersedes()?->value());
        static::assertSame('2026-11-15T10:05:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testSupersedeLinksThePreviousRecordAndKeepsItsState(): void
    {
        $previous = DeclarationSamples::record(null);
        $previous->startSubmission(DeclarationSamples::later(1));
        $previous->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(2));
        $previous->releaseEvents();

        $next = $previous->supersede(DeclarationSamples::recordId('record-2'), DeclarationSamples::otherFingerprint(), DeclarationSamples::later(5));

        static::assertSame('record-2', $previous->supersededBy()?->value());
        static::assertFalse($previous->isCurrent());
        static::assertSame(DeclarationState::Declared, $previous->state());
        static::assertEquals(DeclarationSamples::later(2), $previous->updatedAt());
        static::assertNull($previous->supersedes());
        static::assertNull($next->accountId());
        static::assertSame([], $previous->releaseEvents());
    }

    public function testASupersededRecordCannotBeSupersededAgain(): void
    {
        $previous = DeclarationSamples::record(null);
        $previous->supersede(DeclarationSamples::recordId('record-2'), DeclarationSamples::otherFingerprint(), DeclarationSamples::later(5));

        DeclarationSamples::assertViolation(
            'declaration_record.already_superseded',
            static fn(): DeclarationRecord => $previous->supersede(DeclarationSamples::recordId('record-3'), DeclarationSamples::otherFingerprint(), DeclarationSamples::later(6)),
        );
        static::assertSame('record-2', $previous->supersededBy()?->value());
    }

    public function testASuccessionOfRecords(): void
    {
        $first = DeclarationSamples::record(null);
        $second = $first->supersede(DeclarationSamples::recordId('record-2'), DeclarationSamples::otherFingerprint(), DeclarationSamples::later(5));
        $third = $second->supersede(DeclarationSamples::recordId('record-3'), DeclarationSamples::fingerprint(), DeclarationSamples::later(6));

        static::assertSame('record-3', $second->supersededBy()?->value());
        static::assertSame('record-2', $third->supersedes()?->value());
        static::assertTrue($third->isCurrent());
        static::assertFalse($first->isCurrent());
        static::assertFalse($second->isCurrent());
    }

    public function testASupersededPeriodIsKeptWhateverItsKind(): void
    {
        $previous = DeclarationRecord::record(
            DeclarationSamples::recordId('record-q'),
            DeclarationSamples::tenantId(),
            DeclarationSamples::siret(),
            QuarterlyPeriod::of(2026, 4),
            DeclarationSamples::fingerprint(),
            null,
            DeclarationSamples::instant(),
        );

        $next = $previous->supersede(DeclarationSamples::recordId('record-2'), DeclarationSamples::otherFingerprint(), DeclarationSamples::later(5));

        static::assertSame(Periodicity::Quarterly, $next->periodicity());
        static::assertSame('2026-Q4', $next->period()->toString());
    }

    public function testALateAcceptanceOnASupersededRecordIsFlagged(): void
    {
        $previous = DeclarationSamples::record(null);
        $previous->startSubmission(DeclarationSamples::later(1));
        $previous->markOutcomeUnknown(OutcomeUnknownReason::MayHaveReachedUrssaf, [], DeclarationSamples::later(2));
        $previous->supersede(DeclarationSamples::recordId('record-2'), DeclarationSamples::otherFingerprint(), DeclarationSamples::later(3));

        $previous->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(4));

        static::assertSame(DeclarationState::Declared, $previous->state());
        static::assertTrue($previous->isAcceptedAfterSupersession());
    }

    public function testASupersededRecordRejectedThenAcceptedLateIsFlagged(): void
    {
        $previous = DeclarationSamples::record(null);
        $previous->startSubmission(DeclarationSamples::later(1));
        $previous->markRejected(DeclarationSamples::errors(), DeclarationSamples::later(2));
        $previous->supersede(DeclarationSamples::recordId('record-2'), DeclarationSamples::fingerprint(), DeclarationSamples::later(3));

        $previous->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(4));

        static::assertTrue($previous->isAcceptedAfterSupersession());
    }

    public function testAnAcceptanceOnTheCurrentRecordIsNotFlagged(): void
    {
        $record = DeclarationSamples::record(null);
        $record->startSubmission(DeclarationSamples::later(1));

        $record->markDeclared(DeclarationSamples::result(), DeclarationSamples::later(2));

        static::assertFalse($record->isAcceptedAfterSupersession());
    }
}
