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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindPayments;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindPaymentsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

/**
 * FindPayments: by record ids, by references, or by state (ADR 0014 items 3 and 4).
 *
 * @mago-expect lint:too-many-methods
 */
final class FindPaymentsHandlerTest extends TestCase
{
    public function testTheRecordIdsAndTheReferencesOfASelectionAreLists(): void
    {
        $byIds = FindPayments::byRecordIds(PaymentWorld::tenant(), ...['first' => PaymentRecordId::fromString('p-a')]);
        $byReferences = FindPayments::byReferences(PaymentWorld::tenant(), ...['first' => PaymentWorld::reference()]);

        static::assertSame([0], array_keys($byIds->recordIds));
        static::assertSame([0], array_keys($byReferences->references));
    }

    public function testTheViewsFollowTheOrderOfTheRecordIdsAndLeaveOutUnknownAndForeignOnes(): void
    {
        $world = $this->world();

        $views = $this->find($world, FindPayments::byRecordIds(
            PaymentWorld::tenant(),
            PaymentRecordId::fromString('p-b'),
            PaymentRecordId::fromString('unknown'),
            PaymentRecordId::fromString('p-foreign'),
            PaymentRecordId::fromString('p-a'),
        ));

        static::assertSame(['p-b', 'p-a'], self::ids($views));
        static::assertSame(['ref-b', 'ref-a'], array_map(static fn(PaymentView $view): string => $view->reference, $views));
    }

    public function testTheViewsFollowTheOrderOfTheReferencesAndLeaveOutUnknownAndForeignOnes(): void
    {
        $world = $this->world();

        $views = $this->find($world, FindPayments::byReferences(
            PaymentWorld::tenant(),
            PaymentWorld::reference('ref-b'),
            PaymentWorld::reference('unknown'),
            PaymentWorld::reference('ref-foreign'),
            PaymentWorld::reference('ref-a'),
        ));

        static::assertSame(['p-b', 'p-a'], self::ids($views));
    }

    public function testNothingRequestedIsAnEmptyList(): void
    {
        $world = $this->world();

        static::assertSame([], $this->find($world, FindPayments::byRecordIds(PaymentWorld::tenant())));
        static::assertSame([], $this->find($world, FindPayments::byReferences(PaymentWorld::tenant())));
    }

    public function testAStateListingReturnsTheLeastRecentlyUpdatedFirstOfTheTenant(): void
    {
        $world = $this->world();

        $views = $this->find($world, FindPayments::inState(PaymentWorld::tenant(), PaymentState::Paid, null));

        static::assertSame(['p-a', 'p-b', 'p-c'], self::ids($views));
        static::assertSame('paid', $views[0]->status->state);
    }

    public function testTheLimitOfAStateListingIsCappedByThePolicy(): void
    {
        $world = $this->world();
        $capped = new FindPaymentsHandler($world->payments, (new WorkflowPolicy())->withPageSizes(1, 2));

        static::assertSame(['p-a', 'p-b'], self::ids($capped(FindPayments::inState(PaymentWorld::tenant(), PaymentState::Paid, 10))));
        static::assertSame(['p-a'], self::ids($capped(FindPayments::inState(PaymentWorld::tenant(), PaymentState::Paid, null))));
        static::assertSame(['p-a'], self::ids($this->find($world, FindPayments::inState(PaymentWorld::tenant(), PaymentState::Paid, 1))));
    }

    public function testAnInvalidLimitIsRefused(): void
    {
        $world = $this->world();

        $this->expectException(InvariantViolation::class);

        $this->find($world, FindPayments::inState(PaymentWorld::tenant(), PaymentState::Paid, 0));
    }

    public function testAStateWithoutRecordsIsAnEmptyList(): void
    {
        $world = $this->world();

        static::assertSame([], $this->find($world, FindPayments::inState(PaymentWorld::tenant(), PaymentState::Rejected, null)));
    }

    /**
     * Three paid payments of tenant-1 updated at 08:10, 08:20 and 08:30 (created in another order), one of tenant-2.
     */
    private function world(): PaymentWorld
    {
        $world = new PaymentWorld();
        self::paidAt($world, 'p-b', 'ref-b', 'tenant-1', '2026-11-17T08:20:00Z');
        self::paidAt($world, 'p-c', 'ref-c', 'tenant-1', '2026-11-17T08:30:00Z');
        self::paidAt($world, 'p-a', 'ref-a', 'tenant-1', '2026-11-17T08:10:00Z');
        self::paidAt($world, 'p-foreign', 'ref-foreign', 'tenant-2', '2026-11-17T08:05:00Z');

        return $world;
    }

    private static function paidAt(PaymentWorld $world, string $id, string $reference, string $tenant, string $at): void
    {
        $record = PaymentRecord::record(
            PaymentRecordId::fromString($id),
            PaymentWorld::tenant($tenant),
            PaymentWorld::reference($reference),
            PaymentWorld::siret(),
            PaymentWorld::order(),
            null,
            Instant::fromIso8601(PaymentWorld::RECORDED_AT),
        );
        $record->startSubmission(Instant::fromIso8601(PaymentWorld::RECORDED_AT));
        $record->markPaid(PaymentWorld::paid(), Instant::fromIso8601($at));
        $record->releaseEvents();
        $world->payments->add($record);
    }

    /**
     * @return list<PaymentView>
     */
    private function find(PaymentWorld $world, FindPayments $query): array
    {
        return (new FindPaymentsHandler($world->payments, $world->policy))($query);
    }

    /**
     * @param list<PaymentView> $views
     *
     * @return list<string>
     */
    private static function ids(array $views): array
    {
        return array_map(static fn(PaymentView $view): string => $view->recordId, $views);
    }
}
