<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command;

use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * The locks of the commands (ADR 0015 item 10). One key per tenant and SIRET whatever the selector used to reach the
 * account (an account id is resolved to its SIRET first), so that two commands on one auto-entrepreneur exclude each
 * other; the SIRET never reaches the key in clear (OperationLocks::accountScope). A busy lock is not a failure: the
 * command prints that it skipped the operation and exits with 0.
 */
final readonly class CommandLocks
{
    public function __construct(private OperationLocks $locks, private GetAccountHandler $accounts) {}

    /**
     * Runs the action while holding the lock of the SIRET, or says that it is already running.
     *
     * @param callable(): int $action
     * @param-immediately-invoked-callable $action
     */
    public function forSiret(OutputInterface $output, TenantId $tenant, Siret $siret, string $operation, callable $action): int
    {
        return $this->locks->exclusively($operation, OperationLocks::accountScope($tenant, $siret), $action, self::busy($output));
    }

    /**
     * Same, for an account designated by its id: the lock is the one of its SIRET.
     *
     * @param callable(): int $action
     * @param-immediately-invoked-callable $action
     *
     * @throws RecordNotFound when the account is not tracked
     */
    public function forAccount(OutputInterface $output, TenantId $tenant, AccountId $accountId, string $operation, callable $action): int
    {
        $siret = Siret::fromString(($this->accounts)(new GetAccount($tenant, AccountSelector::byAccountId($accountId)))->siret);

        return $this->forSiret($output, $tenant, $siret, $operation, $action);
    }

    /**
     * Runs the action while holding the lock of the tenant.
     *
     * @param callable(): int $action
     * @param-immediately-invoked-callable $action
     */
    public function forTenant(OutputInterface $output, TenantId $tenant, string $operation, callable $action): int
    {
        return $this->locks->exclusively($operation, OperationLocks::tenantScope($tenant), $action, self::busy($output));
    }

    /**
     * Runs the action while holding the lock of a cross-tenant operation.
     *
     * @param callable(): int $action
     * @param-immediately-invoked-callable $action
     */
    public function globally(OutputInterface $output, string $operation, callable $action): int
    {
        return $this->locks->exclusively($operation, OperationLocks::GLOBAL_SCOPE, $action, self::busy($output));
    }

    /**
     * @return callable(string): int
     */
    private static function busy(OutputInterface $output): callable
    {
        return static fn(string $resource): int => CommandReports::renderBusy($output, $resource);
    }
}
