<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountErasure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\EstimateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command\CommandViews;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Contribution\ContributionSamples;

/**
 * The exact rows of each view: every cell is a string, an absent value is a dash (never a null-safe read of an absent
 * result, mandate or amount).
 */
final class CommandViewsRowsTest extends TestCase
{
    public function testAnAccountWithoutMandate(): void
    {
        $account = (new AccountWorld())->trackedAccount();

        static::assertSame(
            [
                ['Account id', 'account-1'],
                ['Tenant', 'tenant-1'],
                ['SIRET', AccountWorld::SIRET],
                ['Birth date stored', 'yes'],
                ['Mandate present', 'no'],
                ['Periodicity', 'M'],
                ['Affiliation date', '2025-03-01'],
                ['ACRE exemption', 'no'],
                ['Mandate state', '-'],
                ['Last synced at', '2026-11-01T08:00:00Z'],
            ],
            CommandViews::account(AccountView::fromAccount($account, null)),
        );
    }

    public function testAnAccountWithItsMandate(): void
    {
        $world = new AccountWorld();
        $account = $world->trackedAccount();
        $mandate = $world->activeMandateOf($account);

        $rows = CommandViews::account(AccountView::fromAccount($account, $mandate));

        static::assertSame(['Mandate state', 'notified'], $rows[8]);
    }

    public function testADeclarationWithoutResultShowsDashes(): void
    {
        $record = (new DeclarationWorld())->recordIn('record-1', DeclarationState::Pending, 0);

        static::assertSame(
            [
                ['Record id', 'record-1'],
                ['SIRET', '73282932000074'],
                ['Period', '2026-10'],
                ['State', 'pending'],
                ['Unknown outcome reason', '-'],
                ['Attempts', '0'],
                ['Error codes', '-'],
                ['Certificat', '-'],
                ['idDeclaration', '-'],
                ['Due date', '-'],
                ['Total (€)', '-'],
                ['Sales (€)', '-'],
                ['Furnished rental (€)', '-'],
                ['Commercial services (€)', '-'],
                ['Other services (€)', '-'],
                ['CIPAV (€)', '-'],
                ['Vocational training (€)', '-'],
                ['ACRE regularisation (€)', '-'],
                ['Deduction (€)', '-'],
                ['Liberatory sales (€)', '-'],
                ['Liberatory furnished rental (€)', '-'],
                ['Liberatory commercial services (€)', '-'],
                ['Liberatory other services (€)', '-'],
                ['Liberatory CIPAV (€)', '-'],
                ['CMA sales (€)', '-'],
                ['CMA services (€)', '-'],
                ['CCI sales (€)', '-'],
                ['CCI services (€)', '-'],
                ['Updated at', '2026-11-17T08:00:00Z'],
            ],
            CommandViews::declaration(DeclarationView::fromRecord($record)),
        );
    }

    public function testAnAcceptedDeclarationShowsItsResult(): void
    {
        $record = (new DeclarationWorld())->recordIn('record-1', DeclarationState::Declared, 3);

        $rows = CommandViews::declaration(DeclarationView::fromRecord($record));

        static::assertSame(['Attempts', '3'], $rows[5]);
        static::assertSame(['Certificat', '12345678'], $rows[7]);
        static::assertSame(['idDeclaration', 'W1dl9W2Oa4R6ab3UlQAqxF3'], $rows[8]);
        static::assertSame(['Due date', '2026-11-20'], $rows[9]);
        static::assertSame(['Total (€)', '100'], $rows[10]);
        static::assertSame(['Sales (€)', '60'], $rows[11]);
        static::assertSame(['Furnished rental (€)', '-'], $rows[12]);
        static::assertSame(['Commercial services (€)', '40'], $rows[13]);
    }

    public function testAnUnknownOutcomeShowsItsReasonAndItsErrorCodesOnly(): void
    {
        $record = (new DeclarationWorld())->recordIn('record-1', DeclarationState::Submitting, 1);
        $record->markOutcomeUnknown(OutcomeUnknownReason::StaleSubmission, [SubmissionError::of('E1', 'technical', 'Message', 'Description'), SubmissionError::of('E2', 'unknown', 'Other', 'Other')], Instant::fromIso8601(DeclarationWorld::NOW));

        $rows = CommandViews::declaration(DeclarationView::fromRecord($record));

        static::assertSame(['Unknown outcome reason', 'stale_submission'], $rows[4]);
        static::assertSame(['Error codes', 'E1 (technical), E2 (unknown)'], $rows[6]);
    }

    public function testAPaymentWithoutResultShowsDashes(): void
    {
        $record = (new PaymentWorld())->paymentIn('record-1', PaymentState::Pending, 0);

        static::assertSame(
            [
                ['Record id', 'record-1'],
                ['Reference', 'pay-1'],
                ['SIRET', '73282932000074'],
                ['Period', '2026-10'],
                ['Amount (€)', '1500'],
                ['RUM', PaymentWorld::RUM],
                ['State', 'pending'],
                ['Unknown outcome reason', '-'],
                ['Attempts', '0'],
                ['Error codes', '-'],
                ['Certificat', '-'],
                ['idTelepaiement', '-'],
                ['Due date', '-'],
                ['Updated at', '2026-11-17T08:00:00Z'],
            ],
            CommandViews::payment(PaymentView::fromRecord($record)),
        );
    }

    public function testAPaidPaymentShowsItsResult(): void
    {
        $record = (new PaymentWorld())->paymentIn('record-1', PaymentState::Paid, 2);

        $rows = CommandViews::payment(PaymentView::fromRecord($record));

        static::assertSame(['Attempts', '2'], $rows[8]);
        static::assertSame(['Certificat', '12345678'], $rows[10]);
        static::assertSame(['idTelepaiement', 'W1dl9W2Oa4R6ab3UlQAqxF2'], $rows[11]);
        static::assertSame(['Due date', '2026-11-20'], $rows[12]);
    }

    public function testAnEstimateShowsEveryAmount(): void
    {
        $view = EstimateView::fromEstimate(Estimate::of(ContributionSamples::amounts(1234), QuarterlyPeriod::of(2026, 4)));

        static::assertSame(
            [
                ['Period', '2026-T4'],
                ['Total (€)', '1234'],
                ['Sales (€)', '100'],
                ['Furnished rental (€)', '200'],
                ['Commercial services (€)', '300'],
                ['Other services (€)', '400'],
                ['CIPAV (€)', '500'],
                ['Vocational training (€)', '600'],
                ['ACRE regularisation (€)', '-10'],
                ['Deduction (€)', '-20'],
                ['Liberatory sales (€)', '1'],
                ['Liberatory furnished rental (€)', '2'],
                ['Liberatory commercial services (€)', '3'],
                ['Liberatory other services (€)', '4'],
                ['Liberatory CIPAV (€)', '5'],
                ['CMA sales (€)', '6'],
                ['CMA services (€)', '7'],
                ['CCI sales (€)', '8'],
                ['CCI services (€)', '9'],
            ],
            CommandViews::estimate($view),
        );
    }

    public function testAnErasureShowsCountsOnly(): void
    {
        static::assertSame(
            [
                ['Account id', 'account-1'],
                ['Mandates erased', '1'],
                ['Declarations erased', '2'],
                ['Payments erased', '3'],
            ],
            CommandViews::erasure(new AccountErasure('account-1', ['m1'], ['d1', 'd2'], ['p1', 'p2', 'p3'])),
        );
        static::assertSame(['Account id', '-'], CommandViews::erasure(new AccountErasure(null, [], [], []))[0]);
    }
}
