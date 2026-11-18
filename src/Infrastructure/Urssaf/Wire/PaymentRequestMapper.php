<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;

/**
 * Body of the payment order (060): `montant`, then `iban` and `rum` only when present, then `periode`. The IBAN is
 * personal data (ADR 0010).
 */
final class PaymentRequestMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function body(#[SensitiveParameter] PaymentOrder $order): array
    {
        $body = ['montant' => EurosEncoding::encode($order->amount())];

        $iban = $order->iban();
        if ($iban instanceof Iban) {
            $body['iban'] = $iban->value();
        }

        $rum = $order->rum();
        if ($rum instanceof Rum) {
            $body['rum'] = $rum->value();
        }

        $body['periode'] = PeriodEncoding::encode($order->period());

        return $body;
    }
}
