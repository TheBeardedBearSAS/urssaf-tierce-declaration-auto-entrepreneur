<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Contract;

use DateTimeImmutable;
use DateTimeZone;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\AccountHolder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Bic;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandateAddress;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Fictitious data. The bank data are the examples printed in the official documentation (docs/api-reference.md §2.7),
 * not a real person's. Anything about the sandbox account (SIRET, birth date) comes from the environment only.
 */
final class SandboxData
{
    /** A well-formed SIRET that belongs to no account. */
    public const UNKNOWN_SIRET = '00000000000000';

    private const TIME_ZONE = 'Europe/Paris';
    private const MONTHS_PER_QUARTER = 3;
    private const SANDBOX_TURNOVER_EUROS = 100;
    private const SANDBOX_PAYMENT_EUROS = 1;
    private const REVOCATION_DELAY = '+2 days';

    /**
     * The last complete period of the given periodicity (the one an auto-entrepreneur declares now).
     */
    public static function lastPeriod(Periodicity $periodicity): DeclarationPeriod
    {
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TIME_ZONE));
        if ($periodicity === Periodicity::Monthly) {
            $month = $now->modify('first day of last month');

            return DeclarationPeriods::fromParts($periodicity, (int) $month->format('Y'), (int) $month->format('n'));
        }

        $quarter = intdiv((int) $now->format('n') - 1, self::MONTHS_PER_QUARTER);
        $year = (int) $now->format('Y');

        return $quarter === 0
            ? DeclarationPeriods::fromParts($periodicity, $year - 1, 4)
            : DeclarationPeriods::fromParts($periodicity, $year, $quarter);
    }

    public static function turnover(): Turnover
    {
        return Turnover::of(Euros::of(self::SANDBOX_TURNOVER_EUROS), null, null, null, null);
    }

    public static function paymentAmount(): Euros
    {
        return Euros::of(self::SANDBOX_PAYMENT_EUROS);
    }

    public static function exampleIban(): Iban
    {
        return Iban::fromString('FR0012345123451234567891A12');
    }

    public static function newSepaMandate(): NewSepaMandate
    {
        return NewSepaMandate::of(
            Bic::fromString('ABCDEFGHIJK'),
            Iban::forSepaMandate('FR0012345123451234567891A12'),
            AccountHolder::fromString('Maxime LEFORESTIER'),
            SepaMandateAddress::of('13 boulevard vauban', null, '59000', 'LILLE', 'FRANCE'),
        );
    }

    /**
     * A revocation effective date accepted by 071 (at least tomorrow).
     */
    public static function revocationDate(): CalendarDate
    {
        return CalendarDate::fromDateTime(
            new DateTimeImmutable(self::REVOCATION_DELAY, new DateTimeZone(self::TIME_ZONE)),
            new DateTimeZone(self::TIME_ZONE),
        );
    }
}
