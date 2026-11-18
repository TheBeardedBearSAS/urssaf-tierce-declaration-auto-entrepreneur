<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event;

use DateTimeImmutable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The records of an auto-entrepreneur were erased from the local store (GDPR, ADR 0017). Nothing was sent to URSSAF.
 * Identifiers only: no SIRET, no birth date (ADR 0010). The account id is null when no account was tracked for the SIRET.
 */
final readonly class AccountForgotten implements DomainEvent
{
    /**
     * @param list<MandateId>           $mandateIds
     * @param list<DeclarationRecordId> $declarationRecordIds
     * @param list<PaymentRecordId>     $paymentRecordIds
     */
    public function __construct(
        private ?AccountId $accountId,
        private TenantId $tenantId,
        private array $mandateIds,
        private array $declarationRecordIds,
        private array $paymentRecordIds,
        private Instant $occurredAt,
    ) {}

    public function accountId(): ?AccountId
    {
        return $this->accountId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    /**
     * @return list<MandateId>
     */
    public function mandateIds(): array
    {
        return $this->mandateIds;
    }

    /**
     * @return list<DeclarationRecordId>
     */
    public function declarationRecordIds(): array
    {
        return $this->declarationRecordIds;
    }

    /**
     * @return list<PaymentRecordId>
     */
    public function paymentRecordIds(): array
    {
        return $this->paymentRecordIds;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt->toDateTimeImmutable();
    }
}
