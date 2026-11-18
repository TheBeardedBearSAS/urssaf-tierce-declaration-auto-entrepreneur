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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentStatus;

/**
 * Read model of the status of a payment record. Instants are ISO 8601 UTC strings. The stored errors keep the URSSAF
 * message and description (the operator needs them, they may echo host values): never log them.
 */
final readonly class PaymentStatusView
{
    /**
     * @param list<SubmissionErrorView> $errors
     */
    private function __construct(
        public string $state,
        public int $attempts,
        public ?string $lastAttemptAt,
        public array $errors,
        public ?PaymentResultView $result,
        public ?string $outcomeUnknownReason,
        public string $updatedAt,
    ) {}

    public static function fromStatus(PaymentStatus $status): self
    {
        $result = $status->result();

        return new self(
            $status->state()->value,
            $status->attempts(),
            $status->lastAttemptAt()?->toIso8601(),
            SubmissionErrorView::fromErrors($status->errors()),
            $result instanceof PaymentResult ? PaymentResultView::fromResult($result) : null,
            $status->outcomeUnknownReason()?->value,
            $status->updatedAt()->toIso8601(),
        );
    }
}
