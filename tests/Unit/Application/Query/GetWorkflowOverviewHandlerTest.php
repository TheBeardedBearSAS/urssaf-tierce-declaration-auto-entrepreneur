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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\TenantOverview;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\StateCount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverview;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverviewHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedWorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

/**
 * GetWorkflowOverview: the records by tenant and state, with the declarations accepted after their supersession.
 */
final class GetWorkflowOverviewHandlerTest extends TestCase
{
    public function testEveryTenantThatHasRecordsIsListedInStringOrderWithEveryStatePresent(): void
    {
        $world = new PaymentWorld();
        $statistics = new FixedWorkflowStatistics(
            [new StateCount(TenantId::fromString('9'), 'notified', 1)],
            [new StateCount(TenantId::fromString('10'), 'declared', 2)],
            [new StateCount(TenantId::fromString('abc'), 'paid', 3), new StateCount(TenantId::fromString('9'), 'pending', 4)],
        );

        $overviews = (new GetWorkflowOverviewHandler($statistics, $world->declarations))(GetWorkflowOverview::allTenants());

        static::assertSame(['10', '9', 'abc'], array_map(static fn(TenantOverview $overview): string => $overview->tenantId, $overviews));
        [$ten, $nine, $abc] = $overviews;
        static::assertSame(2, $ten->declarations['declared']);
        static::assertSame(0, $ten->declarations['pending']);
        static::assertSame(0, $ten->mandates['notified']);
        static::assertSame(0, $ten->payments['paid']);
        static::assertSame(1, $nine->mandates['notified']);
        static::assertSame(4, $nine->payments['pending']);
        static::assertSame(3, $abc->payments['paid']);
        static::assertSame(0, $abc->mandates['notified']);
        static::assertSame(
            ['pending', 'submitting', 'declared', 'rejected', 'outcome_unknown', 'error', 'invalid_credentials'],
            array_keys($ten->declarations),
        );
        static::assertSame(
            ['pending', 'submitting', 'paid', 'rejected', 'outcome_unknown', 'error', 'invalid_credentials'],
            array_keys($ten->payments),
        );
        static::assertSame(['notified', 'cancelled'], array_keys($ten->mandates));
    }

    public function testATenantWithoutRecordsHasZerosEverywhere(): void
    {
        $world = new PaymentWorld();
        $statistics = new FixedWorkflowStatistics(
            [new StateCount(TenantId::fromString('tenant-2'), 'notified', 5)],
            [new StateCount(TenantId::fromString('tenant-2'), 'declared', 6)],
            [new StateCount(TenantId::fromString('tenant-2'), 'paid', 7)],
        );

        $overviews = (new GetWorkflowOverviewHandler($statistics, $world->declarations))(GetWorkflowOverview::forTenant(PaymentWorld::tenant()));

        static::assertCount(1, $overviews);
        static::assertSame('tenant-1', $overviews[0]->tenantId);
        static::assertSame(0, array_sum($overviews[0]->mandates));
        static::assertSame(0, array_sum($overviews[0]->declarations));
        static::assertSame(0, array_sum($overviews[0]->payments));
        static::assertSame(0, $overviews[0]->declarationsAcceptedAfterSupersession);
        static::assertSame(0, $overviews[0]->needingAttention());
    }

    public function testTheRequestedTenantOnlyIsCounted(): void
    {
        $world = new PaymentWorld();
        $statistics = new FixedWorkflowStatistics(
            [new StateCount(PaymentWorld::tenant(), 'notified', 1), new StateCount(TenantId::fromString('tenant-2'), 'notified', 10)],
            [new StateCount(PaymentWorld::tenant(), 'declared', 2), new StateCount(TenantId::fromString('tenant-2'), 'declared', 20)],
            [new StateCount(PaymentWorld::tenant(), 'paid', 3), new StateCount(TenantId::fromString('tenant-2'), 'paid', 30)],
        );

        $overviews = (new GetWorkflowOverviewHandler($statistics, $world->declarations))(GetWorkflowOverview::forTenant(PaymentWorld::tenant()));

        static::assertCount(1, $overviews);
        static::assertSame([1, 2, 3], [$overviews[0]->mandates['notified'], $overviews[0]->declarations['declared'], $overviews[0]->payments['paid']]);
    }

