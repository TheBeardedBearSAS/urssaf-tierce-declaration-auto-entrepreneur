<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Contract;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;

/**
 * A mandate put in place by a write test, to cancel when the test is done. A mandate that was already there is not
 * leased: it is never touched.
 */
final readonly class SandboxMandateLease
{
    private function __construct(
        private SandboxSession $session,
        private AccountReference $account,
    ) {}

    /**
     * Notifies the mandate when the account has none; null when it was already there. Skips the calling test when the
     * mandate cannot be notified.
     */
    public static function acquire(SandboxSession $session, AccountReference $account): ?self
    {
        $gateway = $session->sandbox->gateway;
        $tenant = $session->sandbox->tenantId;

        if ($gateway->findAccount($tenant, $account->siret())->mandatePresent()) {
            return null;
        }

        try {
            $gateway->notifyMandate($tenant, $account);
        } catch (UrssafRequestRejected $rejected) {
            $session->noteRejection('mandate-030', $rejected);
            TestCase::markTestSkipped('The sandbox account has no mandate and it cannot be notified (see the note).');
        }

        return new self($session, $account);
    }

    public function release(): void
    {
        $this->session->sandbox->gateway->cancelMandate($this->session->sandbox->tenantId, $this->account);
    }
}
