<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Dto;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ChamberFeesView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ContributionAmountsView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationKey;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationResultView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationStatusView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\EstimateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\LiberatoryContributionsView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Contribution\ContributionSamples;

/**
 * @mago-expect lint:too-many-methods
 */
final class DeclarationViewsTest extends TestCase
{
    public function testTheViewOfADeclaredRecordCarriesItsIdentityAndItsStatus(): void
    {
        $world = new DeclarationWorld();
        $record = $world->recordWith('record-1', DeclarationState::Declared, 1, DeclarationWorld::fingerprint(), AccountId::fromString('account-1'));

        $view = DeclarationView::fromRecord($record);

        static::assertSame('record-1', $view->recordId);
        static::assertSame('tenant-1', $view->tenantId);
        static::assertSame('73282932000074', $view->siret);
        static::assertSame('account-1', $view->accountId);
        static::assertSame('M', $view->periodicity);
        static::assertSame(2026, $view->periodYear);
        static::assertSame(10, $view->periodNumber);
        static::assertNull($view->supersedes);
        static::assertNull($view->supersededBy);
        static::assertFalse($view->acceptedAfterSupersession);
        static::assertSame('2026-11-17T08:00:00Z', $view->createdAt);
        static::assertSame('declared', $view->status->state);
        static::assertSame(1, $view->status->attempts);
        static::assertSame('2026-11-17T08:00:00Z', $view->status->lastAttemptAt);
        static::assertSame('2026-11-17T08:00:00Z', $view->status->updatedAt);
        static::assertNull($view->status->outcomeUnknownReason);
        static::assertSame([], $view->status->errors);
    }

    public function testTheResultViewHoldsTheCertificatTheDueDateAndTheAmounts(): void
    {
        $result = DeclarationResultView::fromResult(DeclarationWorld::accepted());

        static::assertSame('12345678', $result->certificat);
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF3', $result->idDeclaration);
        static::assertSame('2026-11-20', $result->dueDate);
        static::assertFalse($result->resolved);
        static::assertSame(100, $result->amounts?->total);
        static::assertSame(60, $result->amounts->sales);
        static::assertNull($result->amounts->furnishedRental);
        static::assertSame(40, $result->amounts->commercialServices);
        static::assertNull($result->amounts->otherServices);
    }

    public function testAResolvedResultHoldsTheCertificatOnly(): void
    {
        $result = DeclarationResultView::fromResult(DeclarationResult::resolved(Certificate::fromString('12345678')));

        static::assertSame('12345678', $result->certificat);
        static::assertNull($result->idDeclaration);
        static::assertNull($result->dueDate);
        static::assertNull($result->amounts);
        static::assertTrue($result->resolved);
    }

    public function testASupersededRecordAndItsSuccessorAreLinked(): void
    {
        $world = new DeclarationWorld();
        $old = $world->recordIn('record-1', DeclarationState::Rejected, 1);
        $new = $old->supersede(DeclarationRecordId::fromString('record-2'), DeclarationWorld::fingerprint(10, 2000), Instant::fromIso8601(DeclarationWorld::NOW));

        $oldView = DeclarationView::fromRecord($old);
        $newView = DeclarationView::fromRecord($new);

        static::assertSame('record-2', $oldView->supersededBy);
        static::assertNull($oldView->supersedes);
        static::assertSame('record-1', $newView->supersedes);
        static::assertNull($newView->supersededBy);
        static::assertNull($newView->accountId);
        static::assertSame('2026-11-17T09:00:00Z', $newView->createdAt);
    }

    public function testALateAcceptanceOfASupersededRecordIsFlagged(): void
    {
        $world = new DeclarationWorld();
        $old = $world->recordIn('record-1', DeclarationState::OutcomeUnknown, 1);
        $old->supersede(DeclarationRecordId::fromString('record-2'), DeclarationWorld::fingerprint(10, 2000), Instant::fromIso8601(DeclarationWorld::NOW));
        $old->markDeclared(DeclarationWorld::accepted(), Instant::fromIso8601(DeclarationWorld::NOW));

        static::assertTrue(DeclarationView::fromRecord($old)->acceptedAfterSupersession);
    }

    public function testAQuarterlyRecordKeepsItsQuarter(): void
    {
        $world = new DeclarationWorld();
        $record = DeclarationRecord::record(
            DeclarationRecordId::fromString('record-q'),
            DeclarationWorld::tenant(),
            DeclarationWorld::siret(),
            QuarterlyPeriod::of(2026, 3),
            DeclarationWorld::fingerprint(),
            null,
            Instant::fromIso8601(DeclarationWorld::RECORDED_AT),
        );

        $view = DeclarationView::fromRecord($record);

        static::assertSame('T', $view->periodicity);
        static::assertSame(3, $view->periodNumber);
        static::assertNull($view->status->lastAttemptAt);
        static::assertNull($view->status->result);
        static::assertSame([], $world->records->inner()->all());
    }

    public function testTheStatusKeepsTheErrorsAndTheReasonOfAnUnknownOutcome(): void
    {
        $world = new DeclarationWorld();
        $record = $world->recordIn('record-1', DeclarationState::Submitting, 1);
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [SubmissionError::of('E1', 'technical', 'Message', 'Description')], Instant::fromIso8601(DeclarationWorld::NOW));

        $status = DeclarationStatusView::fromStatus($record->status());

