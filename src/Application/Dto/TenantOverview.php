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

/**
 * Records of one tenant by state. Every state is listed, with 0 when there is no record (ADR 0003).
 */
final readonly class TenantOverview
{
    /**
     * @param array<string, int> $mandates                              mandates by MandateState value
     * @param array<string, int> $declarations                          declarations by DeclarationState value
     * @param array<string, int> $payments                              payments by PaymentState value
     * @param int                $declarationsAcceptedAfterSupersession superseded declarations that URSSAF accepted after all
     */
    public function __construct(
        public string $tenantId,
        public array $mandates,
        public array $declarations,
        public array $payments,
        public int $declarationsAcceptedAfterSupersession,
    ) {}

    /**
     * Records that need an operator: declarations and payments with an unknown outcome (ADR 0003), in error or with
     * invalid credentials, plus the declarations accepted after their supersession (ADR 0009).
     *
     * It deliberately counts more than DeclarationState::needsAttention() and PaymentState::needsAttention(), which flag
     * only the state that waits for an operator decision (outcome_unknown). An "error" record that remains is always
     * exhausted, because a retryable one is back to pending.
     */
    public function needingAttention(): int
    {
        return $this->declarations['outcome_unknown']
            + $this->declarations['error']
            + $this->declarations['invalid_credentials']
            + $this->payments['outcome_unknown']
            + $this->payments['error']
            + $this->payments['invalid_credentials']
            + $this->declarationsAcceptedAfterSupersession;
    }

    /**
     * @return array{tenant_id: string, mandates: array<string, int>, declarations: array<string, int>, payments: array<string, int>, declarations_accepted_after_supersession: int, needing_attention: int}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'mandates' => $this->mandates,
            'declarations' => $this->declarations,
            'payments' => $this->payments,
            'declarations_accepted_after_supersession' => $this->declarationsAcceptedAfterSupersession,
            'needing_attention' => $this->needingAttention(),
        ];
    }
}
