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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\ShownMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;

/**
 * Method 020 (a safe read), then the account is created with its snapshot. A SIRET that is already tracked returns its
 * account with no call (a birth date that differs from the stored one is stored). Every failure of the call propagates and
 * stores nothing. Two concurrent tracks of the same SIRET end with one account: the loser returns the winner's.
 */
final readonly class TrackAccountHandler
{
    public function __construct(
        private AeAccountRepository $accounts,
        private MandateRepository $mandates,
        private AccountReader $reader,
        private IdGenerator $ids,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    /**
     * @throws UrssafRequestRejected
     * @throws UrssafAuthenticationFailed
     * @throws UrssafUnavailable
     * @throws RateLimitExceeded
     * @throws InvariantViolation         "ae_account.concurrent_modification" while storing a birth date on a tracked account
     */
    public function __invoke(TrackAccount $command): AccountView
    {
        $existing = $this->accounts->findBySiret($command->tenantId, $command->siret);
        if ($existing instanceof AeAccount) {
            return $this->view($this->rememberBirthDate($existing, $command->birthDate));
        }

        return $this->view($this->create($command));
    }

    /**
     * The new account, or the one a concurrent track created first.
     *
     * @throws InvariantViolation
     */
    private function create(TrackAccount $command): AeAccount
    {
        $status = $this->reader->findAccount($command->tenantId, $command->siret);

        try {
            return $this->unitOfWork->run(function (RecordedEvents $events) use ($command, $status): AeAccount {
                $account = AeAccount::track(
                    AccountId::fromString($this->ids->generate()),
                    $command->tenantId,
                    $command->siret,
                    $status,
                    $command->birthDate,
                    Instant::fromDateTime($this->clock->now()),
                );
                $this->accounts->save($account);
                $events->record(...$account->releaseEvents());

                return $account;
            });
        } catch (InvariantViolation $violation) {
            $winner = $violation->violationCode() === AeAccountRepository::DUPLICATE_SIRET
                ? $this->accounts->findBySiret($command->tenantId, $command->siret)
                : null;

            return $winner ?? throw $violation;
        }
    }

    private function rememberBirthDate(AeAccount $account, #[SensitiveParameter] ?CalendarDate $birthDate): AeAccount
    {
        if (!$birthDate instanceof CalendarDate || $account->birthDate()?->equals($birthDate) === true) {
            return $account;
        }

        return $this->unitOfWork->run(function (RecordedEvents $events) use ($account, $birthDate): AeAccount {
            $account->rememberBirthDate($birthDate, Instant::fromDateTime($this->clock->now()));
            $this->accounts->save($account);
            $events->record(...$account->releaseEvents());

            return $account;
        });
    }

    private function view(AeAccount $account): AccountView
    {
        return AccountView::fromAccount($account, ShownMandate::of($this->mandates, $account->tenantId(), $account->siret()));
    }
}
