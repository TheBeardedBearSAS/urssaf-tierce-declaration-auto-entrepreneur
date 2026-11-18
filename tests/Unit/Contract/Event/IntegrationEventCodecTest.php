<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Contract\Event;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountSynced;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEventCodec;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateCancelled;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRevoked;

final class IntegrationEventCodecTest extends TestCase
{
    #[DataProvider('provideEvents')]
    public function testAnEventIsDecodedAsEncoded(IntegrationEvent $event): void
    {
        static::assertEquals($event, IntegrationEventCodec::decode(IntegrationEventCodec::encode($event)));
        static::assertContains($event::class, IntegrationEventCodec::CLASSES);
    }

    /**
     * @return iterable<string, array{IntegrationEvent}>
     */
    public static function provideEvents(): iterable
    {
        $at = '2026-11-15T10:00:00Z';
        yield 'account synced' => [new AccountSynced('e', $at, 't', 'a', null, false, true, 'T', 'M')];
        yield 'account synced first read' => [new AccountSynced('e', $at, 't', 'a', null, null, false, null, 'T')];
        yield 'mandate notified' => [new MandateNotified('e', $at, 't', 'm', null, 'a', 'notified')];
        yield 'mandate cancelled' => [new MandateCancelled('e', $at, 't', 'm', null, null, 'recorded')];
        yield 'declaration declared' => [new DeclarationDeclared('e', $at, 't', 'd', null, 'a', 'M', 2026, 10, '12345678', 'id-1')];
        yield 'declaration declared without id' => [new DeclarationDeclared('e', $at, 't', 'd', null, null, 'T', 2026, 3, '12345678', null)];
        yield 'declaration rejected' => [new DeclarationRejected('e', $at, 't', 'd', null, 'a', 'M', 2026, 10, ['ERR_A'])];
        yield 'declaration outcome unknown' => [new DeclarationOutcomeUnknown('e', $at, 't', 'd', null, 'a', 'M', 2026, 10, 'stale_submission')];
        yield 'payment ordered' => [new PaymentOrdered('e', $at, 't', 'p', 'ref', 'a', 'M', 2026, 10, '12345678', 'pay-1', 'RUM-1')];
        yield 'payment ordered without id or rum' => [new PaymentOrdered('e', $at, 't', 'p', 'ref', null, 'M', 2026, 10, '12345678', null, null)];
        yield 'payment rejected' => [new PaymentRejected('e', $at, 't', 'p', 'ref', 'a', 'T', 2026, 3, ['ERR_A'])];
        yield 'payment outcome unknown' => [new PaymentOutcomeUnknown('e', $at, 't', 'p', 'ref', 'a', 'M', 2026, 10, 'may_have_reached_urssaf')];
        yield 'sepa mandate registered' => [new SepaMandateRegistered('e', $at, 't', null, null, 'RUM-1')];
        yield 'sepa mandate revoked' => [new SepaMandateRevoked('e', $at, 't', null, null, 'RUM-1')];
        yield 'account forgotten' => [new AccountForgotten('e', $at, 't', 'a', null, ['m1', 'm2'], ['d1'], ['p1'])];
        yield 'account forgotten without account' => [new AccountForgotten('e', $at, 't', null, null, [], [], [])];
    }

    public function testEveryClassOfTheCatalogueIsCoveredByTheProvider(): void
    {
        $classes = [];
        foreach (self::provideEvents() as [$event]) {
            $classes[] = $event::class;
        }

        static::assertEqualsCanonicalizing(IntegrationEventCodec::CLASSES, array_values(array_unique($classes)));
    }

    #[DataProvider('provideForeignData')]
    public function testOnlyEncodedIntegrationEventsAreDecoded(string $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The data is not an encoded integration event.');

        // unserialize() reports a notice for data that is not serialized at all: the codec turns it into the exception.
        set_error_handler(static fn(): bool => true, E_NOTICE);

        try {
            IntegrationEventCodec::decode($data);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideForeignData(): iterable
    {
        yield 'not serialized' => ['x'];
        yield 'text' => [serialize('text')];
        yield 'false' => ['b:0;'];
        yield 'foreign object' => [serialize(new stdClass())];
    }

    public function testNoOtherClassIsInstantiatedWhileDecoding(): void
    {
        try {
            IntegrationEventCodec::decode(serialize(new WakeUpRecorder()));
            static::fail('The data should be refused.');
        } catch (InvalidArgumentException $failure) {
            static::assertSame('The data is not an encoded integration event.', $failure->getMessage());
        }

        static::assertFalse(WakeUpRecorder::wokenUp());
    }
}
