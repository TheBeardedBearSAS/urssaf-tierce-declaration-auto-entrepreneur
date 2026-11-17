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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * URSSAF accepted the payment order, or the operator found it (state paid). `paymentId` (idTelepaiement) is null
 * when absent or on an operator resolution; `rum` is the SEPA mandate reference of the order, if any (never the
 * IBAN). Identifiers, periods, states and codes only: never the SIRET, an amount, the birth date nor a URSSAF
 * message (ADR 0010 item 8, ADR 0014 item 3).
 */
final readonly class PaymentOrdered implements DomainEvent
{
    public function __construct(
        private PaymentRecordId $recordId,
        private TenantId $tenantId,
        private PaymentReference $reference,
        private ?AccountId $accountId,
        private DeclarationPeriod $period,
        private Certificate $certificate,
        private ?string $paymentId,
        private ?Rum $rum,
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

    public function reference(): PaymentReference
    {
        return $this->reference;
    }

    public function accountId(): ?AccountId
    {
        return $this->accountId;
    }

    public function period(): DeclarationPeriod
    {
        return $this->period;
    }

    public function certificate(): Certificate
    {
        return $this->certificate;
    }

    public function paymentId(): ?string
    {
        return $this->paymentId;
    }

    public function rum(): ?Rum
    {
        return $this->rum;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
