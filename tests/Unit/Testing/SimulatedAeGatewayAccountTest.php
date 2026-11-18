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

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;

/**
 * Methods 020, the account facts and the scripting of the mandate and the periodicity.
 */
final class SimulatedAeGatewayAccountTest extends TestCase
{
    public function testAnAccountWithoutMandateIsFoundWithItsFacts(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccountWith(
            SimulatorSamples::siret(),
            SimulatorSamples::birthDate(),
            Periodicity::Quarterly,
            CalendarDate::fromString('2020-01-15'),
            true,
        );

        $status = $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertFalse($status->mandatePresent());
        static::assertSame(Periodicity::Quarterly, $status->periodicity());
        static::assertSame('2020-01-15', $status->affiliationDate()?->toString());
        static::assertTrue($status->acreExemption());
        static::assertSame(['020'], $gateway->calls());
    }

    public function testTheShortFormKnowsAnAccountWithoutOptionalFacts(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());

        $status = $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertSame(Periodicity::Monthly, $status->periodicity());
        static::assertNull($status->affiliationDate());
        static::assertNull($status->acreExemption());
        static::assertSame(['020'], $gateway->calls());
    }

    public function testTheMandatePresentFlagIsPerTenant(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        static::assertTrue($gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret())->mandatePresent());
        static::assertFalse($gateway->findAccount(SimulatorSamples::otherTenant(), SimulatorSamples::siret())->mandatePresent());
        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::otherTenant()));
    }

    public function testAMandateReplacesAnyHolderAndCanBeWithdrawn(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::otherTenant());

        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
        static::assertTrue($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::otherTenant()));

        $gateway->withdrawMandate(SimulatorSamples::siret());

        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::otherTenant()));
        static::assertFalse($gateway->findAccount(SimulatorSamples::otherTenant(), SimulatorSamples::siret())->mandatePresent());
    }

    public function testThePeriodicityCanBeChanged(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());

        $gateway->setPeriodicity(SimulatorSamples::siret(), Periodicity::Quarterly);

        static::assertSame(
            Periodicity::Quarterly,
            $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret())->periodicity(),
        );
    }

    public function testAnUnknownSiretIsAnUnknownAccountOnEveryOperationUpTo060(): void
    {
        $gateway = SimulatorSamples::gateway();
        $operations = [Operation::FindAccount, Operation::NotifyMandate, Operation::CancelMandate, Operation::Estimate, Operation::Declare, Operation::Pay];

        foreach ($operations as $operation) {
            $refusal = SimulatorSamples::refusal(static fn(): mixed => SimulatorSamples::call($gateway, $operation));

            static::assertSame($operation, $refusal->operation());
            static::assertSame(400, $refusal->httpStatus());
            static::assertSame(RejectionKind::AccountUnknown, $refusal->kind());
            static::assertSame(['CPTE_INCONNU'], $refusal->errors()->codes());
            static::assertSame('Compte inconnu', $refusal->errors()->first()?->message());
        }
        static::assertSame(['020', '030', '031', '040', '050', '060'], $gateway->calls());
    }

    public function testTheScriptingMethodsRefuseAnUnknownSiret(): void
    {
        $gateway = SimulatorSamples::gateway();
        $failures = [
            SimulatorSamples::failure(static fn() => $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant())),
            SimulatorSamples::failure(static fn() => $gateway->withdrawMandate(SimulatorSamples::siret())),
            SimulatorSamples::failure(static fn() => $gateway->setPeriodicity(SimulatorSamples::siret(), Periodicity::Quarterly)),
            SimulatorSamples::failure(static fn() => $gateway->closePeriod(SimulatorSamples::siret(), SimulatorSamples::month())),
        ];

        foreach ($failures as $failure) {
            static::assertInstanceOf(LogicException::class, $failure);
            static::assertSame('The account is not known to the simulator.', $failure->getMessage());
        }
        static::assertFalse($gateway->isMandated(SimulatorSamples::siret(), SimulatorSamples::tenant()));
        static::assertSame([], $gateway->calls());
    }

    public function testAnAccountIsKnownOnlyOnce(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());

        $failure = SimulatorSamples::failure(
            static fn() => $gateway->knowAccountWith(SimulatorSamples::siret(), SimulatorSamples::birthDate(), Periodicity::Monthly, null, null),
        );

        static::assertInstanceOf(LogicException::class, $failure);
        static::assertSame('The account is already known to the simulator.', $failure->getMessage());
    }

    public function testTwoAccountsAreIndependent(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $gateway->knowAccount(SimulatorSamples::otherSiret(), SimulatorSamples::birthDate(), Periodicity::Quarterly);
        $gateway->mandate(SimulatorSamples::otherSiret(), SimulatorSamples::tenant());

        static::assertFalse($gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret())->mandatePresent());
        static::assertTrue($gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::otherSiret())->mandatePresent());
        static::assertSame(
            Periodicity::Monthly,
            $gateway->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret())->periodicity(),
        );
    }

    public function testTheDefaultClockIsTheSystemClock(): void
    {
        $gateway = new SimulatedAeGateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());

        $estimate = $gateway->estimateContributions(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::turnover());

        $expected = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->modify('first day of last month');
        static::assertSame($expected->format('Y-m'), \sprintf('%04d-%02d', $estimate->period()->year(), DeclarationPeriods::numberOf($estimate->period())));
    }
}
