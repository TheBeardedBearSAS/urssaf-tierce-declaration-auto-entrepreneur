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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

final class PaymentLocatorTest extends TestCase
{
    public function testTheRecordIsLocatedByIdWithinItsTenant(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Pending, 0);

        $record = $world->locator->locate(PaymentWorld::tenant(), PaymentSelector::byRecordId(PaymentRecordId::fromString('record-1')));

        static::assertSame('record-1', $record->id()->value());
    }

    public function testTheRecordIsLocatedByReferenceWithinItsTenant(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Pending, 0);

        $record = $world->locator->locate(PaymentWorld::tenant(), PaymentSelector::byReference(PaymentWorld::reference()));

        static::assertSame('record-1', $record->id()->value());
    }

    public function testARecordOfAnotherTenantIsNotFoundWithItsIdentifier(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Pending, 0);

        try {
            $world->locator->locate(PaymentWorld::tenant('tenant-2'), PaymentSelector::byRecordId(PaymentRecordId::fromString('record-1')));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $refusal) {
            static::assertSame('payment_record.not_found', $refusal->violationCode());
            static::assertSame('payment_record', $refusal->recordType());
            static::assertSame('record-1', $refusal->identifier());
        }
    }

    public function testAnUnknownReferenceIsNotFoundWithTheReference(): void
    {
        $world = new PaymentWorld();

        try {
            $world->locator->locate(PaymentWorld::tenant(), PaymentSelector::byReference(PaymentWorld::reference('pay-x')));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $refusal) {
            static::assertSame('payment_record', $refusal->recordType());
            static::assertSame('pay-x', $refusal->identifier());
        }
    }

    public function testAnUnknownIdIsNotFound(): void
    {
        $world = new PaymentWorld();

        $this->expectException(RecordNotFound::class);

        $world->locator->locate(PaymentWorld::tenant(), PaymentSelector::byRecordId(PaymentRecordId::fromString('unknown')));
    }
}