    public function testATenantThatComesAfterAnotherOneInTheStatisticsIsStillCounted(): void
    {
        $world = new PaymentWorld();
        $statistics = new FixedWorkflowStatistics(
            [new StateCount(TenantId::fromString('tenant-2'), 'notified', 10), new StateCount(PaymentWorld::tenant(), 'notified', 1)],
            [],
            [],
        );

        $overviews = (new GetWorkflowOverviewHandler($statistics, $world->declarations))(GetWorkflowOverview::forTenant(PaymentWorld::tenant()));

        static::assertSame(1, $overviews[0]->mandates['notified']);
    }

    public function testTheDeclarationsAcceptedAfterTheirSupersessionAreCountedPerTenant(): void
    {
        $world = new PaymentWorld();
        self::acceptedAfterSupersession($world, 'a1', 'a1b', 'tenant-1', 9);
        self::acceptedAfterSupersession($world, 'a2', 'a2b', 'tenant-1', 8);
        self::acceptedAfterSupersession($world, 'b1', 'b1b', 'tenant-2', 9);
        $statistics = new FixedWorkflowStatistics(
            [],
            [new StateCount(PaymentWorld::tenant(), 'declared', 2), new StateCount(TenantId::fromString('tenant-2'), 'declared', 1)],
            [],
        );

        $overviews = (new GetWorkflowOverviewHandler($statistics, $world->declarations))(GetWorkflowOverview::allTenants());

        static::assertSame(['tenant-1', 'tenant-2'], array_map(static fn(TenantOverview $overview): string => $overview->tenantId, $overviews));
        static::assertSame(2, $overviews[0]->declarationsAcceptedAfterSupersession);
        static::assertSame(1, $overviews[1]->declarationsAcceptedAfterSupersession);
        static::assertSame(2, $overviews[0]->needingAttention());
        static::assertSame(1, $overviews[1]->needingAttention());
    }

    public function testEveryTermOfTheAttentionCountIsDistinct(): void
    {
        $overview = new TenantOverview(
            'tenant-1',
            ['notified' => 1000, 'cancelled' => 1000],
            [
                'pending' => 1000, 'submitting' => 1000, 'declared' => 1000, 'rejected' => 1000,
                'outcome_unknown' => 1, 'error' => 2, 'invalid_credentials' => 4,
            ],
            [
                'pending' => 1000, 'submitting' => 1000, 'paid' => 1000, 'rejected' => 1000,
                'outcome_unknown' => 8, 'error' => 16, 'invalid_credentials' => 32,
            ],
            64,
        );

        static::assertSame(127, $overview->needingAttention());
    }

    public function testTheOverviewIsAnArray(): void
    {
        $world = new PaymentWorld();
        $statistics = new FixedWorkflowStatistics([], [new StateCount(PaymentWorld::tenant(), 'error', 2)], [new StateCount(PaymentWorld::tenant(), 'error', 3)]);

        $array = (new GetWorkflowOverviewHandler($statistics, $world->declarations))(GetWorkflowOverview::allTenants())[0]->toArray();

        static::assertSame(
            ['tenant_id', 'mandates', 'declarations', 'payments', 'declarations_accepted_after_supersession', 'needing_attention'],
            array_keys($array),
        );
        static::assertSame('tenant-1', $array['tenant_id']);
        static::assertSame(2, $array['declarations']['error']);
        static::assertSame(3, $array['payments']['error']);
        static::assertSame(0, $array['declarations_accepted_after_supersession']);
        static::assertSame(5, $array['needing_attention']);
    }

    /**
     * A rejected declaration superseded by another, then accepted after all (late acceptance, flagged).
     */
    private static function acceptedAfterSupersession(PaymentWorld $world, string $oldId, string $newId, string $tenant, int $month): void
    {
        $at = Instant::fromIso8601(PaymentWorld::RECORDED_AT);
        $fingerprint = DeclarationFingerprint::of(
            PaymentWorld::tenant($tenant),
            PaymentWorld::siret(),
            PaymentWorld::period($month),
            Turnover::of(Euros::of(1000), null, null, null, null),
        );
        $old = DeclarationRecord::record(
            DeclarationRecordId::fromString($oldId),
            PaymentWorld::tenant($tenant),
            PaymentWorld::siret(),
            PaymentWorld::period($month),
            $fingerprint,
            null,
            $at,
        );
        $old->startSubmission($at);
        $old->markRejected(PaymentWorld::errors(), $at);
        $new = $old->supersede(DeclarationRecordId::fromString($newId), $fingerprint, $at);
        $old->markDeclared(DeclarationResult::resolved(Certificate::fromString('12345678')), $at);
        $old->releaseEvents();
        $new->releaseEvents();
        $world->declarations->add($old, $new);
    }
}
