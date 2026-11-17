<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;

/**
 * SEPA mandate as URSSAF answers it (methods 070 and 075, `MandatSepa`). Read model: the response is kept as
 * received, the IBAN of a 075 answer is anonymised and therefore a plain string. Personal data (ADR 0010 item 4):
 * IBAN and holder are masked entirely when dumped.
 *
 * The public constructor is the factory (more than 5 parts).
 */
final readonly class SepaMandate
{
    public function __construct(
        private ?Rum $rum,
        private ?string $bankName,
        #[SensitiveParameter]
        private ?string $iban,
        #[SensitiveParameter]
        private ?string $accountHolder,
        private ?Siret $siret,
        private ?Creditor $creditor,
    ) {}

    public function rum(): ?Rum
    {
        return $this->rum;
    }

    public function bankName(): ?string
    {
        return $this->bankName;
    }

    public function iban(): ?string
    {
        return $this->iban;
    }

    public function accountHolder(): ?string
    {
        return $this->accountHolder;
    }

    public function siret(): ?Siret
    {
        return $this->siret;
    }

    public function creditor(): ?Creditor
    {
        return $this->creditor;
    }

    /**
     * @return array{rum: Rum|null, bankName: string|null, iban: string|null, accountHolder: string|null, siret: Siret|null, creditor: Creditor|null}
     */
    public function __debugInfo(): array
    {
        return [
            'rum' => $this->rum,
            'bankName' => $this->bankName,
            'iban' => $this->iban === null ? null : '***',
            'accountHolder' => $this->accountHolder === null ? null : '***',
            'siret' => $this->siret,
            'creditor' => $this->creditor,
        ];
    }
}
