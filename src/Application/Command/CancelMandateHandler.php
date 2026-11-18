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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\MandateNotifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountReferences;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateRecorder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;

/**
 * Method 031 for a SIRET holding a local active mandate (record type "mandate": without one the command is refused with
 * no call; record it with ImportMandate first when it exists at URSSAF). Every failure of the call propagates and cancels
 * nothing, an {@see OutcomeUnknown} included (ADR 0003): an operator checks the AE space. If it shows the mandate
 * cancelled, RecordMandateCancellation records it (no call); only if the mandate is still active, call this use case
 * again.
 *
 * Only {@see MandateRecordingFailed} ("mandate.recording_failed") means that URSSAF accepted 031: recover without any
 * call (RecordMandateCancellation), never call this use case again. See {@see MandateRecorder} for the recording rules.
 */
final readonly class CancelMandateHandler
{
    public function __construct(
        private AeAccountRepository $accounts,
        private MandateRepository $mandates,
        private MandateNotifier $notifier,
        private MandateRecorder $recorder,
    ) {}

    /**
     * @throws RecordNotFound             "mandate.not_found" without an active local mandate
     * @throws ValidationFailed           "account_reference.birth_date_required": no birth date, the call is refused before sending
     * @throws OutcomeUnknown
     * @throws UrssafRequestRejected
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable
     * @throws RateLimitExceeded
     * @throws MandateRecordingFailed     "mandate.recording_failed": 031 was accepted but could not be recorded, use RecordMandateCancellation
     */
    public function __invoke(CancelMandate $command): MandateView
    {
        $mandate = $this->mandates->findActive($command->tenantId, $command->siret)
            ?? throw RecordNotFound::withIdentifier('mandate', '***');

        $reference = AccountReferences::resolve(
            $command->siret,
            $command->birthDate,
            $this->accounts->findBySiret($command->tenantId, $command->siret),
        );
        $this->notifier->cancelMandate($command->tenantId, $reference);

        return MandateView::fromMandate(
            $this->recorder->recordCancellation($command->tenantId, $command->siret, $mandate->id(), $command->birthDate),
        );
    }
}
