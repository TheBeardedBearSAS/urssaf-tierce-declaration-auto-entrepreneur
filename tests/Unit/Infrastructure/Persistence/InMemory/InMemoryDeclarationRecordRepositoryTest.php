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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;

/**
 * @mago-expect lint:too-many-methods One test per rule of the repository.
 */
final class InMemoryDeclarationRecordRepositoryTest extends TestCase
{
    private InMemoryDeclarationRecordRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryDeclarationRecordRepository();
    }

    public function testASavedRecordIsReadBackAsACopy(): void
    {
        $record = Records::declaration('r1');
        $this->repository->save($record);

        $read = $this->repository->get(DeclarationRecordId::fromString('r1'));

        static::assertNotSame($record, $read);
        static::assertEquals($record, $read);
    }

    public function testAChangeIsOnlyVisibleOnceSaved(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $read = $this->repository->get(DeclarationRecordId::fromString('r1'));

        $read->startSubmission(Records::at(5));

        static::assertSame(DeclarationState::Pending, $this->repository->get(DeclarationRecordId::fromString('r1'))->state());
        $this->repository->save($read);
        static::assertSame(DeclarationState::Submitting, $this->repository->get(DeclarationRecordId::fromString('r1'))->state());
    }

    public function testTheEventsAreNeitherPersistedNorConsumed(): void
    {
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString('r1'),
            Records::tenant(),
            Records::siret(),
            Records::period(),
            Records::declarationFingerprint(1, 'tenant-1', 10),
            null,
            Records::at(),
        );

        $this->repository->save($record);

        static::assertCount(1, $record->releaseEvents());
        static::assertSame([], $this->repository->get(DeclarationRecordId::fromString('r1'))->releaseEvents());
    }

    public function testAnUnknownRecordIsNotFound(): void
    {
        try {
            $this->repository->get(DeclarationRecordId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('declaration_record.not_found', $notFound->violationCode());
            static::assertSame('missing', $notFound->identifier());
        }
    }

    public function testASupersessionSavesTheSupersededRecordFirst(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $old = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $new = $old->supersede(
            DeclarationRecordId::fromString('r2'),
            Records::otherDeclarationFingerprint(1, 'tenant-1', 10),
            Records::at(5),
        );

        $this->repository->save($old);
        $this->repository->save($new);

        $current = $this->repository->findCurrent(Records::tenant(), Records::siret(), Records::period());
        static::assertSame('r2', $current?->id()->value());
        $kept = $this->repository->get(DeclarationRecordId::fromString('r1'));
        static::assertSame('r2', $kept->supersededBy()?->value());
        static::assertFalse($kept->isCurrent());
        static::assertCount(2, $this->repository->all());
    }

    public function testASupersessionSavingTheNewRecordFirstIsRefused(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $old = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $new = $old->supersede(
            DeclarationRecordId::fromString('r2'),
            Records::otherDeclarationFingerprint(1, 'tenant-1', 10),
            Records::at(5),
        );

        try {
            $this->repository->save($new);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(DeclarationRecordRepository::DUPLICATE_CURRENT, $violation->violationCode());
            static::assertSame([], $violation->params());
            static::assertStringNotContainsString(Records::siret(1)->toString(), $violation->getMessage());
        }
        static::assertCount(1, $this->repository->all());
    }

    public function testTheSupersededRecordCanBeSavedAgainWhileTheNewOneIsCurrent(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $old = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $new = $old->supersede(
            DeclarationRecordId::fromString('r2'),
            Records::otherDeclarationFingerprint(1, 'tenant-1', 10),
            Records::at(5),
        );
        $this->repository->save($old);
        $this->repository->save($new);

        $this->repository->save($this->repository->get(DeclarationRecordId::fromString('r1')));

        static::assertCount(2, $this->repository->all());
    }

    public function testASecondFirstDeclarationOfThePeriodIsRefused(): void
    {
        $this->repository->save(Records::declaration('r1'));

        $this->assertDuplicate(Records::declaration('r2'));
    }

    public function testAnotherTenantSiretOrPeriodIsAccepted(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $this->repository->save(Records::declaration('r2', Slot::new()->tenant('tenant-2')));
        $this->repository->save(Records::declaration('r3', Slot::new()->siret(2)));
        $this->repository->save(Records::declaration('r4', Slot::new()->month(11)));

        static::assertCount(4, $this->repository->all());
    }

    public function testSavingTheSameRecordAgainIsAnUpdate(): void
    {
        $record = Records::declaration('r1');
        $this->repository->save($record);
        $this->repository->save($record);

        static::assertCount(1, $this->repository->all());
    }

    public function testTwoConcurrentSupersessionsLeaveTheLoserRefused(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $winnerOld = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $loserOld = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $winnerNew = $winnerOld->supersede(
            DeclarationRecordId::fromString('r2'),
            Records::otherDeclarationFingerprint(1, 'tenant-1', 10),
            Records::at(5),
        );
        $loserNew = $loserOld->supersede(
            DeclarationRecordId::fromString('r3'),
            Records::otherDeclarationFingerprint(1, 'tenant-1', 10),
            Records::at(6),
        );
        $this->repository->save($winnerOld);
        $this->repository->save($winnerNew);

        $this->assertConcurrent($loserOld);
        $this->assertDuplicate($loserNew);
        static::assertSame(
            'r2',
            $this->repository->findCurrent(Records::tenant(), Records::siret(), Records::period())?->id()->value(),
        );
    }

    public function testFindCurrentIsScopedAndIgnoresSupersededRecords(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $this->repository->save(Records::declaration('r2', Slot::new()->tenant('tenant-2')));
        $this->repository->save(Records::declaration('r3', Slot::new()->siret(2)));
        $this->repository->save(Records::declaration('r4', Slot::new()->month(11)));

        static::assertSame('r1', $this->repository->findCurrent(Records::tenant('tenant-1'), Records::siret(1), Records::period(10))?->id()->value());
        static::assertSame('r2', $this->repository->findCurrent(Records::tenant('tenant-2'), Records::siret(1), Records::period(10))?->id()->value());
        static::assertSame('r3', $this->repository->findCurrent(Records::tenant('tenant-1'), Records::siret(2), Records::period(10))?->id()->value());
        static::assertSame('r4', $this->repository->findCurrent(Records::tenant('tenant-1'), Records::siret(1), Records::period(11))?->id()->value());
        static::assertNull($this->repository->findCurrent(Records::tenant('tenant-1'), Records::siret(1), Records::period(12)));
        static::assertNull($this->repository->findCurrent(Records::tenant('tenant-3'), Records::siret(1), Records::period(10)));
        static::assertNull($this->repository->findCurrent(Records::tenant('tenant-1'), Records::siret(3), Records::period(10)));

        $old = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $new = $old->supersede(DeclarationRecordId::fromString('r5'), Records::otherDeclarationFingerprint(1, 'tenant-1', 10), Records::at(9));
        $this->repository->save($old);
        $this->repository->save($new);
        static::assertSame('r5', $this->repository->findCurrent(Records::tenant('tenant-1'), Records::siret(1), Records::period(10))?->id()->value());
    }

    public function testFindByStateIsLeastRecentlyUpdatedFirstTiesByIdAndLimited(): void
    {
        $this->repository->save(Records::declaration('r3', Slot::new()->siret(3)->minutes(5)));
        $this->repository->save(Records::declaration('r2', Slot::new()->siret(2)->minutes(5)));
        $this->repository->save(Records::declaration('r1', Slot::new()->minutes(9)));
        $this->repository->save(Records::declaration('r0', Slot::new()->siret(4)->minutes(1)));
        $this->repository->save(Records::declaration('other-tenant', Slot::new()->siret(5)->tenant('tenant-2')));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'other-state', Slot::new()->siret(6)));

        static::assertSame(['r0', 'r2', 'r3', 'r1'], self::ids($this->repository->findByState(Records::tenant(), DeclarationState::Pending, 10)));
        static::assertSame(['r0', 'r2'], self::ids($this->repository->findByState(Records::tenant(), DeclarationState::Pending, 2)));
        static::assertSame(['other-state'], self::ids($this->repository->findByState(Records::tenant(), DeclarationState::Submitting, 10)));
        static::assertSame([], $this->repository->findByState(Records::tenant('tenant-3'), DeclarationState::Pending, 10));
    }

    public function testStaleSubmissionsAreSelectedAtOrBeforeTheInstantOfEveryTenantOldestFirst(): void
    {
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'b', Slot::new()->minutes(9)));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'a', Slot::new()->siret(2)->minutes(9)));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'old', Slot::new()->siret(3)->tenant('tenant-2')->minutes(3)));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'fresh', Slot::new()->siret(4)->minutes(30)));
        $this->repository->save(Records::declarationIn(DeclarationState::Declared, 'settled', Slot::new()->siret(5)));
        $this->repository->save(Records::declaration('never-started', Slot::new()->siret(6)));

        $stale = $this->repository->findStaleSubmissions(Records::at(10), 10);

        static::assertSame(['old', 'a', 'b'], self::ids($stale));
        static::assertSame(['old', 'a', 'b'], self::ids($this->repository->findStaleSubmissions(Records::at(10), 3)));
        static::assertSame(['old'], self::ids($this->repository->findStaleSubmissions(Records::at(10), 1)));
        static::assertSame(['old'], self::ids($this->repository->findStaleSubmissions(Records::at(9), 10)));
        static::assertSame([], $this->repository->findStaleSubmissions(Records::at(3), 10));
    }

    public function testTheCountsIncludeEveryStateWithZeros(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $this->repository->save(Records::declaration('r2', Slot::new()->siret(2)));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'r3', Slot::new()->siret(3)));
        $this->repository->save(Records::declaration('r4', Slot::new()->tenant('tenant-2')));

        $counts = $this->repository->countByState(Records::tenant());

        static::assertSame(array_map(static fn(DeclarationState $state): string => $state->value, DeclarationState::cases()), array_keys($counts));
        static::assertSame(2, $counts['pending']);
        static::assertSame(1, $counts['submitting']);
        static::assertSame(0, $counts['declared']);
        static::assertSame(0, array_sum($this->repository->countByState(Records::tenant('tenant-3'))));
    }

    public function testTheCountsOfASiretAreScopedToTheTenantAndTheSiret(): void
    {
        $this->repository->save(Records::declaration('r1', Slot::new()->month(9)));
        $this->repository->save(Records::declaration('r2'));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'r3', Slot::new()->month(11)));
        $this->repository->save(Records::declaration('r4', Slot::new()->siret(2)));
        $this->repository->save(Records::declaration('r5', Slot::new()->tenant('tenant-2')));

        $counts = $this->repository->countBySiret(Records::tenant(), Records::siret(1));

        static::assertSame(2, $counts['pending']);
        static::assertSame(1, $counts['submitting']);
        static::assertSame(0, $counts['declared']);
        static::assertCount(7, $counts);
        static::assertSame(0, array_sum($this->repository->countBySiret(Records::tenant(), Records::siret(3))));
        static::assertSame(1, $this->repository->countBySiret(Records::tenant('tenant-2'), Records::siret(1))['pending']);
    }

    public function testRecordsAcceptedAfterTheirSupersessionAreCountedPerTenant(): void
    {
        foreach (['tenant-1', 'tenant-2'] as $n => $tenant) {
            $this->repository->save(Records::declaration('old-' . $tenant, Slot::new()->tenant($tenant)));
            $old = $this->repository->get(DeclarationRecordId::fromString('old-' . $tenant));
            $new = $old->supersede(
                DeclarationRecordId::fromString('new-' . $tenant),
                Records::otherDeclarationFingerprint(1, $tenant, 10),
                Records::at(5),
            );
            $this->repository->save($old);
            $this->repository->save($new);
            if ($n === 0) {
                $late = $this->repository->get(DeclarationRecordId::fromString('old-' . $tenant));
                $late->startSubmission(Records::at(6));
                $late->markDeclared(DeclarationSamples::result(), Records::at(7));
                $this->repository->save($late);
            }
        }

        static::assertSame(1, $this->repository->countAcceptedAfterSupersession(Records::tenant('tenant-1')));
        static::assertSame(0, $this->repository->countAcceptedAfterSupersession(Records::tenant('tenant-2')));
        static::assertSame(0, $this->repository->countAcceptedAfterSupersession(Records::tenant('tenant-3')));
    }

    public function testTheTenantsWithAStateAreDistinctAndSorted(): void
    {
        $this->repository->save(Records::declaration('r1', Slot::new()->tenant('tenant-b')));
        $this->repository->save(Records::declaration('r2', Slot::new()->siret(2)->tenant('tenant-a')));
        $this->repository->save(Records::declaration('r3', Slot::new()->siret(3)->tenant('tenant-b')));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'r4', Slot::new()->siret(4)->tenant('tenant-c')));

        static::assertSame(['tenant-a', 'tenant-b'], self::tenantValues($this->repository->tenantsWithState(DeclarationState::Pending)));
        static::assertSame(['tenant-c'], self::tenantValues($this->repository->tenantsWithState(DeclarationState::Submitting)));
        static::assertSame([], $this->repository->tenantsWithState(DeclarationState::Declared));
    }

    public function testAClaimWinsOnceAndCountsTheAttemptWithoutAnyEvent(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $caller = $this->repository->get(DeclarationRecordId::fromString('r1'));

        static::assertTrue($this->repository->claimForSubmission(DeclarationRecordId::fromString('r1'), Records::at(7)));
        static::assertFalse($this->repository->claimForSubmission(DeclarationRecordId::fromString('r1'), Records::at(8)));

        $claimed = $this->repository->get(DeclarationRecordId::fromString('r1'));
        static::assertSame(DeclarationState::Submitting, $claimed->state());
        static::assertSame(1, $claimed->attempts());
        static::assertTrue(Records::at(7)->equals($claimed->lastAttemptAt() ?? Records::at(99)));
        static::assertSame([], $claimed->releaseEvents());
        static::assertSame(DeclarationState::Pending, $caller->state());
        static::assertSame([], $caller->releaseEvents());
        $this->assertConcurrent($caller);
    }

    public function testAClaimIsRefusedForAMissingNotPendingOrSupersededRecord(): void
    {
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'busy'));
        $this->repository->save(Records::declaration('old', Slot::new()->siret(2)));
        $old = $this->repository->get(DeclarationRecordId::fromString('old'));
        $new = $old->supersede(DeclarationRecordId::fromString('new'), Records::otherDeclarationFingerprint(2, 'tenant-1', 10), Records::at(5));
        $this->repository->save($old);
        $this->repository->save($new);

        static::assertFalse($this->repository->claimForSubmission(DeclarationRecordId::fromString('missing'), Records::at(7)));
        static::assertFalse($this->repository->claimForSubmission(DeclarationRecordId::fromString('busy'), Records::at(7)));
        static::assertFalse($this->repository->claimForSubmission(DeclarationRecordId::fromString('old'), Records::at(7)));
        static::assertSame(DeclarationState::Pending, $this->repository->get(DeclarationRecordId::fromString('old'))->state());
        static::assertTrue($this->repository->claimForSubmission(DeclarationRecordId::fromString('new'), Records::at(7)));
    }

    public function testARequeuedRecordCanBeClaimedAgain(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $this->repository->claimForSubmission(DeclarationRecordId::fromString('r1'), Records::at(7));
        $record = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $record->markFailed(DeclarationSamples::errors(), Records::at(8));
        $record->retry(Records::at(9));
        $this->repository->save($record);

        static::assertTrue($this->repository->claimForSubmission(DeclarationRecordId::fromString('r1'), Records::at(10)));
        static::assertSame(2, $this->repository->get(DeclarationRecordId::fromString('r1'))->attempts());
    }

    public function testDeleteBySiretDeletesTheGivenStatesOldestCreationFirst(): void
    {
        $this->repository->save(Records::declaration('c', Slot::new()->month(8)->minutes(5)));
        $this->repository->save(Records::declaration('b', Slot::new()->month(9)));
        $this->repository->save(Records::declaration('a'));
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'busy', Slot::new()->month(11)));
        $this->repository->save(Records::declaration('other-siret', Slot::new()->siret(2)));
        $this->repository->save(Records::declaration('other-tenant', Slot::new()->tenant('tenant-2')));

        $deleted = $this->repository->deleteBySiret(
            Records::tenant(),
            Records::siret(1),
            [DeclarationState::Pending, DeclarationState::Declared],
        );

        static::assertSame(['a', 'b', 'c'], array_map(static fn(DeclarationRecordId $id): string => $id->value(), $deleted));
        static::assertSame(
            ['busy', 'other-siret', 'other-tenant'],
            self::ids($this->repository->all()),
        );
        static::assertSame([], $this->repository->deleteBySiret(Records::tenant(), Records::siret(1), [DeclarationState::Pending]));
        static::assertSame([], $this->repository->deleteBySiret(Records::tenant('tenant-3'), Records::siret(1), [DeclarationState::Submitting]));
    }

    public function testADeletedRecordIsGoneStaleAndItsKeyIsFree(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $read = $this->repository->get(DeclarationRecordId::fromString('r1'));

        $this->repository->deleteBySiret(Records::tenant(), Records::siret(), [DeclarationState::Pending]);

        $this->assertConcurrent($read);
        try {
            $this->repository->get(DeclarationRecordId::fromString('r1'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('declaration_record.not_found', $notFound->violationCode());
        }
        $this->repository->save(Records::declaration('r1'));
        static::assertCount(1, $this->repository->all());
    }

    public function testTheCurrentKeyOfADeletedRecordIsFree(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $this->repository->deleteBySiret(Records::tenant(), Records::siret(), [DeclarationState::Pending]);

        $this->repository->save(Records::declaration('r2'));

        static::assertSame(['r2'], self::ids($this->repository->all()));
    }

    public function testAStaleAnErasedAndAQueryCopyAreRefused(): void
    {
        $this->repository->save(Records::declaration('r1'));
        $first = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $second = $this->repository->get(DeclarationRecordId::fromString('r1'));
        $byState = $this->repository->findByState(Records::tenant(), DeclarationState::Pending, 1)[0];
        $current = $this->repository->findCurrent(Records::tenant(), Records::siret(), Records::period());
        $this->repository->save($first);
        $this->repository->save($first);

        $this->assertConcurrent($second);
        $this->assertConcurrent($byState);
        static::assertInstanceOf(DeclarationRecord::class, $current);
        $this->assertConcurrent($current);
    }

    public function testStaleSubmissionCopiesAndANeverReadInstanceAreRefused(): void
    {
        $this->repository->save(Records::declarationIn(DeclarationState::Submitting, 'r1'));
        $stale = $this->repository->findStaleSubmissions(Records::at(10), 1)[0];
        $this->repository->save($this->repository->get(DeclarationRecordId::fromString('r1')));

        $this->assertConcurrent($stale);
        $this->assertConcurrent(Records::declaration('r1', Slot::new()->siret(2)));
    }

    private function assertConcurrent(DeclarationRecord $record): void
    {
        try {
            $this->repository->save($record);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(DeclarationRecordRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
    }

    private function assertDuplicate(DeclarationRecord $record): void
    {
        try {
            $this->repository->save($record);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(DeclarationRecordRepository::DUPLICATE_CURRENT, $violation->violationCode());
        }
    }

    /**
     * @param list<DeclarationRecord> $records
     *
     * @return list<string>
     */
    private static function ids(array $records): array
    {
        return array_map(static fn(DeclarationRecord $record): string => $record->id()->value(), $records);
    }

    /**
     * @param list<TenantId> $tenants
     *
     * @return list<string>
     */
    private static function tenantValues(array $tenants): array
    {
        return array_map(static fn(TenantId $tenant): string => $tenant->value(), $tenants);
    }
}
