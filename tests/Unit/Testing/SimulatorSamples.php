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

use Closure;
use PHPUnit\Framework\Assert;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;
use Throwable;

/**
 * Fictitious values shared by the simulator tests and the standalone tests (the api-reference section 2 examples).
 *
 * @mago-expect lint:too-many-methods One sample per domain object the tests need.
 */
final class SimulatorSamples
{
    public const SIRET = '73282932000074';
    public const OTHER_SIRET = '12345678901234';
    public const BIRTH_DATE = '1975-02-23';
    public const NOW = '2026-11-17T09:00:00Z';
    public const IBAN = SepaSamples::IBAN;

    public static function tenant(): TenantId
    {
        return TenantId::fromString('tenant-1');
    }

    public static function otherTenant(): TenantId
    {
        return TenantId::fromString('tenant-2');
    }

    public static function siret(): Siret
    {
        return Siret::fromString(self::SIRET);
    }

    public static function otherSiret(): Siret
    {
        return Siret::fromString(self::OTHER_SIRET);
    }

    public static function birthDate(): CalendarDate
    {
        return CalendarDate::fromString(self::BIRTH_DATE);
    }

    public static function reference(): AccountReference
    {
        return AccountReference::of(self::siret(), self::birthDate());
    }

    public static function month(int $month = 10): DeclarationPeriod
    {
        return MonthlyPeriod::of(2026, $month);
    }

    public static function quarter(int $quarter = 3): DeclarationPeriod
    {
        return QuarterlyPeriod::of(2026, $quarter);
    }

    public static function turnover(): Turnover
    {
        return Turnover::of(Euros::of(1000), null, null, null, null);
    }

    public static function declaration(): TurnoverDeclaration
    {
        return self::declarationFor(self::month());
    }

    public static function declarationFor(DeclarationPeriod $period): TurnoverDeclaration
    {
        return TurnoverDeclaration::of($period, self::turnover());
    }

    public static function order(): PaymentOrder
    {
        return self::orderFor(self::month());
    }

    public static function orderFor(DeclarationPeriod $period): PaymentOrder
    {
        return PaymentOrder::of($period, Euros::of(220), Iban::fromString(self::IBAN), null);
    }

    public static function newMandate(): NewSepaMandate
    {
        return SepaSamples::newMandate();
    }

    public static function rum(string $value): Rum
    {
        return Rum::fromString($value);
    }

    /**
     * A simulator whose clock is the sample instant (17 November 2026, 10:00 in Paris).
     */
    public static function gateway(string $now = self::NOW): SimulatedAeGateway
    {
        return new SimulatedAeGateway(new FixedClock($now));
    }

    /**
     * The refusal thrown by the call.
     *
     * @param-immediately-invoked-callable $call
     * @param Closure(): mixed $call
     *
     * @throws Throwable the failures of the call that are not refusals
     */
    public static function refusal(Closure $call): UrssafRequestRejected
    {
        try {
            $call();
        } catch (UrssafRequestRejected $rejected) {
            return $rejected;
        }

        Assert::fail('The call was expected to be refused.');
    }

    /**
     * The failure thrown by the call.
     *
     * @param-immediately-invoked-callable $call
     * @param Closure(): mixed $call
     */
    public static function failure(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        Assert::fail('The call was expected to fail.');
    }

    /**
     * The call of the operation on the sample account, with the sample arguments (a 031 and a 030 carry the sample birth
     * date, a 071 a RUM nobody holds).
     *
     * @throws Throwable the failure of the simulator
     */
    public static function call(SimulatedAeGateway $gateway, Operation $operation): mixed
    {
        $tenant = self::tenant();
        $siret = self::siret();

        return match ($operation) {
            Operation::FindAccount => $gateway->findAccount($tenant, $siret),
            Operation::NotifyMandate => $gateway->notifyMandate($tenant, self::reference()),
            Operation::CancelMandate => $gateway->cancelMandate($tenant, self::reference()),
            Operation::Estimate => $gateway->estimateContributions($tenant, $siret, self::turnover()),
            Operation::Declare => $gateway->declareTurnover($tenant, $siret, self::declaration()),
            Operation::Pay => $gateway->payContributions($tenant, $siret, self::order()),
            Operation::RegisterSepaMandate => $gateway->registerSepaMandate($tenant, $siret, self::newMandate()),
            Operation::RevokeSepaMandate => $gateway->revokeSepaMandate($tenant, SepaRevocation::of($siret, self::rum('SIMRUM00000001'), null)),
            Operation::ListSepaMandates => $gateway->listSepaMandates($tenant, $siret),
        };
    }
}
