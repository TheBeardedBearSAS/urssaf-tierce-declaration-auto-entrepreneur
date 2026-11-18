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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindDeclarations;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindDeclarationsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;

final class FindDeclarationsHandlerTest extends TestCase
{
    public function testTheRecordIdsOfASelectionAreAList(): void
    {
        $query = FindDeclarations::byRecordIds(DeclarationWorld::tenant(), ...['first' => DeclarationRecordId::fromString('record-1')]);

        static::assertSame([0], array_keys($query->recordIds));
    }

    public function testItReturnsTheViewsInTheOrderOfTheIdsAndLeavesOutTheUnknownOnes(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(self::pending('record-1', 9), self::pending('record-2', 10));

        $views = $this->find($world, FindDeclarations::byRecordIds(
            DeclarationWorld::tenant(),
            DeclarationRecordId::fromString('record-2'),
            DeclarationRecordId::fromString('missing'),
            DeclarationRecordId::fromString('record-1'),
        ));

        static::assertSame(['record-2', 'record-1'], array_map(static fn(DeclarationView $view): string => $view->recordId, $views));
    }

    public function testARecordOfAnotherTenantIsLeftOut(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(self::pending('record-1', 10));

        static::assertSame([], $this->find($world, FindDeclarations::byRecordIds(DeclarationWorld::tenant('tenant-2'), DeclarationRecordId::fromString('record-1'))));
    }

    public function testNoIdGivesNoView(): void
    {
        static::assertSame([], $this->find(new DeclarationWorld(), FindDeclarations::byRecordIds(DeclarationWorld::tenant())));
    }

    public function testItListsTheRecordsInAStateLeastRecentlyUpdatedFirst(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(
            self::pending('record-a', 9, '2026-11-17T08:30:00Z'),
            self::pending('record-b', 8, '2026-11-17T08:00:00Z'),
        );
        $world->recordIn('record-declared', DeclarationState::Declared, 1);

        $views = $this->find($world, FindDeclarations::inState(DeclarationWorld::tenant(), DeclarationState::Pending, null));

        static::assertSame(['record-b', 'record-a'], array_map(static fn(DeclarationView $view): string => $view->recordId, $views));
        static::assertSame('pending', $views[0]->status->state);
    }

    public function testTheLimitIsCappedByThePolicyAndTheDefaultComesFromIt(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withPageSizes(1, 2));
        $world->records->add(self::pending('record-1', 8), self::pending('record-2', 9), self::pending('record-3', 10));

        static::assertCount(1, $this->find($world, FindDeclarations::inState(DeclarationWorld::tenant(), DeclarationState::Pending, null)));
        static::assertCount(2, $this->find($world, FindDeclarations::inState(DeclarationWorld::tenant(), DeclarationState::Pending, 50)));
        static::assertCount(2, $this->find($world, FindDeclarations::inState(DeclarationWorld::tenant(), DeclarationState::Pending, 2)));
    }

    public function testAnInvalidLimitIsRefused(): void
    {
        $this->expectException(InvariantViolation::class);

        $this->find(new DeclarationWorld(), FindDeclarations::inState(DeclarationWorld::tenant(), DeclarationState::Pending, 0));
    }

    public function testAStateListingOnlyHoldsTheTenantRecords(): void
    {
        $world = new DeclarationWorld();
        $world->records->add(self::pending('record-1', 10));

        static::assertSame([], $this->find($world, FindDeclarations::inState(DeclarationWorld::tenant('tenant-2'), DeclarationState::Pending, null)));
    }

    /**
     * @return list<DeclarationView>
     */
    private function find(DeclarationWorld $world, FindDeclarations $query): array
    {
        return (new FindDeclarationsHandler($world->records, $world->policy))($query);
    }

    private static function pending(string $id, int $month, string $createdAt = DeclarationWorld::RECORDED_AT): DeclarationRecord
    {
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString($id),
            DeclarationWorld::tenant(),
            DeclarationWorld::siret(),
            DeclarationWorld::period($month),
            DeclarationFingerprint::of(DeclarationWorld::tenant(), DeclarationWorld::siret(), DeclarationWorld::period($month), DeclarationWorld::turnover()),
            null,
            Instant::fromIso8601($createdAt),
        );
        $record->releaseEvents();

        return $record;
    }
}
