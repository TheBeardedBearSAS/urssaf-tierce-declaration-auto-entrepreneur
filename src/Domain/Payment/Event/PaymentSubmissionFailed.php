<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event;

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The submission failed and is proven not sent (state error): it may be retried. Codes only: the URSSAF messages may
 * echo personal data (ADR 0010). No integration event (ADR 0014 item 4).
 */
final readonly class PaymentSubmissionFailed implements DomainEvent
{
    /**
     * @param list<string> $errorCodes
     */
    public function __construct(
        private PaymentRecordId $recordId,
        private TenantId $tenantId,
        private array $errorCodes,
        private Instant $occurredAt,
    ) {}

    public function recordId(): PaymentRecordId
    {
        return $this->recordId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return $this->errorCodes;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
