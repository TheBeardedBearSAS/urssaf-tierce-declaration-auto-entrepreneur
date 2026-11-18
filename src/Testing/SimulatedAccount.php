<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * One auto-entrepreneur of the simulator: its identity, its periodicity, the tenant that holds its mandate, the periods
 * closed for declaration, the certificate of each period and its active SEPA mandates.
 *
 * Plain state holder of the simulator: the accessors are its whole job, hence the method count.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods
 */
final class SimulatedAccount
{
    private ?TenantId $holder = null;

    /** @var list<DeclarationPeriod> */
    private array $closedPeriods = [];

    /** @var array<string, string> certificate by period */
    private array $certificates = [];

    /** @var array<string, SepaMandate> by RUM */
    private array $sepaMandates = [];

    public function __construct(
        private readonly Siret $siret,
        private readonly CalendarDate $birthDate,
        private Periodicity $periodicity,
        private readonly ?CalendarDate $affiliationDate,
        private readonly ?bool $acreExemption,
    ) {}

    public function siret(): Siret
    {
        return $this->siret;
    }

    public function periodicity(): Periodicity
    {
        return $this->periodicity;
    }

    public function setPeriodicity(Periodicity $periodicity): void
    {
        $this->periodicity = $periodicity;
    }

    public function hasBirthDate(CalendarDate $birthDate): bool
    {
        return $this->birthDate->equals($birthDate);
    }

    public function holdBy(?TenantId $tenantId): void
    {
        $this->holder = $tenantId;
    }

    public function isMandatedBy(TenantId $tenantId): bool
    {
        return $this->holder instanceof TenantId && $this->holder->equals($tenantId);
    }

    public function isHeldByAnotherThan(TenantId $tenantId): bool
    {
        return $this->holder instanceof TenantId && !$this->holder->equals($tenantId);
    }

    public function status(TenantId $tenantId): AccountStatus
    {
        return AccountStatus::of(
            $this->isMandatedBy($tenantId),
            $this->periodicity,
            $this->affiliationDate,
            $this->acreExemption,
        );
    }

    public function closePeriod(DeclarationPeriod $period): void
    {
        $this->closedPeriods[] = $period;
    }

    public function isOpen(DeclarationPeriod $period): bool
    {
        foreach ($this->closedPeriods as $closed) {
            if ($closed->equals($period)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The certificate of the period: given once, then stable whatever the number of declarations (last wins).
     */
    public function certificateFor(DeclarationPeriod $period, SimulatedSequence $certificates): string
    {
        return $this->certificates[self::key($period)] ??= $certificates->next();
    }

    public function addSepaMandate(Rum $rum, SepaMandate $mandate): void
    {
        $this->sepaMandates[$rum->value()] = $mandate;
    }

    public function hasSepaMandateWithIban(string $iban): bool
    {
        foreach ($this->sepaMandates as $mandate) {
            if ($mandate->iban() === $iban) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool whether a mandate with this RUM was active and is now removed
     */
    public function removeSepaMandate(Rum $rum): bool
    {
        $known = \array_key_exists($rum->value(), $this->sepaMandates);
        unset($this->sepaMandates[$rum->value()]);

        return $known;
    }

    /**
     * @return list<SepaMandate>
     */
    public function sepaMandates(): array
    {
        return array_values($this->sepaMandates);
    }

    private static function key(DeclarationPeriod $period): string
    {
        return $period->toString();
    }
}
