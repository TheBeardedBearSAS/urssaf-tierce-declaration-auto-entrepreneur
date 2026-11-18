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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\MandateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateRecorder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;

/**
 * Records a mandate cancellation without any call (origin "recorded"). Idempotent: it cancels the latest local mandate of
 * the SIRET when it is active, or returns it when it is already cancelled. No call was made, so it never throws
 * "mandate.recording_failed" (ADR 0009).
 */
final readonly class RecordMandateCancellationHandler
{
    public function __construct(
        private MandateRepository $mandates,
        private MandateRecorder $recorder,
    ) {}

    /**
     * @throws RecordNotFound     "mandate.not_found" when the SIRET never had a local mandate (or it was erased meanwhile)
     * @throws InvariantViolation a conflict that persisted after every attempt: call it again, it is idempotent
     */
    public function __invoke(RecordMandateCancellation $command): MandateView
    {
        $known = $this->mandates->findBySiret($command->tenantId, $command->siret);
        $mandate = array_pop($known)
            ?? throw RecordNotFound::withIdentifier('mandate', '***');

        return MandateView::fromMandate(
            $this->recorder->recordObservedCancellation($mandate->id()),
        );
    }
}
