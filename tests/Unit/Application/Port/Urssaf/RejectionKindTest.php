<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Port\Urssaf;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;

final class RejectionKindTest extends TestCase
{
    public function testValuesAreTheAdr0005ListInPriorityOrder(): void
    {
        static::assertSame(
            [
                'sepa_mandate_already_exists',
                'mandate_held_elsewhere',
                'account_unknown',
                'no_active_user',
                'mandate_missing',
                'mandate_out_of_period',
                'period_not_open',
                'periodicity_mismatch',
                'invalid_bank_details',
                'unknown_bank',
                'invalid_parameter',
                'not_found',
                'technical',
                'unknown',
            ],
            array_map(static fn(RejectionKind $kind): string => $kind->value, RejectionKind::cases()),
        );
    }

    public function testFromValueRoundTrips(): void
    {
        static::assertSame(RejectionKind::MandateMissing, RejectionKind::from('mandate_missing'));
        static::assertNull(RejectionKind::tryFrom('MDT_INCONNU'));
    }
}
