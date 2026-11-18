<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use Closure;
use LogicException;
use Override;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\AccountReader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\MandateNotifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use Throwable;

/**
 * Hand-written fake of the account-side gateway ports (020, 030, 031): each operation answers what the test queued (a
 * status, null for the void success of 030 / 031, or a throwable that is thrown), in order, and journals its call
 * ("call:030"). An unexpected call throws a LogicException.
 */
final class FakeAccountGateway implements AccountReader, MandateNotifier
{
    /** @var array<string, list<AccountStatus|Throwable|null>> */
    private array $queues = [];

    /** @var list<string> */
    private array $calls = [];

    /** @var list<AccountReference> */
    private array $references = [];

    /** @var list<Siret> */
    private array $sirets = [];

    /** @var (Closure(): void)|null another process writing while the next call is in flight */
    private ?Closure $intrusion = null;

    public function __construct(private readonly Journal $journal) {}

    /**
     * Runs $intrusion once, during the next call (after it was journaled, before it answers): another process changing
     * the stored aggregates while the HTTP call is in flight.
     *
     * @param Closure(): void $intrusion
     *
     * @param-immediately-invoked-callable $intrusion
     */
    public function duringNextCall(Closure $intrusion): void
    {
        $this->intrusion = $intrusion;
    }

    public function queue(Operation $operation, AccountStatus|Throwable|null ...$outcomes): void
    {
        $this->queues[$operation->value] = [...($this->queues[$operation->value] ?? []), ...array_values($outcomes)];
    }

    /**
     * @return list<string> the operation numbers called, in order
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<AccountReference> the references sent with 030 and 031
     */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * @return list<Siret> the SIRETs read with 020
     */
    public function sirets(): array
    {
        return $this->sirets;
    }

    #[Override]
    public function findAccount(TenantId $tenantId, Siret $siret): AccountStatus
    {
        $this->sirets[] = $siret;
        $outcome = $this->outcome(Operation::FindAccount);
        if (!$outcome instanceof AccountStatus) {
            throw new LogicException('Unexpected outcome queued for method 020.');
        }

        return $outcome;
    }

    #[Override]
    public function notifyMandate(TenantId $tenantId, #[SensitiveParameter] AccountReference $account): void
    {
        $this->references[] = $account;
        $this->outcome(Operation::NotifyMandate);
    }

    #[Override]
    public function cancelMandate(TenantId $tenantId, #[SensitiveParameter] AccountReference $account): void
    {
        $this->references[] = $account;
        $this->outcome(Operation::CancelMandate);
    }

    private function outcome(Operation $operation): AccountStatus|null
    {
        $this->calls[] = $operation->value;
        $this->journal->add('call:' . $operation->value);
        if ($this->intrusion instanceof Closure) {
            $intrusion = $this->intrusion;
            $this->intrusion = null;
            $intrusion();
        }
        if (($this->queues[$operation->value] ?? []) === []) {
            throw new LogicException(\sprintf('Unexpected call to method %s.', $operation->value));
        }

        $outcome = array_shift($this->queues[$operation->value]);
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
