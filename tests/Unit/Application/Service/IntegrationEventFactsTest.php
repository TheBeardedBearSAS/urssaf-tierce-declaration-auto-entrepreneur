<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IntegrationEventFacts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;

/**
 * @internal
 */
final class IntegrationEventFactsTest extends TestCase
{
    public function testTheIdIsAUuidShapedSliceOfTheHashOfTheFact(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $hash = hash('sha256', serialize(['type', 'tenant', 'aggregate', IntegrationEventFacts::instant($at), ['x']]));
        $id = IntegrationEventFacts::id('type', 'tenant', 'aggregate', $at, ['x']);

        static::assertSame(
            substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12),
            $id,
        );
        static::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
        static::assertSame($id, IntegrationEventFacts::id('type', 'tenant', 'aggregate', $at, ['x']));
        static::assertSame('04422f0c-f544-b4bc-714e-e14f9e78a460', $id);
    }

    public function testTheTypeTheTenantTheAggregateTheInstantAndTheFactsEachContributeToTheId(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $base = IntegrationEventFacts::id('type', 'tenant', 'aggregate', $at, ['x']);

        static::assertNotSame($base, IntegrationEventFacts::id('other', 'tenant', 'aggregate', $at, ['x']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'other', 'aggregate', $at, ['x']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'tenant', 'other', $at, ['x']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'tenant', 'aggregate', $at->modify('+1 second'), ['x']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'tenant', 'aggregate', $at, ['y']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'tenant', 'aggregate', $at, ['x', null]));
    }

    public function testAMissingAggregateIsNotAnEmptyAggregate(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        static::assertNotSame(
            IntegrationEventFacts::id('type', 'tenant', null, $at, ['x']),
            IntegrationEventFacts::id('type', 'tenant', '', $at, ['x']),
        );
    }

    public function testTheInstantIsIso8601InUtc(): void
    {
        static::assertSame('2026-11-14T22:30:00Z', IntegrationEventFacts::instant(new DateTimeImmutable('2026-11-14T23:30:00+01:00')));
        static::assertSame('2026-11-15T10:00:00Z', IntegrationEventFacts::instant(new DateTimeImmutable('2026-11-15T10:00:00Z')));
    }

    public function testThePeriodIsItsCodeItsYearAndItsMonthOrQuarter(): void
    {
        static::assertSame(['M', 2026, 10], IntegrationEventFacts::period(MonthlyPeriod::of(2026, 10)));
        static::assertSame(['T', 2026, 3], IntegrationEventFacts::period(QuarterlyPeriod::of(2026, 3)));
    }
}
