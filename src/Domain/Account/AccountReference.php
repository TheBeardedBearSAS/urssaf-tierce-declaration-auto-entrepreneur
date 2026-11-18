<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * SIRET and birth date identifying an auto-entrepreneur for methods 030 and 031 (ADR 0008).
 *
 * Personal data (ADR 0010): masked when dumped.
 */
final readonly class AccountReference
{
    private function __construct(
        private Siret $siret,
        #[SensitiveParameter]
        private CalendarDate $birthDate,
    ) {}

    public static function of(Siret $siret, #[SensitiveParameter] CalendarDate $birthDate): self
    {
        return new self($siret, $birthDate);
    }

    public function siret(): Siret
    {
        return $this->siret;
    }

    public function birthDate(): CalendarDate
    {
        return $this->birthDate;
    }

    public function equals(self $other): bool
    {
        return $this->siret->equals($other->siret) && $this->birthDate->equals($other->birthDate);
    }

    /**
     * @return array{siret: string, birthDate: string}
     */
    public function __debugInfo(): array
    {
        return ['siret' => $this->siret->__debugInfo()['value'], 'birthDate' => '***'];
    }
}
