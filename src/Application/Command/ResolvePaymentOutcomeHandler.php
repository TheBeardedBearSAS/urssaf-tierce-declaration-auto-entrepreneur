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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Applies the operator's finding to a record in state outcome_unknown (ADR 0003). Nothing is sent to URSSAF. The
 * resolved PaymentOrdered event carries no idTelepaiement.
 */
final readonly class ResolvePaymentOutcomeHandler
{
    public function __construct(
        private PaymentLocator $locator,
        private PaymentRecordRepository $records,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    /**
     * @throws RecordNotFound         "payment_record.not_found"
     * @throws InvalidStateTransition the record is not in state outcome_unknown
     * @throws InvalidValue           "certificate.invalid_format" (a blank certificat included)
     * @throws InvariantViolation     "payment_record.concurrent_modification": the record changed since it was read,
     *                                nothing was saved (read it again and decide again)
     */
    public function __invoke(ResolvePaymentOutcome $command): PaymentView
    {
        $record = $this->locator->locate($command->tenantId, $command->payment);
        $this->unitOfWork->run(function (RecordedEvents $events) use ($command, $record): void {
            $this->resolve($record, $command->certificat, Instant::fromDateTime($this->clock->now()));
            $this->records->save($record);
            $events->record(...$record->releaseEvents());
        });

        return PaymentView::fromRecord($record);
    }

    /**
     * @throws InvalidValue
     * @throws InvalidStateTransition
     */
    private function resolve(PaymentRecord $record, ?string $certificat, Instant $now): void
    {
        if ($certificat === null) {
            $record->resolveAsNotReceived($now);

            return;
        }
        $record->resolveAsPaid(Certificate::fromString($certificat), $now);
    }
}
