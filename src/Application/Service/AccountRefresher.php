<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Reads the 020 snapshot of a tracked account and stores it on the account, in one transaction (ADR 0009). The call is
 * made outside any transaction. The snapshot never creates or cancels a local mandate.
 */
final readonly class AccountRefresher
{
    public function __construct(
        private AccountReader $reader,
        private AeAccountRepository $accounts,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    /**
     * True when the tracked snapshot changed (an event was recorded).
     *
     * @throws UrssafRequestRejected
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable
     * @throws RateLimitExceeded
     * @throws InvariantViolation         "ae_account.concurrent_modification": the account changed since it was read, nothing was saved
     */
    public function refresh(AeAccount $account): bool
    {
        return $this->apply($account, $this->reader->findAccount($account->tenantId(), $account->siret()));
    }

    /**
     * Stores an answer of 020 already read for this account. True when the tracked snapshot changed.
     */
    public function apply(AeAccount $account, AccountStatus $status): bool
    {
        return $this->unitOfWork->run(function (RecordedEvents $events) use ($account, $status): bool {
            $account->sync($status, Instant::fromDateTime($this->clock->now()));
            $this->accounts->save($account);
            $released = $account->releaseEvents();
            $events->record(...$released);

            return $released !== [];
        });
    }
}
