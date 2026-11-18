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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateGuard;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

final class MandateGuardTest extends TestCase
{
    public function testAnActiveLocalMandatePasses(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();

        (new MandateGuard($world->mandates, $world->policy))->assertMandate(AccountWorld::tenant(), AccountWorld::siret(), Operation::Declare, false);

        $this->expectNotToPerformAssertions();
    }

    public function testWithoutAMandateTheOperationIsRefusedLocally(): void
    {
        $world = new AccountWorld();

        try {
            (new MandateGuard($world->mandates, $world->policy))->assertMandate(AccountWorld::tenant(), AccountWorld::siret(), Operation::Pay, false);
            static::fail('The mandate guard must refuse.');
        } catch (MandateRequired $refusal) {
            static::assertSame('mandate.required', $refusal->violationCode());
            static::assertSame(['operation' => '060'], $refusal->params());
        }
    }

    public function testACancelledMandateDoesNotPass(): void
    {
        $world = new AccountWorld();
        $world->cancelledMandate();

        $this->expectException(MandateRequired::class);

        (new MandateGuard($world->mandates, $world->policy))->assertMandate(AccountWorld::tenant(), AccountWorld::siret(), Operation::Declare, false);
    }

    public function testTheMandateOfAnotherSiretOrTenantDoesNotPass(): void
    {
        $world = new AccountWorld();
        $world->activeMandate('mandate-1', AccountWorld::OTHER_SIRET);
        $guard = new MandateGuard($world->mandates, $world->policy);

        $this->expectException(MandateRequired::class);

        $guard->assertMandate(AccountWorld::tenant(), AccountWorld::siret(), Operation::Declare, false);
    }

    public function testTheMandateOfAnotherTenantDoesNotPass(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();
        $guard = new MandateGuard($world->mandates, $world->policy);

        $this->expectException(MandateRequired::class);

        $guard->assertMandate(AccountWorld::tenant('tenant-2'), AccountWorld::siret(), Operation::Declare, false);
    }

    public function testASyncedAccountThatReportsAMandateNeverSatisfiesTheGuard(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount('account-1', AccountWorld::BIRTH_DATE, true);

        $this->expectException(MandateRequired::class);

        (new MandateGuard($world->mandates, $world->policy))->assertMandate(AccountWorld::tenant(), AccountWorld::siret(), Operation::Declare, false);
    }

    public function testTheCommandCanSkipTheCheck(): void
    {
        $world = new AccountWorld();

        (new MandateGuard($world->mandates, $world->policy))->assertMandate(AccountWorld::tenant(), AccountWorld::siret(), Operation::Declare, true);

        $this->expectNotToPerformAssertions();
    }

    public function testThePolicyCanDisableTheCheck(): void
    {
        $world = new AccountWorld();
        $policy = (new WorkflowPolicy())->withRequireLocalMandate(false);

        (new MandateGuard($world->mandates, $policy))->assertMandate(AccountWorld::tenant(), AccountWorld::siret(), Operation::Estimate, false);

        $this->expectNotToPerformAssertions();
    }

    public function testTheRefusalNeverHoldsTheSiret(): void
    {
        $refusal = MandateRequired::forOperation(Operation::Declare);

        static::assertStringNotContainsString(AccountWorld::SIRET, $refusal->getMessage());
        static::assertSame('No active local mandate is known for the SIRET: method 050 is refused locally (ADR 0011).', $refusal->getMessage());
        static::assertStringNotContainsString(AccountWorld::SIRET, implode(',', array_map(strval(...), $refusal->params())));
    }
}
