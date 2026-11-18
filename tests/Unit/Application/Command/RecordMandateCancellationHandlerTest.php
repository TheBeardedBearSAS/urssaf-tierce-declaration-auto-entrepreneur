<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Command;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RecordMandateCancellation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RecordMandateCancellationHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;

final class RecordMandateCancellationHandlerTest extends TestCase
{
    public function testTheActiveMandateIsCancelledWithTheOriginRecordedAndWithoutAnyCall(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();

        $view = self::handler($world)(new RecordMandateCancellation(AccountWorld::tenant(), AccountWorld::siret()));

        static::assertSame('mandate-1', $view->mandateId);
        static::assertSame('cancelled', $view->state);
        static::assertSame('recorded', $view->cancellationOrigin);
        static::assertSame('2026-11-17T09:00:00Z', $view->cancelledAt);
        static::assertSame([], $world->gateway->calls());
        static::assertSame(['begin', 'save:mandate-1', 'append:urssaf.tdae.mandate.cancelled', 'commit', 'publish:MandateCancelled'], $world->journal->entries());
    }

    public function testACancelledMandateIsReturnedAsIs(): void
    {
        $world = new AccountWorld();
        $world->cancelledMandate();

        $view = self::handler($world)(new RecordMandateCancellation(AccountWorld::tenant(), AccountWorld::siret()));

        static::assertSame('cancelled', $view->cancellationOrigin);
        static::assertSame('2026-11-10T08:00:00Z', $view->cancelledAt);
        static::assertSame(['begin', 'append:', 'commit'], $world->journal->entries());
    }

    public function testTheLatestMandateIsTheOneRecorded(): void
    {
        $world = new AccountWorld();
        $world->cancelledMandate('mandate-1');
        $world->activeMandate('mandate-2');

        $view = self::handler($world)(new RecordMandateCancellation(AccountWorld::tenant(), AccountWorld::siret()));

        static::assertSame('mandate-2', $view->mandateId);
        static::assertSame('recorded', $view->cancellationOrigin);
        static::assertSame('cancelled', $world->mandates->get(MandateId::fromString('mandate-1'))->cancellationOrigin()?->value);
    }

    public function testWithoutAnyMandateTheSiretIsNotFound(): void
    {
        $world = new AccountWorld();
        $world->activeMandate('mandate-other', AccountWorld::OTHER_SIRET);

        try {
            self::handler($world)(new RecordMandateCancellation(AccountWorld::tenant(), AccountWorld::siret()));
            static::fail('The SIRET never had a mandate.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('mandate.not_found', $notFound->violationCode());
            static::assertSame('***', $notFound->identifier());
        }
    }

    public function testTheMandateOfAnotherTenantIsNotFound(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();

        $this->expectException(RecordNotFound::class);

        self::handler($world)(new RecordMandateCancellation(AccountWorld::tenant('tenant-2'), AccountWorld::siret()));
    }

    public function testAConflictIsRetriedAndTheLastOneIsRethrown(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();
        self::conflictEveryTime($world);

        try {
            self::handler($world)(new RecordMandateCancellation(AccountWorld::tenant(), AccountWorld::siret()));
            static::fail('Every attempt conflicts.');
        } catch (InvariantViolation $violation) {
            static::assertSame(MandateRepository::CONCURRENT_MODIFICATION, $violation->violationCode());
        }
        static::assertCount(3, array_keys($world->journal->entries(), 'rollback', true));
        static::assertTrue($world->mandates->get(MandateId::fromString('mandate-1'))->isActive());
    }

    public function testNoCallWasMadeSoItNeverReportsAnAcceptedCall(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();
        $failure = new RuntimeException('storage down');
        $handler = new RecordMandateCancellationHandler($world->mandates, $world->recorderWithFailingOutbox($failure));

        try {
            $handler(new RecordMandateCancellation(AccountWorld::tenant(), AccountWorld::siret()));
            static::fail('The storage failure must propagate as it is.');
        } catch (RuntimeException $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertTrue($world->mandates->get(MandateId::fromString('mandate-1'))->isActive());
    }

    public function testTheAccountIsNotNeeded(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();

        self::handler($world)(new RecordMandateCancellation(AccountWorld::tenant(), AccountWorld::siret()));

        static::assertSame('1975-02-23', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
        static::assertNotContains('save:account-1', $world->journal->entries());
    }

    private static function conflictEveryTime(AccountWorld $world): void
    {
        $world->beforeNextMandateSave(static function (InMemoryMandateRepository $inner) use ($world): void {
            $inner->save($inner->get(MandateId::fromString('mandate-1')));
            self::conflictEveryTime($world);
        });
    }

    private static function handler(AccountWorld $world): RecordMandateCancellationHandler
    {
        return new RecordMandateCancellationHandler($world->mandates, $world->recorder());
    }
}
