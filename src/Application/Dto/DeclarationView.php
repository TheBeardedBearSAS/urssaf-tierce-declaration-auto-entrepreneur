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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;

/**
 * Read model of a declaration record: primitives only, never the declared amounts (ADR 0010 item 2). Instants are ISO
 * 8601 UTC strings. `accountId` is null when the SIRET was not tracked when the declaration was recorded.
 *
 * The SIRET is in the view because the operator needs it to check the auto-entrepreneur space (ADR 0003); debug output
 * keeps its last four digits only.

 */
final readonly class DeclarationView
{
    private function __construct(
        public string $recordId,
        public string $tenantId,
        public string $siret,
        public ?string $accountId,
        public string $periodicity,
        public int $periodYear,
        public int $periodNumber,
        public ?string $supersedes,
        public ?string $supersededBy,
        public bool $acceptedAfterSupersession,
        public DeclarationStatusView $status,
        public string $createdAt,
    ) {}

    public static function fromRecord(DeclarationRecord $record): self
    {
        return new self(
            $record->id()->value(),
            $record->tenantId()->value(),
            $record->siret()->toString(),
            $record->accountId()?->value(),
            $record->periodicity()->value,
            $record->period()->year(),
            DeclarationPeriods::numberOf($record->period()),
            $record->supersedes()?->value(),
            $record->supersededBy()?->value(),
            $record->isAcceptedAfterSupersession(),
            DeclarationStatusView::fromStatus($record->status()),
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
            'siret' => '***' . substr($this->siret, -4),
            'accountId' => $this->accountId,
            'periodicity' => $this->periodicity,
            'periodYear' => $this->periodYear,
            'periodNumber' => $this->periodNumber,
            'supersedes' => $this->supersedes,
            'supersededBy' => $this->supersededBy,
            'acceptedAfterSupersession' => $this->acceptedAfterSupersession,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
        ];
    }
}
