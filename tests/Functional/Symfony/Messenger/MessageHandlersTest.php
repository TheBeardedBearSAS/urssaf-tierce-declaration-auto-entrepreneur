<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler\SyncAccountMessageHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler\SyncAccountsMessageHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\RetryDelay;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;

/**
 * The two Messenger handlers over the test application (scripted URSSAF platform): what they run, under which lock, and
 * how failures reach Messenger. One test per scenario, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final class MessageHandlersTest extends TestCase
{
    private const MESSENGER = ['messenger' => ['enabled' => true]];
    private const OTHER_SIRET = '12345678901234';

    public function testTheTenantHandlerRefreshesTheAccountsOfTheTenantOnly(): void
    {
        // @mago-expect lint:no-literal-password The secret is an environment placeholder, set by the test kernel.
        $harness = self::boot(['credentials' => ['tenant-2' => ['client_id' => 'client-2', 'client_secret' => '%env(URSSAF_TEST_SECRET)%']]]);
        Scenario::trackedAccount($harness);
        Scenario::trackedAccount($harness, Scenario::TENANT, self::OTHER_SIRET);
        Scenario::trackedAccount($harness, 'tenant-2');
        static::assertSame(3, $harness->urssaf->calls(HttpScript::ROUTE_020));

        self::tenantHandler($harness)(new SyncAccountsMessage('tenant-1'));

        static::assertSame(5, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheAccountHandlerRefreshesOneAccount(): void
    {
        $harness = self::boot();
        Scenario::trackedAccount($harness);
        $other = Scenario::trackedAccount($harness, Scenario::TENANT, self::OTHER_SIRET);

        self::accountHandler($harness)(new SyncAccountMessage('tenant-1', $other->accountId));

        static::assertSame(3, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheLocksAreReleasedAfterTheRun(): void
    {
        $harness = self::boot();
        $account = Scenario::trackedAccount($harness);

        self::tenantHandler($harness)(new SyncAccountsMessage('tenant-1'));
        self::accountHandler($harness)(new SyncAccountMessage('tenant-1', $account->accountId));

        $tenantLock = HeldLocks::hold($harness, OperationLocks::resource(OperationLocks::ACCOUNTS_SYNC, 'tenant-1'));
        $accountLock = HeldLocks::hold($harness, self::accountResource());
        static::assertTrue($tenantLock->isAcquired());
        static::assertTrue($accountLock->isAcquired());
    }

    public function testTheTenantHandlerAsksForARetryWhileTheTenantIsBeingRefreshed(): void
    {
        $harness = self::boot();
        Scenario::trackedAccount($harness);
        $resource = OperationLocks::resource(OperationLocks::ACCOUNTS_SYNC, 'tenant-1');
        $held = HeldLocks::hold($harness, $resource);

        try {
            self::tenantHandler($harness)(new SyncAccountsMessage('tenant-1'));
            static::fail('The handler must ask for a retry.');
        } catch (RecoverableMessageHandlingException $retry) {
            RetryDelay::assertCarries(30_000, $retry);
            static::assertStringContainsString(\sprintf('lock "%s" is held', $resource), $retry->getMessage());
        }

        static::assertTrue($held->isAcquired());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testTheAccountHandlerAsksForARetryWhileTheAccountIsBeingRefreshed(): void
    {
        $harness = self::boot();
        $account = Scenario::trackedAccount($harness);
        $held = HeldLocks::hold($harness, self::accountResource());

        try {
            self::accountHandler($harness)(new SyncAccountMessage('tenant-1', $account->accountId));
            static::fail('The handler must ask for a retry.');
        } catch (RecoverableMessageHandlingException $retry) {
            RetryDelay::assertCarries(30_000, $retry);
            static::assertStringContainsString(\sprintf('lock "%s" is held', self::accountResource()), $retry->getMessage());
            static::assertStringNotContainsString(Scenario::SIRET, $retry->getMessage());
        }

        static::assertTrue($held->isAcquired());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testATemporaryFailureOfUrssafIsRecoverableWithItsDelay(): void
    {
        $harness = self::boot();
        $account = Scenario::trackedAccount($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_020, 503, '', ['Retry-After' => '120']);

        try {
            self::accountHandler($harness)(new SyncAccountMessage('tenant-1', $account->accountId));
            static::fail('Expected a recoverable exception.');
        } catch (RecoverableMessageHandlingException $failure) {
            RetryDelay::assertCarries(120_000, $failure);
        }
    }

    public function testRefusedCredentialsAreUnrecoverable(): void
    {
        $harness = self::boot();
        Scenario::trackedAccount($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 401, '{"error":"invalid_client"}');
        $harness->clock->modify('+2 hours');

        $this->expectException(UnrecoverableMessageHandlingException::class);

        self::tenantHandler($harness)(new SyncAccountsMessage('tenant-1'));
    }

    public function testAnUnknownAccountAndInvalidIdentifiersAreUnrecoverable(): void
    {
        $harness = self::boot();
        $handlers = [
            static fn() => self::accountHandler($harness)(new SyncAccountMessage('tenant-1', 'account-9')),
            static fn() => self::accountHandler($harness)(new SyncAccountMessage(' ', 'account-9')),
            static fn() => self::accountHandler($harness)(new SyncAccountMessage('tenant-1', ' ')),
            static fn() => self::tenantHandler($harness)(new SyncAccountsMessage(' ')),
        ];

        foreach ($handlers as $handle) {
            try {
                $handle();
                static::fail('Expected an unrecoverable exception.');
            } catch (UnrecoverableMessageHandlingException $failure) {
                static::assertInstanceOf(UrssafException::class, $failure->getPrevious());
            }
        }

        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testNothingIsLockedWithoutALockFactory(): void
    {
        $harness = Harness::boot([...self::MESSENGER, 'lock' => ['enabled' => false]], true);
        $account = Scenario::trackedAccount($harness);

        self::accountHandler($harness)(new SyncAccountMessage('tenant-1', $account->accountId));

        static::assertFalse($harness->privateService(OperationLocks::class, OperationLocks::class)->isEnabled());
        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    private static function accountResource(): string
    {
        return OperationLocks::resource(OperationLocks::ACCOUNT_SYNC, OperationLocks::accountScope(TenantId::fromString('tenant-1'), Siret::fromString(Scenario::SIRET)));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function boot(array $config = []): Harness
    {
        return Harness::boot([...self::MESSENGER, ...$config], true, true);
    }

    private static function tenantHandler(Harness $harness): SyncAccountsMessageHandler
    {
        return $harness->privateService(SyncAccountsMessageHandler::class, SyncAccountsMessageHandler::class);
    }

    private static function accountHandler(Harness $harness): SyncAccountMessageHandler
    {
        return $harness->privateService(SyncAccountMessageHandler::class, SyncAccountMessageHandler::class);
    }
}
