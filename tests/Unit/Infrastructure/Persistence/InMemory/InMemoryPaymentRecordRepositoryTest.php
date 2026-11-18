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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;

/**
 * @mago-expect lint:too-many-methods One test per rule of the repository.
 */
final class InMemoryPaymentRecordRepositoryTest extends TestCase
{
    private InMemoryPaymentRecordRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryPaymentRecordRepository();
    }

    public function testASavedRecordIsReadBackAsACopy(): void
    {
        $record = Records::payment('p1');
        $this->repository->save($record);

        $read = $this->repository->get(PaymentRecordId::fromString('p1'));

        static::assertNotSame($record, $read);
        static::assertEquals($record, $read);
    }

    public function testAChangeIsOnlyVisibleOnceSaved(): void
    {
        $this->repository->save(Records::payment('p1'));
        $read = $this->repository->get(PaymentRecordId::fromString('p1'));

        $read->startSubmission(Records::at(5));

        static::assertSame(PaymentState::Pending, $this->repository->get(PaymentRecordId::fromString('p1'))->state());
        $this->repository->save($read);
        static::assertSame(PaymentState::Submitting, $this->repository->get(PaymentRecordId::fromString('p1'))->state());
    }

    public function testTheEventsAreNeitherPersistedNorConsumed(): void
    {
        $record = PaymentRecord::record(
            PaymentRecordId::fromString('p1'),
            Records::tenant(),
            PaymentReference::fromString('ref-1'),
            Records::siret(),
            Records::order(10, 1500),
            null,
            Records::at(),
        );

        $this->repository->save($record);

        static::assertCount(1, $record->releaseEvents());
        static::assertSame([], $this->repository->get(PaymentRecordId::fromString('p1'))->releaseEvents());
    }

    public function testAnUnknownRecordIsNotFound(): void
    {
        try {
            $this->repository->get(PaymentRecordId::fromString('missing'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('payment_record.not_found', $notFound->violationCode());
            static::assertSame('missing', $notFound->identifier());
        }
    }

    public function testASupersessionSavesTheSupersededRecordFirst(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Rejected, 'p1', Slot::new()->reference('ref-1')));
        $old = $this->repository->get(PaymentRecordId::fromString('p1'));
        $new = $old->supersede(PaymentRecordId::fromString('p2'), Records::order(10, 1600), Records::at(5));

        $this->repository->save($old);
        $this->repository->save($new);

        $current = $this->repository->findByReference(Records::tenant(), PaymentReference::fromString('ref-1'));
        static::assertSame('p2', $current?->id()->value());
        $kept = $this->repository->get(PaymentRecordId::fromString('p1'));
        static::assertSame('p2', $kept->supersededBy()?->value());
        static::assertFalse($kept->isCurrent());
        static::assertCount(2, $this->repository->all());
    }

    public function testASupersessionSavingTheNewRecordFirstIsRefused(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Rejected, 'p1', Slot::new()->reference('ref-1')));
        $old = $this->repository->get(PaymentRecordId::fromString('p1'));
        $new = $old->supersede(PaymentRecordId::fromString('p2'), Records::order(10, 1600), Records::at(5));

        try {
            $this->repository->save($new);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(PaymentRecordRepository::DUPLICATE_REFERENCE, $violation->violationCode());
            static::assertSame([], $violation->params());
        }
        static::assertCount(1, $this->repository->all());
    }

    public function testTheSupersededRecordCanBeSavedAgainWhileTheNewOneIsCurrent(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Rejected, 'p1', Slot::new()->reference('ref-1')));
        $old = $this->repository->get(PaymentRecordId::fromString('p1'));
        $new = $old->supersede(PaymentRecordId::fromString('p2'), Records::order(10, 1600), Records::at(5));
        $this->repository->save($old);
        $this->repository->save($new);

        $this->repository->save($this->repository->get(PaymentRecordId::fromString('p1')));

        static::assertCount(2, $this->repository->all());
    }

    public function testASecondRecordOfTheSameReferenceIsRefusedInTheSameTenantOnly(): void
    {
        $this->repository->save(Records::payment('p1', Slot::new()->reference('ref-1')));
        $this->repository->save(Records::payment('p2', Slot::new()->tenant('tenant-2')->reference('ref-1')));
        $this->repository->save(Records::payment('p3', Slot::new()->reference('ref-2')));

        $this->assertDuplicate(Records::payment('p4', Slot::new()->siret(2)->reference('ref-1')));
        static::assertCount(3, $this->repository->all());
    }

    public function testSavingTheSameRecordAgainIsAnUpdate(): void
    {
        $record = Records::payment('p1');
        $this->repository->save($record);
        $this->repository->save($record);

        static::assertCount(1, $this->repository->all());
    }

    public function testTwoConcurrentSupersessionsLeaveTheLoserRefused(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Rejected, 'p1', Slot::new()->reference('ref-1')));
        $winnerOld = $this->repository->get(PaymentRecordId::fromString('p1'));
        $loserOld = $this->repository->get(PaymentRecordId::fromString('p1'));
        $winnerNew = $winnerOld->supersede(PaymentRecordId::fromString('p2'), Records::order(10, 1600), Records::at(5));
        $loserNew = $loserOld->supersede(PaymentRecordId::fromString('p3'), Records::order(10, 1700), Records::at(6));
        $this->repository->save($winnerOld);
        $this->repository->save($winnerNew);

        $this->assertConcurrent($loserOld);
        $this->assertDuplicate($loserNew);
    }

    public function testFindByReferenceIsScopedToTheTenantAndTheCurrentRecord(): void
    {
        $this->repository->save(Records::payment('p1', Slot::new()->reference('ref-1')));
        $this->repository->save(Records::payment('p2', Slot::new()->tenant('tenant-2')->reference('ref-1')));

        static::assertSame('p1', $this->repository->findByReference(Records::tenant(), PaymentReference::fromString('ref-1'))?->id()->value());
        static::assertSame('p2', $this->repository->findByReference(Records::tenant('tenant-2'), PaymentReference::fromString('ref-1'))?->id()->value());
        static::assertNull($this->repository->findByReference(Records::tenant(), PaymentReference::fromString('ref-2')));
        static::assertNull($this->repository->findByReference(Records::tenant('tenant-3'), PaymentReference::fromString('ref-1')));
    }

    public function testTheCurrentRecordsOfAPeriodAreFilteredByStateOldestFirstTiesById(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Paid, 'c', Slot::new()->minutes(5)));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'b'));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'a'));
        $this->repository->save(Records::paymentIn(PaymentState::Rejected, 'rejected', Slot::new()->minutes(1)));
        $this->repository->save(Records::paymentIn(PaymentState::Paid, 'other-period', Slot::new()->month(11)));
        $this->repository->save(Records::paymentIn(PaymentState::Paid, 'other-siret', Slot::new()->siret(2)));
        $this->repository->save(Records::paymentIn(PaymentState::Paid, 'other-tenant', Slot::new()->tenant('tenant-2')));

        $found = $this->repository->findCurrentForPeriod(
            Records::tenant(),
            Records::siret(1),
            Records::period(10),
            [PaymentState::Submitting, PaymentState::Paid],
        );

        static::assertSame(['a', 'b', 'c'], self::ids($found));
        static::assertSame(
            ['rejected'],
            self::ids($this->repository->findCurrentForPeriod(Records::tenant(), Records::siret(1), Records::period(10), [PaymentState::Rejected])),
        );
        static::assertSame(
            [],
            $this->repository->findCurrentForPeriod(Records::tenant(), Records::siret(1), Records::period(12), [PaymentState::Paid]),
        );
    }

    public function testASupersededRecordIsNotACurrentRecordOfThePeriod(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Rejected, 'p1', Slot::new()->reference('ref-1')));
        $old = $this->repository->get(PaymentRecordId::fromString('p1'));
        $new = $old->supersede(PaymentRecordId::fromString('p2'), Records::order(10, 1600), Records::at(5));
        $this->repository->save($old);
        $this->repository->save($new);

        $found = $this->repository->findCurrentForPeriod(
            Records::tenant(),
            Records::siret(),
            Records::period(),
            [PaymentState::Pending, PaymentState::Rejected],
        );

        static::assertSame(['p2'], self::ids($found));
    }

    public function testFindByStateIsLeastRecentlyUpdatedFirstTiesByIdAndLimited(): void
    {
        $this->repository->save(Records::payment('p3', Slot::new()->minutes(5)));
        $this->repository->save(Records::payment('p2', Slot::new()->minutes(5)));
        $this->repository->save(Records::payment('p1', Slot::new()->minutes(9)));
        $this->repository->save(Records::payment('p0', Slot::new()->minutes(1)));
        $this->repository->save(Records::payment('other-tenant', Slot::new()->tenant('tenant-2')));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'other-state'));

        static::assertSame(['p0', 'p2', 'p3', 'p1'], self::ids($this->repository->findByState(Records::tenant(), PaymentState::Pending, 10)));
        static::assertSame(['p0', 'p2'], self::ids($this->repository->findByState(Records::tenant(), PaymentState::Pending, 2)));
        static::assertSame(['other-state'], self::ids($this->repository->findByState(Records::tenant(), PaymentState::Submitting, 10)));
        static::assertSame([], $this->repository->findByState(Records::tenant('tenant-3'), PaymentState::Pending, 10));
    }

    public function testStaleSubmissionsAreSelectedAtOrBeforeTheInstantOfEveryTenantOldestFirst(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'b', Slot::new()->minutes(9)));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'a', Slot::new()->minutes(9)));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'old', Slot::new()->tenant('tenant-2')->minutes(3)));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'fresh', Slot::new()->minutes(30)));
        $this->repository->save(Records::paymentIn(PaymentState::Paid, 'settled'));
        $this->repository->save(Records::payment('never-started'));

        static::assertSame(['old', 'a', 'b'], self::ids($this->repository->findStaleSubmissions(Records::at(10), 10)));
        static::assertSame(['old', 'a'], self::ids($this->repository->findStaleSubmissions(Records::at(10), 2)));
        static::assertSame(['old'], self::ids($this->repository->findStaleSubmissions(Records::at(10), 1)));
        static::assertSame(['old'], self::ids($this->repository->findStaleSubmissions(Records::at(9), 10)));
        static::assertSame([], $this->repository->findStaleSubmissions(Records::at(3), 10));
    }

    public function testTheCountsIncludeEveryStateWithZeros(): void
    {
        $this->repository->save(Records::payment('p1'));
        $this->repository->save(Records::payment('p2', Slot::new()->siret(2)));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'p3', Slot::new()->siret(3)));
        $this->repository->save(Records::payment('p4', Slot::new()->tenant('tenant-2')));

        $counts = $this->repository->countByState(Records::tenant());

        static::assertSame(array_map(static fn(PaymentState $state): string => $state->value, PaymentState::cases()), array_keys($counts));
        static::assertSame(2, $counts['pending']);
        static::assertSame(1, $counts['submitting']);
        static::assertSame(0, $counts['paid']);
        static::assertSame(0, array_sum($this->repository->countByState(Records::tenant('tenant-3'))));
    }

    public function testTheCountsOfASiretAreScopedToTheTenantAndTheSiret(): void
    {
        $this->repository->save(Records::payment('p1', Slot::new()->month(9)));
        $this->repository->save(Records::payment('p2'));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'p3', Slot::new()->month(11)));
        $this->repository->save(Records::payment('p4', Slot::new()->siret(2)));
        $this->repository->save(Records::payment('p5', Slot::new()->tenant('tenant-2')));

        $counts = $this->repository->countBySiret(Records::tenant(), Records::siret(1));

        static::assertSame(2, $counts['pending']);
        static::assertSame(1, $counts['submitting']);
        static::assertSame(0, $counts['paid']);
        static::assertCount(7, $counts);
        static::assertSame(0, array_sum($this->repository->countBySiret(Records::tenant(), Records::siret(3))));
        static::assertSame(1, $this->repository->countBySiret(Records::tenant('tenant-2'), Records::siret(1))['pending']);
    }

    public function testTheTenantsWithAStateAreDistinctAndSorted(): void
    {
        $this->repository->save(Records::payment('p1', Slot::new()->tenant('tenant-b')));
        $this->repository->save(Records::payment('p2', Slot::new()->siret(2)->tenant('tenant-a')));
        $this->repository->save(Records::payment('p3', Slot::new()->siret(3)->tenant('tenant-b')));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'p4', Slot::new()->siret(4)->tenant('tenant-c')));

        static::assertSame(['tenant-a', 'tenant-b'], self::tenantValues($this->repository->tenantsWithState(PaymentState::Pending)));
        static::assertSame(['tenant-c'], self::tenantValues($this->repository->tenantsWithState(PaymentState::Submitting)));
        static::assertSame([], $this->repository->tenantsWithState(PaymentState::Paid));
    }

    public function testAClaimWinsOnceAndCountsTheAttemptWithoutAnyEvent(): void
    {
        $this->repository->save(Records::payment('p1'));
        $caller = $this->repository->get(PaymentRecordId::fromString('p1'));

        static::assertTrue($this->repository->claimForSubmission(PaymentRecordId::fromString('p1'), Records::at(7)));
        static::assertFalse($this->repository->claimForSubmission(PaymentRecordId::fromString('p1'), Records::at(8)));

        $claimed = $this->repository->get(PaymentRecordId::fromString('p1'));
        static::assertSame(PaymentState::Submitting, $claimed->state());
        static::assertSame(1, $claimed->attempts());
        static::assertTrue(Records::at(7)->equals($claimed->lastAttemptAt() ?? Records::at(99)));
        static::assertSame([], $claimed->releaseEvents());
        static::assertSame(PaymentState::Pending, $caller->state());
        static::assertSame([], $caller->releaseEvents());
        $this->assertConcurrent($caller);
    }

    public function testAClaimIsRefusedForAMissingNotPendingOrSupersededRecord(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'busy'));
        $this->repository->save(Records::paymentIn(PaymentState::Rejected, 'old', Slot::new()->siret(2)->reference('ref-old')));
        $old = $this->repository->get(PaymentRecordId::fromString('old'));
        $new = $old->supersede(PaymentRecordId::fromString('new'), Records::order(10, 1600), Records::at(5));
        $this->repository->save($old);
        $this->repository->save($new);

        static::assertFalse($this->repository->claimForSubmission(PaymentRecordId::fromString('missing'), Records::at(7)));
        static::assertFalse($this->repository->claimForSubmission(PaymentRecordId::fromString('busy'), Records::at(7)));
        static::assertFalse($this->repository->claimForSubmission(PaymentRecordId::fromString('old'), Records::at(7)));
        static::assertSame(PaymentState::Rejected, $this->repository->get(PaymentRecordId::fromString('old'))->state());
        static::assertTrue($this->repository->claimForSubmission(PaymentRecordId::fromString('new'), Records::at(7)));
    }

    public function testDeleteBySiretDeletesTheGivenStatesOldestCreationFirst(): void
    {
        $this->repository->save(Records::payment('c', Slot::new()->month(8)->minutes(5)));
        $this->repository->save(Records::payment('b', Slot::new()->month(9)));
        $this->repository->save(Records::payment('a'));
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'busy', Slot::new()->month(11)));
        $this->repository->save(Records::payment('other-siret', Slot::new()->siret(2)));
        $this->repository->save(Records::payment('other-tenant', Slot::new()->tenant('tenant-2')));

        $deleted = $this->repository->deleteBySiret(
            Records::tenant(),
            Records::siret(1),
            [PaymentState::Pending, PaymentState::Paid],
        );

        static::assertSame(['a', 'b', 'c'], array_map(static fn(PaymentRecordId $id): string => $id->value(), $deleted));
        static::assertSame(['busy', 'other-siret', 'other-tenant'], self::ids($this->repository->all()));
        static::assertSame([], $this->repository->deleteBySiret(Records::tenant(), Records::siret(1), [PaymentState::Pending]));
        static::assertSame([], $this->repository->deleteBySiret(Records::tenant('tenant-3'), Records::siret(1), [PaymentState::Submitting]));
    }

    public function testADeletedRecordIsGoneStaleAndItsKeyIsFree(): void
    {
        $this->repository->save(Records::payment('p1', Slot::new()->reference('ref-1')));
        $read = $this->repository->get(PaymentRecordId::fromString('p1'));

        $this->repository->deleteBySiret(Records::tenant(), Records::siret(), [PaymentState::Pending]);

        $this->assertConcurrent($read);
        try {
            $this->repository->get(PaymentRecordId::fromString('p1'));
            static::fail('Expected RecordNotFound.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('payment_record.not_found', $notFound->violationCode());
        }
        $this->repository->save(Records::payment('p2', Slot::new()->reference('ref-1')));
        static::assertSame(['p2'], self::ids($this->repository->all()));
    }

    public function testAStaleAnErasedAndAQueryCopyAreRefused(): void
    {
        $this->repository->save(Records::payment('p1', Slot::new()->reference('ref-1')));
        $first = $this->repository->get(PaymentRecordId::fromString('p1'));
        $second = $this->repository->get(PaymentRecordId::fromString('p1'));
        $byState = $this->repository->findByState(Records::tenant(), PaymentState::Pending, 1)[0];
        $byReference = $this->repository->findByReference(Records::tenant(), PaymentReference::fromString('ref-1'));
        $forPeriod = $this->repository->findCurrentForPeriod(Records::tenant(), Records::siret(), Records::period(), [PaymentState::Pending])[0];
        $this->repository->save($first);
        $this->repository->save($first);

        $this->assertConcurrent($second);
        $this->assertConcurrent($byState);
        static::assertInstanceOf(PaymentRecord::class, $byReference);
        $this->assertConcurrent($byReference);
        $this->assertConcurrent($forPeriod);
    }

    public function testStaleSubmissionCopiesAndANeverReadInstanceAreRefused(): void
    {
        $this->repository->save(Records::paymentIn(PaymentState::Submitting, 'p1', Slot::new()->reference('ref-1')));
        $stale = $this->repository->findStaleSubmissions(Records::at(10), 1)[0];
        $this->repository->save($this->repository->get(PaymentRecordId::fromString('p1')));

        $this->assertConcurrent($stale);
        $this->assertConcurrent(Records::payment('p1', Slot::new()->reference('ref-2')));
    }

    private function assertConcurrent(PaymentRecord $record): void
    {
        try {
            $this->repository->save($record);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(PaymentRecordRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
    }

    private function assertDuplicate(PaymentRecord $record): void
    {
        try {
            $this->repository->save($record);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $violation) {
            static::assertSame(PaymentRecordRepository::DUPLICATE_REFERENCE, $violation->violationCode());
        }
    }

    /**
     * @param list<PaymentRecord> $records
     *
     * @return list<string>
     */
    private static function ids(array $records): array
    {
        return array_map(static fn(PaymentRecord $record): string => $record->id()->value(), $records);
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
