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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;

/**
 * Records a mandate without any call. Idempotent: a local active mandate is returned as is, and a given birth date is
 * stored on the tracked account. This is the call-free recovery after an unknown outcome of 030 that the AE space shows
 * registered, and after "mandate.recording_failed".
 *
 * Like the other mandate commands it reads again and retries (at most {@see MandateRecorder::MAX_ATTEMPTS} attempts) on a
 * conflict of the account or the mandate.
 */
final readonly class ImportMandateHandler
{
    public function __construct(
        private AeAccountRepository $accounts,
        private MandateRepository $mandates,
        private MandateRecorder $recorder,
    ) {}

    /**
     * @throws InvariantViolation "ae_account.concurrent_modification", "mandate.concurrent_modification" or
     *                            "mandate.duplicate_active" after every attempt (rolled back with a transactional
     *                            TransactionManager): call it again, it is idempotent; or a violation that is not a conflict
     */
    public function __invoke(ImportMandate $command): MandateView
    {
        $active = $this->mandates->findActive($command->tenantId, $command->siret);
        if (
            $active instanceof Mandate
            && (!$command->birthDate instanceof CalendarDate || !$this->accounts->findBySiret($command->tenantId, $command->siret) instanceof AeAccount)
        ) {
            return MandateView::fromMandate($active);
        }

        return MandateView::fromMandate($this->recorder->recordImport($command->tenantId, $command->siret, $command->birthDate));
    }
}
