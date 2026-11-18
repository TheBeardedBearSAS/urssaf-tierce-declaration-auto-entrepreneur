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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\EstimateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\EstimateContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\EstimateContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Contribution\ContributionSamples;
use Throwable;

/**
 * @mago-expect lint:too-many-methods
 */
final class EstimateContributionsHandlerTest extends TestCase
{
    public function testTheMandateCheckIsNotSkippedByDefault(): void
    {
        $query = new EstimateContributions(DeclarationWorld::tenant(), DeclarationWorld::siret(), DeclarationWorld::turnover());

        static::assertFalse($query->skipMandateCheck);
    }

    public function testItReturnsTheEstimateOfUrssafWithoutRecordingAnything(): void
    {
        $world = new DeclarationWorld();
        $world->activeMandate();
        $world->journal->clear();
        $world->gateway->queue(Operation::Estimate, Estimate::of(ContributionSamples::amounts(1234), DeclarationWorld::period()));

        $view = $this->handler($world)(new EstimateContributions(DeclarationWorld::tenant(), DeclarationWorld::siret(), DeclarationWorld::turnover()));

        static::assertSame('M', $view->periodicity);
        static::assertSame(2026, $view->periodYear);
        static::assertSame(10, $view->periodNumber);
        $amounts = $view->amounts;
        static::assertSame(1234, $amounts->total);
        static::assertSame(100, $amounts->sales);
        static::assertSame(200, $amounts->furnishedRental);
        static::assertSame(300, $amounts->commercialServices);
        static::assertSame(400, $amounts->otherServices);
        static::assertSame(500, $amounts->cipav);
        static::assertSame(600, $amounts->vocationalTraining);
        static::assertSame(-10, $amounts->acreRegularisation);
        static::assertSame(-20, $amounts->deduction);
        static::assertSame([1, 2, 3, 4, 5], [
            $amounts->liberatory->sales,
            $amounts->liberatory->furnishedRental,
            $amounts->liberatory->commercialServices,
            $amounts->liberatory->otherServices,
            $amounts->liberatory->cipav,
        ]);
        static::assertSame([6, 7, 8, 9], [
            $amounts->chamberFees->cmaSales,
            $amounts->chamberFees->cmaServices,
            $amounts->chamberFees->cciSales,
            $amounts->chamberFees->cciServices,
        ]);
        static::assertSame(['call:040'], $world->journal->entries());
        static::assertEquals([DeclarationWorld::turnover()], $world->gateway->turnovers());
        static::assertTrue(DeclarationWorld::siret()->equals($world->gateway->sirets()[0]));
        static::assertSame([], $world->outbox->appended());
        static::assertSame([], $world->records->inner()->all());
    }

    public function testAQuarterlyEstimateKeepsItsQuarter(): void
    {
        $world = new DeclarationWorld();
        $world->activeMandate();
        $world->gateway->queue(Operation::Estimate, Estimate::of(ContributionSamples::amounts(1), QuarterlyPeriod::of(2026, 3)));

        $view = $this->estimate($world, false);

        static::assertSame('T', $view->periodicity);
        static::assertSame(3, $view->periodNumber);
    }

    public function testAnEstimateWithoutAnyAmountKeepsTheNulls(): void
    {
        $world = new DeclarationWorld();
        $world->activeMandate();
        $world->gateway->queue(Operation::Estimate, Estimate::of(DeclarationWorld::amounts(), DeclarationWorld::period()));

        $view = $this->estimate($world, false);

        static::assertSame(100, $view->amounts->total);
        static::assertNull($view->amounts->furnishedRental);
        static::assertNull($view->amounts->acreRegularisation);
        static::assertNull($view->amounts->deduction);
        static::assertNull($view->amounts->liberatory->sales);
        static::assertNull($view->amounts->chamberFees->cmaSales);
    }

    public function testTheMandateGuardRunsBeforeTheCall(): void
    {
        $world = new DeclarationWorld();

        $thrown = $this->estimateAndCatch($world, false);

        static::assertInstanceOf(MandateRequired::class, $thrown);
        static::assertSame(['operation' => '040'], $thrown->params());
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->journal->entries());
    }

    public function testTheMandateGuardCanBeSkippedForTheQuery(): void
    {
        $world = new DeclarationWorld();
        $world->gateway->queue(Operation::Estimate, Estimate::of(ContributionSamples::amounts(1), DeclarationWorld::period()));

        static::assertSame(1, $this->estimate($world, true)->amounts->total);
    }

    public function testTheMandateGuardCanBeSwitchedOffByThePolicy(): void
    {
        $world = new DeclarationWorld((new WorkflowPolicy())->withRequireLocalMandate(false));
        $world->gateway->queue(Operation::Estimate, Estimate::of(ContributionSamples::amounts(1), DeclarationWorld::period()));

        static::assertSame(1, $this->estimate($world, false)->amounts->total);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'a rejection' => ['rejected'];
        yield 'an unavailable URSSAF' => ['unavailable'];
        yield 'a local rate limit' => ['rate_limit'];
        yield 'invalid credentials' => ['credentials'];
    }

    #[DataProvider('failures')]
    public function testEveryFailurePropagatesAndNothingIsRecorded(string $name): void
    {
        $world = new DeclarationWorld();
        $world->activeMandate();
        $world->journal->clear();
        $failure = match ($name) {
            'rejected' => UrssafRequestRejected::withErrors(Operation::Estimate, 400, ApiErrors::none(), RejectionKind::MandateMissing),
            'unavailable' => UrssafUnavailable::withStatus(Operation::Estimate, 503, null, ApiErrors::none()),
            'rate_limit' => RateLimitExceeded::forOperation(Operation::Estimate, 3),
            default => UrssafAuthenticationFailed::withStatus(401),
        };
        $world->gateway->queue(Operation::Estimate, $failure);

        $thrown = $this->estimateAndCatch($world, false);

        static::assertSame($failure, $thrown);
        static::assertSame(['call:040'], $world->journal->entries());
        static::assertSame([], $world->records->inner()->all());
    }

    private function handler(DeclarationWorld $world): EstimateContributionsHandler
    {
        return new EstimateContributionsHandler($world->gateway, $world->guard());
    }

    private function estimate(DeclarationWorld $world, bool $skipMandateCheck): EstimateView
    {
        return $this->handler($world)(new EstimateContributions(DeclarationWorld::tenant(), DeclarationWorld::siret(), DeclarationWorld::turnover(), $skipMandateCheck));
    }

    private function estimateAndCatch(DeclarationWorld $world, bool $skipMandateCheck): ?Throwable
    {
        try {
            $this->estimate($world, $skipMandateCheck);
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
