<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Dto;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;

final class BatchReportTest extends TestCase
{
    public function testReport(): void
    {
        $first = new BatchItem('a', ItemOutcome::Declared, ['10']);
        $second = new BatchItem('b', ItemOutcome::Rejected);
        $third = new BatchItem('c', ItemOutcome::Declared);

        $report = BatchReport::of(...['first' => $first, 'second' => $second])->merge(BatchReport::of($third));

        static::assertSame([$first, $second, $third], $report->items());
        static::assertSame(3, $report->total());
        static::assertSame(2, $report->count(ItemOutcome::Declared));
        static::assertSame(1, $report->count(ItemOutcome::Rejected));
        static::assertSame(0, $report->count(ItemOutcome::Failed));
        static::assertSame([], $second->codes);
        static::assertSame(['10'], $first->codes);
        static::assertSame('a', $first->subject);
        static::assertSame(0, BatchReport::of()->total());
    }

    public function testMergeKeepsEveryItemOfBothReports(): void
    {
        $a = new BatchItem('a', ItemOutcome::Declared);
        $b = new BatchItem('b', ItemOutcome::Rejected);
        $c = new BatchItem('c', ItemOutcome::Failed);

        static::assertSame([$a, $b, $c], BatchReport::of($a)->merge(BatchReport::of($b, $c))->items());
        static::assertSame([$a], BatchReport::of($a)->merge(BatchReport::of())->items());
    }

    public function testEveryOutcomeHasItsValue(): void
    {
        static::assertSame(
            [
                'declared',
                'paid',
                'rejected',
                'outcome_unknown',
                'invalid_credentials',
                'retry_later',
                'exhausted',
                'not_claimed',
                'replayed',
                'manual_review',
                'changed',
                'unchanged',
                'failed',
            ],
            array_map(static fn(ItemOutcome $outcome): string => $outcome->value, ItemOutcome::cases()),
        );
    }
}
