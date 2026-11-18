<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Persistence\InMemory;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryWorkflowStatistics;

/**
 * The order and the exact shape of the counts of the in-memory statistics.
 */
final class InMemoryWorkflowStatisticsTest extends TestCase
{
    public function testNothingIsCountedWithoutRecords(): void
    {
        $statistics = new InMemoryWorkflowStatistics(
            new InMemoryMandateRepository(),
            new InMemoryDeclarationRecordRepository(),
            new InMemoryPaymentRecordRepository(),
        );

        static::assertSame([], $statistics->mandateStates());
        static::assertSame([], $statistics->declarationStates());
        static::assertSame([], $statistics->paymentStates());
    }

    public function testTheCountsKeepTheOrderOfFirstAppearancePerTenantAndState(): void
    {
        $mandates = new InMemoryMandateRepository();
        $declarations = new InMemoryDeclarationRecordRepository();
        $payments = new InMemoryPaymentRecordRepository();
        $mandates->save(Records::mandate('m1', Slot::new()->tenant('tenant-b')));
        $mandates->save(Records::mandate('m2', Slot::new()->siret(2)->tenant('tenant-a')));
        $mandates->save(Records::cancelledMandate('m3', Slot::new()->siret(3)->tenant('tenant-b')));
        $mandates->save(Records::mandate('m4', Slot::new()->siret(4)->tenant('tenant-b')));
        $declarations->save(Records::declaration('d1', Slot::new()->tenant('tenant-b')));
        $declarations->save(Records::declarationIn(DeclarationState::Submitting, 'd2', Slot::new()->siret(2)->tenant('tenant-a')));
        $declarations->save(Records::declaration('d3', Slot::new()->siret(3)->tenant('tenant-b')));
        $payments->save(Records::payment('p1', Slot::new()->tenant('tenant-b')));
        $payments->save(Records::paymentIn(PaymentState::Paid, 'p2', Slot::new()->siret(2)->tenant('tenant-b')));
        $payments->save(Records::payment('p3', Slot::new()->siret(3)->tenant('tenant-a')));
        $statistics = new InMemoryWorkflowStatistics($mandates, $declarations, $payments);

        static::assertSame(
            [['tenant-b', 'notified', 2], ['tenant-b', 'cancelled', 1], ['tenant-a', 'notified', 1]],
            self::flat($statistics->mandateStates()),
        );
        static::assertSame(
            [['tenant-b', 'pending', 2], ['tenant-a', 'submitting', 1]],
            self::flat($statistics->declarationStates()),
        );
        static::assertSame(
            [['tenant-b', 'pending', 1], ['tenant-b', 'paid', 1], ['tenant-a', 'pending', 1]],
            self::flat($statistics->paymentStates()),
        );
    }

    /**
     * @param list<StateCount> $counts
     *
     * @return list<array{string, string, int}>
     */
    private static function flat(array $counts): array
    {
        return array_map(
            static fn(StateCount $count): array => [$count->tenantId->value(), $count->state, $count->count],
            $counts,
        );
    }
}
