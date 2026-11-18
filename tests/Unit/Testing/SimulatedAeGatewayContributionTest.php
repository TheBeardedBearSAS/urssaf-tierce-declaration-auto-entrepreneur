<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;

/**
 * Methods 040, 050 and 060.
 *
 * @mago-expect lint:too-many-methods One test per rule of the simulator.
 */
final class SimulatedAeGatewayContributionTest extends TestCase
{
    public function testTheEstimateHasTheDefaultAmounts(): void
    {
        $gateway = self::mandated();

        $estimate = $gateway->estimateContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::turnover());

        static::assertSame(220, $estimate->amounts()->total()->toInt());
        static::assertSame(220, $estimate->amounts()->sales()?->toInt());
        static::assertNull($estimate->amounts()->furnishedRental());
        static::assertNull($estimate->amounts()->commercialServices());
        static::assertNull($estimate->amounts()->otherServices());
        static::assertNull($estimate->amounts()->cipav());
        static::assertNull($estimate->amounts()->vocationalTraining());
        static::assertNull($estimate->amounts()->acreRegularisation());
        static::assertNull($estimate->amounts()->deduction());
        static::assertSame(SimulatedAeGateway::DEFAULT_SALES_EUROS, $estimate->amounts()->total()->toInt());
    }

    /**
     * @return iterable<string, array{string, Periodicity, string}>
     */
    public static function provideEstimatePeriods(): iterable
    {
        yield 'monthly, mid-year' => ['2026-11-17T09:00:00Z', Periodicity::Monthly, '2026-10'];
        yield 'monthly, in February' => ['2026-02-10T09:00:00Z', Periodicity::Monthly, '2026-1'];
        yield 'monthly, in January' => ['2026-01-10T09:00:00Z', Periodicity::Monthly, '2025-12'];
        yield 'monthly, 23:30 UTC on the last day of the year is already January in Paris' => ['2026-12-31T23:30:00Z', Periodicity::Monthly, '2026-12'];
        yield 'monthly, 22:30 UTC on the last day of the year is still December in Paris' => ['2026-12-31T22:30:00Z', Periodicity::Monthly, '2026-11'];
        yield 'quarterly, in the second quarter' => ['2026-05-17T09:00:00Z', Periodicity::Quarterly, '2026-1'];
        yield 'quarterly, first day of the third quarter' => ['2026-07-01T09:00:00Z', Periodicity::Quarterly, '2026-2'];
        yield 'quarterly, last day of the third quarter' => ['2026-09-30T09:00:00Z', Periodicity::Quarterly, '2026-2'];
        yield 'quarterly, in the fourth quarter' => ['2026-11-17T09:00:00Z', Periodicity::Quarterly, '2026-3'];
        yield 'quarterly, in the first quarter' => ['2026-03-31T09:00:00Z', Periodicity::Quarterly, '2025-4'];
        yield 'quarterly, first day of the year' => ['2026-01-01T09:00:00Z', Periodicity::Quarterly, '2025-4'];
    }

    #[DataProvider('provideEstimatePeriods')]
    public function testTheDefaultEstimatePeriodIsThePeriodBeforeTodayInParis(string $now, Periodicity $periodicity, string $expected): void
    {
        $gateway = SimulatorSamples::gateway($now);
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate(), $periodicity);
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $period = $gateway->estimateContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::turnover())->period();

        static::assertSame($periodicity, $period->periodicity());
        static::assertSame($expected, $period->year() . '-' . DeclarationPeriods::numberOf($period));
    }

    public function testTheEstimatePeriodAndAmountsCanBeScripted(): void
    {
        $gateway = self::mandated();
        $amounts = ContributionAmounts::of(
            Euros::of(500),
            Euros::of(100),
            Euros::of(200),
            Euros::of(50),
            Euros::of(60),
            Euros::of(70),
            Euros::of(20),
            LiberatoryContributions::none(),
            ChamberFees::none(),
            Euros::of(-5),
            Euros::of(-10),
        );
        $gateway->setAmounts($amounts);
        $gateway->setEstimatePeriod(MonthlyPeriod::of(2025, 3));

        $estimate = $gateway->estimateContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::turnover());

        static::assertTrue($estimate->amounts()->equals($amounts));
        static::assertSame('2025-03', $estimate->period()->toString());
    }

    public function testAnEstimateNeedsTheMandateOfTheCallingTenant(): void
    {
        $gateway = self::mandated();

        $refusal = SimulatorSamples::refusal(
            static fn(): Estimate => $gateway->estimateContributions(SimulatorSamples::otherTenant(), SimulatorSamples::siret(), SimulatorSamples::turnover()),
        );

        static::assertSame(400, $refusal->httpStatus());
        static::assertSame(RejectionKind::MandateMissing, $refusal->kind());
        static::assertSame(['MDT_INCONNU'], $refusal->errors()->codes());
    }

    public function testADeclarationHasTheAmountsTheDueDateAndStableIdentifiers(): void
    {
        $gateway = self::mandated();
        $amounts = ContributionAmounts::of(Euros::of(300), null, null, null, null, null, null, LiberatoryContributions::none(), ChamberFees::none(), null, null);
        $gateway->setAmounts($amounts);

        $first = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declaration());
        $second = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declaration());
        $other = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declarationFor(SimulatorSamples::month(9)));

        static::assertSame('00000001', $first->certificate()->value());
        static::assertSame('SIMDECL0000000000000001', $first->declarationId());
        static::assertSame('2026-11-30', $first->dueDate()?->toString());
        static::assertTrue($first->amounts()?->equals($amounts));
        static::assertSame('00000001', $second->certificate()->value());
        static::assertSame('SIMDECL0000000000000002', $second->declarationId());
        static::assertSame('00000002', $other->certificate()->value());
        static::assertSame('SIMDECL0000000000000003', $other->declarationId());
        static::assertSame('2026-10-31', $other->dueDate()?->toString());
    }

    /**
     * @return iterable<string, array{Periodicity, int, int, string}>
     */
    public static function provideDueDates(): iterable
    {
        yield 'a month, the next month has 30 days' => [Periodicity::Monthly, 2026, 10, '2026-11-30'];
        yield 'December is due in January' => [Periodicity::Monthly, 2026, 12, '2027-01-31'];
        yield 'January of a leap year is due on 29 February' => [Periodicity::Monthly, 2024, 1, '2024-02-29'];
        yield 'January of a common year is due on 28 February' => [Periodicity::Monthly, 2026, 1, '2026-02-28'];
        yield 'the first quarter is due at the end of April' => [Periodicity::Quarterly, 2026, 1, '2026-04-30'];
        yield 'the second quarter is due at the end of July' => [Periodicity::Quarterly, 2026, 2, '2026-07-31'];
        yield 'the third quarter is due at the end of October' => [Periodicity::Quarterly, 2026, 3, '2026-10-31'];
        yield 'the fourth quarter is due at the end of January' => [Periodicity::Quarterly, 2026, 4, '2027-01-31'];
    }

    #[DataProvider('provideDueDates')]
    public function testTheDueDateIsTheLastDayOfTheMonthAfterThePeriod(Periodicity $periodicity, int $year, int $number, string $expected): void
    {
        $period = DeclarationPeriods::fromParts($periodicity, $year, $number);
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate(), $periodicity);
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $declared = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declarationFor($period));
        $paid = $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::orderFor($period));

        static::assertSame($expected, $declared->dueDate()?->toString());
        static::assertSame($expected, $paid->dueDate()?->toString());
    }

    public function testADeclarationNeedsTheMandate(): void
    {
        $gateway = self::mandated();

        $refusal = SimulatorSamples::refusal(
            static fn(): DeclarationResult => $gateway->declareTurnover(SimulatorSamples::otherTenant(), SimulatorSamples::siret(), SimulatorSamples::declaration()),
        );

        static::assertSame(RejectionKind::MandateMissing, $refusal->kind());
        static::assertSame(['MDT_INCONNU'], $refusal->errors()->codes());
    }

    public function testAPeriodOfTheOtherPeriodicityIsRefused(): void
    {
        $gateway = self::mandated();

        $refusal = SimulatorSamples::refusal(
            static fn(): DeclarationResult => $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declarationFor(SimulatorSamples::quarter())),
        );

        static::assertSame(RejectionKind::PeriodicityMismatch, $refusal->kind());
        static::assertSame(['PERIODICITE_INVALIDE'], $refusal->errors()->codes());
    }

    public function testAClosedPeriodIsRefusedAndTheOthersAreNot(): void
    {
        $gateway = self::mandated();
        $gateway->closePeriod(SimulatorSamples::siret(), SimulatorSamples::month(10));

        $refusal = SimulatorSamples::refusal(
            static fn(): DeclarationResult => $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declarationFor(SimulatorSamples::month(10))),
        );
        $open = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declarationFor(SimulatorSamples::month(11)));

        static::assertSame(RejectionKind::PeriodNotOpen, $refusal->kind());
        static::assertSame(['APPEL_INCONNU'], $refusal->errors()->codes());
        static::assertSame('00000001', $open->certificate()->value());
    }

    public function testTheSamePeriodNumberOfAnotherPeriodicityDoesNotShareState(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());
        $gateway->closePeriod(SimulatorSamples::siret(), SimulatorSamples::month(1));
        $gateway->setPeriodicity(SimulatorSamples::siret(), Periodicity::Quarterly);

        $declared = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declarationFor(SimulatorSamples::quarter(1)));

        static::assertSame('00000001', $declared->certificate()->value());
    }

    public function testAPaymentHasTheCertificateOfTheDeclarationOfThePeriod(): void
    {
        $gateway = self::mandated();
        $declared = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declaration());

        $first = $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::order());
        $second = $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::order());

        static::assertSame($declared->certificate()->value(), $first->certificate()->value());
        static::assertSame('SIMPAY00000000000000001', $first->paymentId());
        static::assertSame('SIMPAY00000000000000002', $second->paymentId());
        static::assertSame('2026-11-30', $first->dueDate()?->toString());
        static::assertSame('2026-10', $first->period()?->toString());
    }

    public function testAPaymentBeforeTheDeclarationGivesTheCertificateTheDeclarationWillKeep(): void
    {
        $gateway = self::mandated();

        $paid = $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::order());
        $declared = $gateway->declareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declaration());
        $nextPeriod = $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::orderFor(SimulatorSamples::month(11)));

        static::assertSame('00000001', $paid->certificate()->value());
        static::assertSame('00000001', $declared->certificate()->value());
        static::assertSame('00000002', $nextPeriod->certificate()->value());
    }

    public function testAPaymentNeedsTheMandate(): void
    {
        $gateway = self::mandated();

        $refusal = SimulatorSamples::refusal(
            static fn(): PaymentResult => $gateway->payContributions(SimulatorSamples::otherTenant(), SimulatorSamples::siret(), SimulatorSamples::order()),
        );

        static::assertSame(Operation::Pay, $refusal->operation());
        static::assertSame(RejectionKind::MandateMissing, $refusal->kind());
    }

    public function testASecondPaymentOfTheSamePeriodIsNotDetected(): void
    {
        $gateway = self::mandated();
        $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::order());

        $gateway->payContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::order());

        static::assertSame(['060', '060'], $gateway->calls());
    }

    private static function mandated(): SimulatedAeGateway
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        return $gateway;
    }
}
