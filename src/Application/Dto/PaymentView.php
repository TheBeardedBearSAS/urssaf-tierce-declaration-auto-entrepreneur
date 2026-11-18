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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;

/**
 * Read model of a payment record (primitives only). Instants are ISO 8601 UTC strings. The SIRET is in the view because
 * the operator needs it to check the auto-entrepreneur space (ADR 0003): debug output keeps its last four digits. There
 * is never an IBAN: it is not stored (ADR 0010). `accountId` is null when the account was not tracked when the payment
 * was recorded.
 */
final readonly class PaymentView
{
    /**
     * @mago-expect lint:excessive-parameter-list One parameter per field of the read model.
     */
    private function __construct(
        public string $recordId,
        public string $tenantId,
        public string $reference,
        public string $siret,
        public ?string $accountId,
        public string $periodicity,
        public int $periodYear,
        public int $periodNumber,
        public int $amount,
        public ?string $rum,
        public ?string $supersedes,
        public ?string $supersededBy,
        public PaymentStatusView $status,
        public string $createdAt,
    ) {}

    public static function fromRecord(PaymentRecord $record): self
    {
        return new self(
            $record->id()->value(),
            $record->tenantId()->value(),
            $record->reference()->value(),
            $record->siret()->toString(),
            $record->accountId()?->value(),
            $record->periodicity()->value,
            $record->period()->year(),
            DeclarationPeriods::numberOf($record->period()),
            $record->amount()->toInt(),
            $record->rum()?->value(),
            $record->supersedes()?->value(),
            $record->supersededBy()?->value(),
            PaymentStatusView::fromStatus($record->status()),
            $record->createdAt()->toIso8601(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'recordId' => $this->recordId,
            'tenantId' => $this->tenantId,
            'reference' => $this->reference,
            'siret' => '***' . substr($this->siret, -4),
            'accountId' => $this->accountId,
            'periodicity' => $this->periodicity,
            'periodYear' => $this->periodYear,
            'periodNumber' => $this->periodNumber,
            'amount' => $this->amount,
            'rum' => $this->rum,
            'supersedes' => $this->supersedes,
            'supersededBy' => $this->supersededBy,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
        ];
    }
}
