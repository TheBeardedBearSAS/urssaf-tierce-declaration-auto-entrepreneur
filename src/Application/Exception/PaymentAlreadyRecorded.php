<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;

/**
 * Thrown when a tenant records again a payment reference with another payment content (ADR 0009, ADR 0011 item 3).
 * Nothing was sent. The SIRET, the amount and the bank data never appear in the message or the parameters (ADR 0010).
 *
 * Violation code: "payment_record.already_recorded".
 */
final class PaymentAlreadyRecorded extends DomainException
{
    public static function withReference(PaymentReference $reference, PaymentRecordId $existing): self
    {
        return new self(
            'The payment reference is already recorded with another payment content: use a new payment reference for another payment (ADR 0009).',
            'payment_record.already_recorded',
            ['payment_reference' => $reference->value(), 'payment_record_id' => $existing->value()],
        );
    }
}
