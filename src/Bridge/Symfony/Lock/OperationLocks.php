<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock;

use Symfony\Component\Lock\LockFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * Prevents overlapping runs of the same operation for the same scope (overlapping crons, a cron racing a Messenger
 * worker, two mandate commands of one auto-entrepreneur...) with a non-blocking Symfony Lock named
 * "urssaf_tdae.<operation>.<scope>" (ADR 0015 item 10, docs/workflow.md).
 *
 * Without a lock factory (symfony/lock not installed, or the "lock" option disabled) every operation just runs: the
 * domain claims (state transitions, conditional updates, optimistic concurrency) remain the last line of defence
 * against a double submission.
 *
 * The scope of an account is the tenant and the SHA-256 digest of the SIRET: the SIRET never reaches a lock store key
 * or a log line in clear. The digest is pseudonymous, not anonymous (14 digits are enumerable).
 */
final readonly class OperationLocks
{
    /**
     * The lock is released explicitly in the finally block of exclusively(), and only when this process acquired it: no
     * release when the lock object is destroyed.
     */
    private const AUTO_RELEASE = false;

    public const PREFIX = 'urssaf_tdae';

    /** The mandate operations (030, 031, import, cancellation record) and the erasure of one SIRET. */
    public const MANDATE = 'mandate';

    /** The 020 refresh of one SIRET. */
    public const ACCOUNT_SYNC = 'account.sync';

    /** The 020 refresh of the least recently synced accounts of one tenant. */
    public const ACCOUNTS_SYNC = 'accounts.sync';
    public const DECLARATIONS_RECONCILE = 'declarations.reconcile';
    public const PAYMENTS_RECONCILE = 'payments.reconcile';
    public const REQUEUE = 'requeue';
    public const OUTBOX_RELAY = 'outbox.relay';
    public const OUTBOX_PURGE = 'outbox.purge';

    /** Scope of the cross-tenant operations. */
    public const GLOBAL_SCOPE = 'all';

    private const DIGEST_LENGTH = 32;

    public function __construct(private ?LockFactory $factory, private float $ttl = 3600.0) {}

    /**
     * False when the lock is disabled or symfony/lock is missing: every operation then runs unlocked.
     */
    public function isEnabled(): bool
    {
        return $this->factory instanceof LockFactory;
    }

    /**
     * "<tenant>.<first 32 hex of the SHA-256 of the SIRET>": one key per tenant and SIRET, whatever the selector used
     * to reach the account and whether it is tracked.
     */
    public static function accountScope(TenantId $tenant, Siret $siret): string
    {
        return \sprintf('%s.%s', $tenant->value(), substr(hash('sha256', $siret->toString()), 0, self::DIGEST_LENGTH));
    }

    public static function tenantScope(TenantId $tenant): string
    {
        return $tenant->value();
    }

    public static function resource(string $operation, string $scope): string
    {
        return \sprintf('%s.%s.%s', self::PREFIX, $operation, $scope);
    }

    /**
     * Runs $action while holding the lock of the operation, or $busy (given the lock name) when another process holds it.
     *
     * @template T
     * @template B
     *
     * @param callable(): T       $action
     * @param callable(string): B $busy
     * @param-immediately-invoked-callable $action
     * @param-immediately-invoked-callable $busy
     *
     * @return T|B
     */
    public function exclusively(string $operation, string $scope, callable $action, callable $busy): mixed
    {
        if (!$this->factory instanceof LockFactory) {
            return $action();
        }
        $resource = self::resource($operation, $scope);
        $lock = $this->factory->createLock($resource, $this->ttl, self::AUTO_RELEASE);
        if (!$lock->acquire(false)) {
            return $busy($resource);
        }
        try {
            return $action();
        } finally {
            $lock->release();
        }
    }
}
