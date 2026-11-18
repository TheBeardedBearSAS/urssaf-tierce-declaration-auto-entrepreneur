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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountReferences;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateRecorder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;

/**
 * Method 030 for a SIRET. A local active mandate means there is nothing to send: it is returned without any call and
 * nothing is stored (idempotent). Every failure of the call propagates and records nothing, whatever the cause (ADR 0003,
 * ADR 0009):
 * - a rejection with {@see RejectionKind::MandateHeldElsewhere}: another platform holds the mandate; the host reports it
 *   and may import the mandate if it is in fact its own;
 * - an {@see OutcomeUnknown} (030 is never resent unless declared safe): an operator checks the AE space. If the mandate
 *   exists there, ImportMandate records it (no call); only if it does not, this use case may be called again;
 * - invalid credentials, temporary failures (the call provably did not reach URSSAF).
 *
 * Only {@see MandateRecordingFailed} ("mandate.recording_failed") means that URSSAF accepted 030: recover without any
 * call (ImportMandate), never call this use case again. Every other exception means that the call was not sent (a
 * validation failure, {@see UrssafRequestRejected}, {@see UrssafAuthenticationFailed}, {@see UrssafUnavailable},
 * {@see RateLimitExceeded}) or that its outcome is unknown ({@see OutcomeUnknown}, an unexpected response, any foreign
 * throwable): check the AE space before anything else.
 *
 * Once URSSAF accepted 030 the mandate is recorded without another call, whatever the other writers do: see
 * {@see MandateRecorder}. Mandate commands of one SIRET should still be serialized by the host (a lock per SIRET).
 */
final readonly class NotifyMandateHandler
{
    public function __construct(
        private AeAccountRepository $accounts,
        private MandateRepository $mandates,
        private MandateNotifier $notifier,
        private MandateRecorder $recorder,
    ) {}

    /**
     * @throws ValidationFailed           "account_reference.birth_date_required": no birth date, the call is refused before sending
     * @throws OutcomeUnknown
     * @throws UrssafRequestRejected
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable
     * @throws RateLimitExceeded
     * @throws MandateRecordingFailed     "mandate.recording_failed": 030 was accepted but could not be recorded, use ImportMandate
     */
    public function __invoke(NotifyMandate $command): MandateView
    {
        $active = $this->mandates->findActive($command->tenantId, $command->siret);
        if ($active instanceof Mandate) {
            return MandateView::fromMandate($active);
        }

        $reference = AccountReferences::resolve(
            $command->siret,
            $command->birthDate,
            $this->accounts->findBySiret($command->tenantId, $command->siret),
        );
        $this->notifier->notifyMandate($command->tenantId, $reference);

        return MandateView::fromMandate($this->recorder->recordNotification($command->tenantId, $command->siret, $command->birthDate));
    }
}
