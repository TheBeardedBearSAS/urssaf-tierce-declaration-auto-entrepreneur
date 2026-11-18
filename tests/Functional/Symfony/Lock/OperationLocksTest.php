<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Lock;

use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HeldNonWaitingStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\RecordingStore;

/**
 * One test per rule of the operation locks (names, scopes, non-blocking acquisition, release), hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final class OperationLocksTest extends TestCase
{
    public function testTheResourceNameContainsTheOperationAndTheScope(): void
    {
        static::assertSame('urssaf_tdae.account.sync.tenant-1', OperationLocks::resource('account.sync', 'tenant-1'));
    }

    public function testTheOperationsAreNamedAfterTheCommandsTheyProtect(): void
    {
        $resources = array_map(
            static fn(string $operation): string => OperationLocks::resource($operation, OperationLocks::GLOBAL_SCOPE),
            [
                OperationLocks::MANDATE,
                OperationLocks::ACCOUNT_SYNC,
                OperationLocks::ACCOUNTS_SYNC,
                OperationLocks::DECLARATIONS_RECONCILE,
                OperationLocks::PAYMENTS_RECONCILE,
                OperationLocks::REQUEUE,
                OperationLocks::OUTBOX_RELAY,
                OperationLocks::OUTBOX_PURGE,
            ],
        );

        static::assertSame(
            [
                'urssaf_tdae.mandate.all',
                'urssaf_tdae.account.sync.all',
                'urssaf_tdae.accounts.sync.all',
                'urssaf_tdae.declarations.reconcile.all',
                'urssaf_tdae.payments.reconcile.all',
                'urssaf_tdae.requeue.all',
                'urssaf_tdae.outbox.relay.all',
                'urssaf_tdae.outbox.purge.all',
            ],
            $resources,
        );
    }

    public function testTheScopeOfAnAccountIsTheTenantAndTheDigestOfTheSiret(): void
    {
        $scope = OperationLocks::accountScope(TenantId::fromString('tenant-1'), Siret::fromString('12345678901234'));

        static::assertSame('tenant-1.' . substr(hash('sha256', '12345678901234'), 0, 32), $scope);
        static::assertStringNotContainsString('12345678901234', $scope);
        static::assertMatchesRegularExpression('/^tenant-1\.[0-9a-f]{32}$/D', $scope);
    }

    public function testTheScopeOfAccountsDependsOnTheTenantAndOnTheSiret(): void
    {
        $tenant = TenantId::fromString('tenant-1');
        $siret = Siret::fromString('12345678901234');

        static::assertNotSame(
            OperationLocks::accountScope($tenant, $siret),
            OperationLocks::accountScope(TenantId::fromString('tenant-2'), $siret),
        );
        static::assertNotSame(
            OperationLocks::accountScope($tenant, $siret),
            OperationLocks::accountScope($tenant, Siret::fromString('12345678901235')),
        );
        static::assertSame(
            OperationLocks::accountScope($tenant, $siret),
            OperationLocks::accountScope($tenant, Siret::fromString('12345678901234')),
        );
    }

    public function testTheScopeOfATenantIsItsIdentifier(): void
    {
        static::assertSame('tenant-1', OperationLocks::tenantScope(TenantId::fromString('tenant-1')));
    }

    public function testItIsEnabledOnlyWithAFactory(): void
    {
        static::assertFalse((new OperationLocks(null))->isEnabled());
        static::assertTrue((new OperationLocks(new LockFactory(new InMemoryStore())))->isEnabled());
    }

    public function testItRunsTheActionWithoutFactory(): void
    {
        $result = (new OperationLocks(null))->exclusively('op', 'tenant-1', static fn(): string => 'ran', static fn(): string => 'busy');

        static::assertSame('ran', $result);
    }

    public function testItRunsTheActionAndReleasesTheLock(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $locks = new OperationLocks($factory, 60.0);
        $held = null;

        $result = $locks->exclusively('op', 'tenant-1', static function () use ($factory, &$held): string {
            $held = !$factory->createLock(OperationLocks::resource('op', 'tenant-1'))->acquire();

            return 'ran';
        }, static fn(): string => 'busy');

        static::assertSame('ran', $result);
        static::assertTrue($held);
        static::assertTrue($factory->createLock(OperationLocks::resource('op', 'tenant-1'))->acquire());
    }

    public function testTheLockIsCreatedWithTheNameAndTheTtlOfTheConfiguration(): void
    {
        $store = new RecordingStore();

        (new OperationLocks(new LockFactory($store), 90.5))->exclusively('op', 'tenant-1', static fn(): string => 'ran', static fn(): string => 'busy');

        static::assertSame(['urssaf_tdae.op.tenant-1'], $store->saved());
        static::assertSame([['urssaf_tdae.op.tenant-1', 90.5]], $store->ttls());
    }

    public function testTheDefaultTtlIsOneHour(): void
    {
        $store = new RecordingStore();

        (new OperationLocks(new LockFactory($store)))->exclusively('op', 'tenant-1', static fn(): string => 'ran', static fn(): string => 'busy');

        static::assertSame([['urssaf_tdae.op.tenant-1', 3600.0]], $store->ttls());
    }

    public function testItNeverWaitsForTheLock(): void
    {
        // The store is held by another process and fails a blocking acquisition at once: the call returns "busy" only
        // because the acquisition is not blocking.
        $result = (new OperationLocks(new LockFactory(new HeldNonWaitingStore()), 90.5))->exclusively('op', 'tenant-1', static fn(): string => 'ran', static fn(): string => 'busy');

        static::assertSame('busy', $result);
    }

    public function testItReleasesTheLockAfterTheAction(): void
    {
        $store = new RecordingStore();

        $result = (new OperationLocks(new LockFactory($store)))->exclusively('op', 'tenant-1', static fn(): string => 'ran', static fn(): string => 'busy');

        static::assertSame('ran', $result);
        static::assertSame(1, $store->deleted());
    }

    public function testItNeverReleasesTheLockOfAnotherProcess(): void
    {
        $store = new RecordingStore();
        $other = (new LockFactory($store))->createLock(OperationLocks::resource('op', 'tenant-1'));
        static::assertTrue($other->acquire());

        (new OperationLocks(new LockFactory($store)))->exclusively('op', 'tenant-1', static fn(): string => 'ran', static fn(): string => 'busy');

        static::assertSame(0, $store->deleted());
        static::assertTrue($other->isAcquired());
    }

    public function testItReleasesTheLockWhenTheActionFails(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $locks = new OperationLocks($factory);

        try {
            $locks->exclusively('op', 'tenant-1', static function (): never {
                throw new LogicException('boom');
            }, static fn(): string => 'busy');
            static::fail('The failure must propagate.');
        } catch (LogicException) {
            static::assertTrue($factory->createLock(OperationLocks::resource('op', 'tenant-1'))->acquire());
        }
    }

    public function testItReportsTheLockNameWhenAnotherProcessHoldsIt(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $other = $factory->createLock(OperationLocks::resource('op', 'tenant-1'));
        static::assertTrue($other->acquire());
        $ran = false;

        $result = (new OperationLocks($factory))->exclusively('op', 'tenant-1', static function () use (&$ran): string {
            $ran = true;

            return 'ran';
        }, static fn(string $resource): string => 'busy:' . $resource);

        static::assertSame('busy:urssaf_tdae.op.tenant-1', $result);
        static::assertFalse($ran);
        static::assertTrue($other->isAcquired(), 'The lock of the other process is left untouched.');
    }

    public function testAnotherTenantIsNotBlocked(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $factory->createLock(OperationLocks::resource('op', 'tenant-1'))->acquire();

        $result = (new OperationLocks($factory))->exclusively('op', 'tenant-2', static fn(): string => 'ran', static fn(): string => 'busy');

        static::assertSame('ran', $result);
    }
}
