<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Creditor;

final class CreditorTest extends TestCase
{
    public function testAccessors(): void
    {
        $creditor = new Creditor('URSSAF', 'FR00ZZZ000000', '1 rue A', '2 rue B', '59000', 'FRANCE', 'LILLE');

        static::assertSame('URSSAF', $creditor->name());
        static::assertSame('FR00ZZZ000000', $creditor->ics());
        static::assertSame('1 rue A', $creditor->firstLine());
        static::assertSame('2 rue B', $creditor->secondLine());
        static::assertSame('59000', $creditor->postalCode());
        static::assertSame('FRANCE', $creditor->country());
        static::assertSame('LILLE', $creditor->city());
    }

    public function testEveryFieldIsOptional(): void
    {
        $creditor = new Creditor(null, null, null, null, null, null, null);

        static::assertNull($creditor->name());
        static::assertNull($creditor->ics());
        static::assertNull($creditor->firstLine());
        static::assertNull($creditor->secondLine());
        static::assertNull($creditor->postalCode());
        static::assertNull($creditor->country());
        static::assertNull($creditor->city());
    }
}