        static::assertSame('outcome_unknown', $status->state);
        static::assertSame('stale_submission', $status->outcomeUnknownReason);
        static::assertCount(1, $status->errors);
        static::assertSame(['E1', 'technical', 'Message', 'Description'], [$status->errors[0]->code, $status->errors[0]->kind, $status->errors[0]->message, $status->errors[0]->description]);
        static::assertSame(['code' => 'E1', 'kind' => 'technical', 'message' => '***', 'description' => '***'], $status->errors[0]->__debugInfo());
    }

    public function testDebugOutputKeepsTheLastFourDigitsOfTheSiretOnly(): void
    {
        $world = new DeclarationWorld();
        $view = DeclarationView::fromRecord($world->recordIn('record-1', DeclarationState::Pending, 0));

        $debug = $view->__debugInfo();

        static::assertSame('***0074', $debug['siret']);
        static::assertSame('record-1', $debug['recordId']);
        static::assertSame('tenant-1', $debug['tenantId']);
        static::assertNull($debug['accountId']);
        static::assertSame('M', $debug['periodicity']);
        static::assertSame(2026, $debug['periodYear']);
        static::assertSame(10, $debug['periodNumber']);
        static::assertNull($debug['supersedes']);
        static::assertNull($debug['supersededBy']);
        static::assertFalse($debug['acceptedAfterSupersession']);
        static::assertSame($view->status, $debug['status']);
        static::assertSame('2026-11-17T08:00:00Z', $debug['createdAt']);
        static::assertStringNotContainsString('73282932000074', print_r($debug, true));
        static::assertStringNotContainsString('73282932000074', var_export($debug, true));
    }

    public function testDebugOutputKeepsExactlyTheLinksAndTheAccountOfTheRecord(): void
    {
        $world = new DeclarationWorld();
        $old = $world->recordWith('record-1', DeclarationState::Rejected, 1, DeclarationWorld::fingerprint(), AccountId::fromString('account-1'));
        $new = $old->supersede(DeclarationRecordId::fromString('record-2'), DeclarationWorld::fingerprint(10, 2000), Instant::fromIso8601(DeclarationWorld::NOW));

        $oldView = DeclarationView::fromRecord($old);
        $newView = DeclarationView::fromRecord($new);

        static::assertSame(
            ['recordId', 'tenantId', 'siret', 'accountId', 'periodicity', 'periodYear', 'periodNumber', 'supersedes', 'supersededBy', 'acceptedAfterSupersession', 'status', 'createdAt'],
            array_keys($oldView->__debugInfo()),
        );
        static::assertSame('account-1', $oldView->__debugInfo()['accountId']);
        static::assertSame('record-2', $oldView->__debugInfo()['supersededBy']);
        static::assertNull($oldView->__debugInfo()['supersedes']);
        static::assertSame('record-1', $newView->__debugInfo()['supersedes']);
        static::assertNull($newView->__debugInfo()['supersededBy']);
    }

    public function testAnAbsentAmountStaysAbsentInTheView(): void
    {
        $amounts = ContributionAmounts::of(Euros::of(10), null, null, null, null, null, null, LiberatoryContributions::none(), ChamberFees::none(), null, null);

        $view = ContributionAmountsView::fromAmounts($amounts);

        static::assertSame(10, $view->total);
        static::assertNull($view->sales);
        static::assertNull($view->furnishedRental);
    }

    public function testTheSelectorMasksItsReferenceInDebugOutput(): void
    {
        $byId = DeclarationSelector::byRecordId(DeclarationRecordId::fromString('record-1'));
        $current = DeclarationSelector::current(DeclarationWorld::siret(), DeclarationWorld::period());

        static::assertSame(['reference' => '***'], $byId->__debugInfo());
        static::assertSame(['reference' => '***'], $current->__debugInfo());
        static::assertSame('record-1', $byId->reference instanceof DeclarationRecordId ? $byId->reference->value() : null);
        static::assertInstanceOf(DeclarationKey::class, $current->reference);
        static::assertTrue($current->reference->siret->equals(DeclarationWorld::siret()));
        static::assertTrue($current->reference->period->equals(DeclarationWorld::period()));
    }

    public function testTheSubmissionPairsTheViewAndTheOutcome(): void
    {
        $world = new DeclarationWorld();
        $view = DeclarationView::fromRecord($world->recordIn('record-1', DeclarationState::Pending, 0));

        $submission = new DeclarationSubmission($view, ItemOutcome::NotClaimed);

        static::assertSame($view, $submission->declaration);
        static::assertSame(ItemOutcome::NotClaimed, $submission->outcome);
    }

    public function testTheEstimateViewHoldsTheAmountsAndThePeriod(): void
    {
        $view = EstimateView::fromEstimate(Estimate::of(ContributionSamples::amounts(1234), QuarterlyPeriod::of(2026, 4)));

        static::assertSame('T', $view->periodicity);
        static::assertSame(2026, $view->periodYear);
        static::assertSame(4, $view->periodNumber);
        static::assertSame(1234, $view->amounts->total);
    }

    public function testTheAmountViewsAreWholeEuros(): void
    {
        $liberatory = LiberatoryContributionsView::fromContributions(LiberatoryContributions::of(Euros::of(1), null, Euros::of(3), null, Euros::of(5)));
        $fees = ChamberFeesView::fromFees(ChamberFees::of(null, Euros::of(7), null, Euros::of(9)));
        $amounts = ContributionAmountsView::fromAmounts(ContributionSamples::amounts(1234));

        static::assertSame([1, null, 3, null, 5], [$liberatory->sales, $liberatory->furnishedRental, $liberatory->commercialServices, $liberatory->otherServices, $liberatory->cipav]);
        static::assertSame([null, 7, null, 9], [$fees->cmaSales, $fees->cmaServices, $fees->cciSales, $fees->cciServices]);
        static::assertSame(1234, $amounts->total);
        static::assertSame(600, $amounts->vocationalTraining);
        static::assertSame([5, 9], [$amounts->liberatory->cipav, $amounts->chamberFees->cciServices]);
    }
}
