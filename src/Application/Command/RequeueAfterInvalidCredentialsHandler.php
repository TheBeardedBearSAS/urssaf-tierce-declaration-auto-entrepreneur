<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\RequeueReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Once the tenant credentials are fixed: moves up to "limit" declarations, then up to "limit" payments, from
 * invalid_credentials back to pending (one transaction). The host then declares or pays them again (it holds the
 * payload, and the IBAN, which is never stored). Run it again while a returned count reaches the limit. Nothing is sent
 * to URSSAF.
 *
 * A record another process changed since it was read ("declaration_record.concurrent_modification" or
 * "payment_record.concurrent_modification") makes the whole transaction fail and nothing is requeued: run it again, it
 * reads the records again.
 */
final readonly class RequeueAfterInvalidCredentialsHandler
{
    public function __construct(
        private DeclarationRecordRepository $declarations,
        private PaymentRecordRepository $payments,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws InvariantViolation "declaration_record.concurrent_modification" or "payment_record.concurrent_modification"
     *                            (nothing was requeued)
     */
    public function __invoke(RequeueAfterInvalidCredentials $command): RequeueReport
    {
        $limit = $this->policy->pageSize($command->limit);
        $declarations = $this->declarations->findByState($command->tenantId, DeclarationState::InvalidCredentials, $limit);
        $payments = $this->payments->findByState($command->tenantId, PaymentState::InvalidCredentials, $limit);

        return $this->unitOfWork->run(function (RecordedEvents $events) use ($declarations, $payments): RequeueReport {
            $now = Instant::fromDateTime($this->clock->now());
            $maxAttempts = $this->policy->maxSubmissionAttempts();
            foreach ($declarations as $declaration) {
                $declaration->retry($now, $maxAttempts);
                $this->declarations->save($declaration);
                $events->record(...$declaration->releaseEvents());
            }
            foreach ($payments as $payment) {
                $payment->retry($now, $maxAttempts);
                $this->payments->save($payment);
                $events->record(...$payment->releaseEvents());
            }

            return new RequeueReport(\count($declarations), \count($payments));
        });
    }
}
