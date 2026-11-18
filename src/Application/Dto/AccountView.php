<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Read model of an auto-entrepreneur account (primitives only). Dates are "yyyy-MM-dd", instants are ISO 8601 UTC strings.
 * The birth date is never exposed (ADR 0010): only whether one is stored. The SIRET is in the view for the host, and is
 * masked when the view is dumped.
 */
final readonly class AccountView
{
    private const VISIBLE_DIGITS = 4;

    private function __construct(
        public string $accountId,
        public string $tenantId,
        public string $siret,
        public bool $hasBirthDate,
        public bool $mandatePresent,
        public string $periodicity,
        public ?string $affiliationDate,
        public ?bool $acreExemption,
        public ?MandateView $mandate,
        public string $lastSyncedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromAccount(AeAccount $account, ?Mandate $mandate): self
    {
        $status = $account->status();

        return new self(
            $account->id()->value(),
            $account->tenantId()->value(),
            $account->siret()->toString(),
            $account->birthDate() instanceof CalendarDate,
            $status->mandatePresent(),
            $status->periodicity()->value,
            $status->affiliationDate()?->toString(),
            $status->acreExemption(),
            $mandate instanceof Mandate ? MandateView::fromMandate($mandate) : null,
            $account->lastSyncedAt()->toIso8601(),
            $account->createdAt()->toIso8601(),
            $account->updatedAt()->toIso8601(),
        );
    }

    /**
     * @return array<string, bool|string|MandateView|null>
     */
    public function __debugInfo(): array
    {
        return [
            'accountId' => $this->accountId,
            'tenantId' => $this->tenantId,
            'siret' => '***' . substr($this->siret, -self::VISIBLE_DIGITS),
            'hasBirthDate' => $this->hasBirthDate,
            'mandatePresent' => $this->mandatePresent,
            'periodicity' => $this->periodicity,
            'acreExemption' => $this->acreExemption,
            'mandate' => $this->mandate,
            'lastSyncedAt' => $this->lastSyncedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
