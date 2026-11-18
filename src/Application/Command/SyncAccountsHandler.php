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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchItem;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\BatchReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountRefresher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;

/**
 * Polls method 020 for the least recently synced accounts of a tenant, never synced first, at most "limit" in total. Per
 * account (each one in its own transaction):
 * - answer applied: {@see ItemOutcome::Changed} when the tracked snapshot changed, {@see ItemOutcome::Unchanged} otherwise;
 * - request refused by URSSAF: {@see ItemOutcome::Failed} with the error codes, the account is untouched;
 * - temporary failure ({@see RetryableException}: 429, transport, 5xx, token, local rate limit): {@see ItemOutcome::RetryLater},
 *   the account is untouched and the run goes on (020 is a safe read);
 * - account changed by another process since it was read ("ae_account.concurrent_modification"): the answer is discarded,
 *   {@see ItemOutcome::RetryLater}, the account is untouched and the run goes on.
 * Credential failures ({@see UrssafAuthenticationFailed}) concern the whole tenant and stop the run: what was already
 * processed stays committed.
 */
final readonly class SyncAccountsHandler
{
    public function __construct(
        private AeAccountRepository $accounts,
        private AccountRefresher $refresher,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws UrssafAuthenticationFailed
     */
    public function __invoke(SyncAccounts $command): BatchReport
    {
        $accounts = $this->accounts->findLeastRecentlySynced($command->tenantId, $this->policy->pageSize($command->limit));

        return BatchReport::of(...array_map(fn(AeAccount $account): BatchItem => $this->sync($account), $accounts));
    }

    /**
     * @throws UrssafAuthenticationFailed
     * @throws InvariantViolation         a repository refusal other than a concurrent modification
     */
    private function sync(AeAccount $account): BatchItem
    {
        $subject = $account->id()->value();

        try {
            $changed = $this->refresher->refresh($account);
        } catch (UrssafRequestRejected $rejection) {
            return new BatchItem($subject, ItemOutcome::Failed, $rejection->errors()->codes());
        } catch (RetryableException) {
            return new BatchItem($subject, ItemOutcome::RetryLater);
        } catch (InvariantViolation $violation) {
            if ($violation->violationCode() !== AeAccountRepository::CONCURRENT_MODIFICATION) {
                throw $violation;
            }

            return new BatchItem($subject, ItemOutcome::RetryLater, [$violation->violationCode()]);
        }

        return new BatchItem($subject, $changed ? ItemOutcome::Changed : ItemOutcome::Unchanged);
    }
}
