<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Submission;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;

final class OutcomeUnknownReasonTest extends TestCase
{
    public function testBackedValues(): void
    {
        static::assertSame(
            ['may_have_reached_urssaf', 'stale_submission'],
            array_map(static fn(OutcomeUnknownReason $reason): string => $reason->value, OutcomeUnknownReason::cases()),
        );
    }
}
