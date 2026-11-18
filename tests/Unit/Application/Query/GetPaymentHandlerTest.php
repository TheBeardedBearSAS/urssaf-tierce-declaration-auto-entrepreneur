<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Query;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetPayment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetPaymentHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

final class GetPaymentHandlerTest extends TestCase
{
    public function testAPaymentIsReadByRecordIdOrByReference(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Paid);
        $handler = new GetPaymentHandler($world->locator);

        $byId = $handler(new GetPayment(PaymentWorld::tenant(), PaymentSelector::byRecordId(PaymentRecordId::fromString('record-1'))));
        $byReference = $handler(new GetPayment(PaymentWorld::tenant(), PaymentSelector::byReference(PaymentWorld::reference())));

        static::assertSame('record-1', $byId->recordId);
        static::assertSame('paid', $byId->status->state);
        static::assertEquals($byId, $byReference);
    }

    public function testAnUnknownOrForeignPaymentIsNotFound(): void
    {
        $world = new PaymentWorld();
        $world->paymentIn('record-1', PaymentState::Paid);
        $handler = new GetPaymentHandler($world->locator);

        foreach ([
            new GetPayment(PaymentWorld::tenant(), PaymentSelector::byRecordId(PaymentRecordId::fromString('unknown'))),
            new GetPayment(PaymentWorld::tenant('tenant-2'), PaymentSelector::byRecordId(PaymentRecordId::fromString('record-1'))),
            new GetPayment(PaymentWorld::tenant(), PaymentSelector::byReference(PaymentWorld::reference('unknown'))),
            new GetPayment(PaymentWorld::tenant('tenant-2'), PaymentSelector::byReference(PaymentWorld::reference())),
        ] as $query) {
            try {
                $handler($query);
                static::fail('Expected RecordNotFound.');
            } catch (RecordNotFound $refusal) {
                static::assertSame('payment_record.not_found', $refusal->violationCode());
            }
        }
    }
}
