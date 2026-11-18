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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentResultView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentStatusView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\RequeueReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\TenantOverview;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

/**
 * The payment read models (primitives only), the selector, the submission, the requeue report and the overview.
 */
final class PaymentViewsTest extends TestCase
{
    public function testAPaidRecordIsDescribedWithPrimitivesOnly(): void
    {
        $world = new PaymentWorld();
        $record = $world->paymentIn('record-1', PaymentState::Paid, 2);

        $view = PaymentView::fromRecord($record);

        static::assertSame('record-1', $view->recordId);
        static::assertSame('tenant-1', $view->tenantId);
        static::assertSame('pay-1', $view->reference);
        static::assertSame('73282932000074', $view->siret);
        static::assertNull($view->accountId);
        static::assertSame('M', $view->periodicity);
        static::assertSame(2026, $view->periodYear);
        static::assertSame(10, $view->periodNumber);
        static::assertSame(1500, $view->amount);
        static::assertSame(PaymentWorld::RUM, $view->rum);
        static::assertNull($view->supersedes);
        static::assertNull($view->supersededBy);
        static::assertSame('2026-11-17T08:00:00Z', $view->createdAt);
        static::assertSame('paid', $view->status->state);
        static::assertSame(2, $view->status->attempts);
        static::assertSame('2026-11-17T08:00:00Z', $view->status->lastAttemptAt);
        static::assertSame([], $view->status->errors);
        static::assertNull($view->status->outcomeUnknownReason);
        static::assertSame('2026-11-17T08:00:00Z', $view->status->updatedAt);
        $result = $view->status->result;
        static::assertInstanceOf(PaymentResultView::class, $result);
        static::assertSame('12345678', $result->certificat);
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $result->idTelepaiement);
        static::assertSame('2026-11-20', $result->dueDate);
        static::assertSame('M', $result->periodicity);
        static::assertSame(2026, $result->periodYear);
        static::assertSame(10, $result->periodNumber);
        static::assertFalse($result->resolved);
        // The IBAN is not stored: no view holds it.
        static::assertStringNotContainsString(PaymentWorld::IBAN, serialize($view));
    }

    public function testAQuarterlyRecordAndItsLinksAreDescribed(): void
    {
        $at = Instant::fromIso8601(PaymentWorld::RECORDED_AT);
        $order = PaymentWorld::order();
        $rejected = PaymentRecord::record(
            PaymentRecordId::fromString('old'),
            PaymentWorld::tenant(),
            PaymentWorld::reference(),
            PaymentWorld::siret(),
            PaymentOrder::of(QuarterlyPeriod::of(2026, 3), $order->amount(), null, null),
            AccountId::fromString('account-1'),
            $at,
        );
        $rejected->startSubmission($at);
        $rejected->markRejected([SubmissionError::of('ER_API_060_0070', 'invalid_bank_details', 'Message', 'Description')], $at);
        $new = $rejected->supersede(PaymentRecordId::fromString('new'), PaymentOrder::of(QuarterlyPeriod::of(2026, 3), $order->amount(), null, null), $at);

        $old = PaymentView::fromRecord($rejected);
        $next = PaymentView::fromRecord($new);

        static::assertSame('T', $old->periodicity);
        static::assertSame(3, $old->periodNumber);
        static::assertSame('account-1', $old->accountId);
        static::assertNull($old->rum);
        static::assertSame('new', $old->supersededBy);
        static::assertSame('old', $next->supersedes);
        static::assertNull($next->supersededBy);
        static::assertSame('rejected', $old->status->state);
        static::assertCount(1, $old->status->errors);
        static::assertSame('ER_API_060_0070', $old->status->errors[0]->code);
        static::assertSame('invalid_bank_details', $old->status->errors[0]->kind);
        static::assertSame('Message', $old->status->errors[0]->message);
        static::assertSame('Description', $old->status->errors[0]->description);
    }

    public function testAnUnknownOutcomeCarriesItsReason(): void
    {
        $world = new PaymentWorld();
        $record = $world->paymentIn('record-1', PaymentState::OutcomeUnknown);

        $status = PaymentStatusView::fromStatus($record->status());

        static::assertSame('outcome_unknown', $status->state);
        static::assertSame(OutcomeUnknownReason::MayHaveReachedUrssaf->value, $status->outcomeUnknownReason);
        static::assertNull($status->result);
    }

    public function testAResolutionHasNoPeriodNorDueDate(): void
    {
        $view = PaymentResultView::fromResult(PaymentResult::resolved(Certificate::fromString('87654321')));

        static::assertSame('87654321', $view->certificat);
        static::assertNull($view->idTelepaiement);
        static::assertNull($view->dueDate);
        static::assertNull($view->periodicity);
        static::assertNull($view->periodYear);
        static::assertNull($view->periodNumber);
        static::assertTrue($view->resolved);
    }

    public function testTheDebugOutputKeepsTheLastFourDigitsOfTheSiretOnly(): void
    {
        $world = new PaymentWorld();
        $view = PaymentView::fromRecord($world->paymentIn('record-1', PaymentState::Pending, 0));

        $debug = $view->__debugInfo();

        static::assertSame('***0074', $debug['siret']);
        static::assertSame('record-1', $debug['recordId']);
        static::assertSame('tenant-1', $debug['tenantId']);
        static::assertSame('pay-1', $debug['reference']);
        static::assertSame(1500, $debug['amount']);
        static::assertSame(
            ['recordId', 'tenantId', 'reference', 'siret', 'accountId', 'periodicity', 'periodYear', 'periodNumber', 'amount', 'rum', 'supersedes', 'supersededBy', 'status', 'createdAt'],
            array_keys($debug),
        );
        static::assertStringNotContainsString('73282932000074', print_r($view, true));
        static::assertStringNotContainsString('73282932000074', var_export($debug, true));
    }

    public function testASubmissionPairsTheViewWithTheOutcome(): void
    {
        $world = new PaymentWorld();
        $view = PaymentView::fromRecord($world->paymentIn('record-1', PaymentState::Paid));

        $submission = new PaymentSubmission($view, ItemOutcome::Paid);

        static::assertSame($view, $submission->payment);
        static::assertSame(ItemOutcome::Paid, $submission->outcome);
    }

    public function testTheSelectorDesignatesARecordIdOrAReference(): void
    {
        $id = PaymentRecordId::fromString('record-1');
        $reference = PaymentWorld::reference();

        static::assertSame($id, PaymentSelector::byRecordId($id)->reference);
        static::assertSame($reference, PaymentSelector::byReference($reference)->reference);
    }

    public function testTheRequeueReportCountsEachKind(): void
    {
        $report = new RequeueReport(3, 5);

        static::assertSame(3, $report->declarations);
        static::assertSame(5, $report->payments);
    }

    public function testTheOverviewCountsTheRecordsThatNeedAnOperator(): void
    {
        $overview = new TenantOverview(
            'tenant-1',
            ['notified' => 4, 'cancelled' => 1],
            ['pending' => 9, 'submitting' => 9, 'declared' => 9, 'rejected' => 9, 'outcome_unknown' => 1, 'error' => 2, 'invalid_credentials' => 4],
            ['pending' => 9, 'submitting' => 9, 'paid' => 9, 'rejected' => 9, 'outcome_unknown' => 8, 'error' => 16, 'invalid_credentials' => 32],
            64,
        );

        static::assertSame(127, $overview->needingAttention());
        static::assertSame(
            [
                'tenant_id' => 'tenant-1',
                'mandates' => ['notified' => 4, 'cancelled' => 1],
                'declarations' => $overview->declarations,
                'payments' => $overview->payments,
                'declarations_accepted_after_supersession' => 64,
                'needing_attention' => 127,
            ],
            $overview->toArray(),
        );
    }
}
