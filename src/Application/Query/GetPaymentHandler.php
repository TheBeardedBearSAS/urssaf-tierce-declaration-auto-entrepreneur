<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;

final readonly class GetPaymentHandler
{
    public function __construct(
        private PaymentLocator $locator,
    ) {}

    /**
     * @throws RecordNotFound "payment_record.not_found"
     */
    public function __invoke(GetPayment $query): PaymentView
    {
        return PaymentView::fromRecord($this->locator->locate($query->tenantId, $query->payment));
    }
}
