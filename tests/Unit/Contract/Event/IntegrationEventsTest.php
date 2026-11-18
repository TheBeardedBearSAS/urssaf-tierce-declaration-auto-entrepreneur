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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\IntegrationEventCodec;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;

/**
 * The catalogue of ADR 0014: every type, its version and its exact properties.
 */
final class IntegrationEventsTest extends TestCase
{
    private const AT = CatalogueSamples::AT;

    /**
     * @param array<string, mixed> $expected every property, in constructor order
     */
    #[DataProvider('provideEvents')]
    public function testAnEventExposesItsTypeVersionAndProperties(IntegrationEvent $event, string $type, array $expected): void
    {
        static::assertSame($type, $event->type());
        static::assertSame($event::TYPE, $type);
        static::assertSame(1, $event->version());
        static::assertSame($expected, $event->toArray());
    }

    /**
     * @return iterable<string, array{IntegrationEvent, string, array<string, mixed>}>
     */
    public static function provideEvents(): iterable
    {
        return CatalogueSamples::all();
    }

    public function testEveryTypeIsUniquePrefixedAndTheCodecKnowsEveryFinalClassOfTheDirectory(): void
    {
        $types = [];
        foreach (self::provideEvents() as [$event]) {
            $types[$event::class] = $event->type();
        }

        static::assertCount(12, IntegrationEventCodec::CLASSES);
        static::assertCount(12, $types);
        static::assertCount(12, array_unique($types));
        foreach ($types as $type) {
            static::assertStringStartsWith('urssaf.tdae.', $type);
        }

        $declared = [];
        foreach (self::catalogueFiles() as $file) {
            $class = 'TheBeardedBear\\UrssafTierceDeclarationAutoEntrepreneur\\Contract\\Event\\' . basename($file, '.php');
            static::assertTrue(class_exists($class), $class);
            $reflection = new ReflectionClass($class);
            if ($reflection->isFinal() && $reflection->isSubclassOf(IntegrationEvent::class)) {
                $declared[] = $class;
            }
        }
        static::assertEqualsCanonicalizing(IntegrationEventCodec::CLASSES, $declared);
    }

    public function testEveryCatalogueClassHasAnEventInTheProvider(): void
    {
        $classes = [];
        foreach (self::provideEvents() as [$event]) {
            $classes[] = $event::class;
        }

        static::assertEqualsCanonicalizing(IntegrationEventCodec::CLASSES, array_values(array_unique($classes)));
    }

    public function testTheNullableAccountAggregateAndReferenceCases(): void
    {
        $declaration = new DeclarationRejected('e', self::AT, 't', 'd', null, null, 'M', 2026, 10, []);
        $payment = new PaymentRejected('e', self::AT, 't', 'p', 'ref', null, 'M', 2026, 10, []);
        $sepa = new SepaMandateRegistered('e', self::AT, 't', null, null, 'RUM-1');
        $forgotten = new AccountForgotten('e', self::AT, 't', null, null, [], [], []);

        static::assertNull($declaration->accountId);
        static::assertNull($declaration->externalReference);
        static::assertNull($payment->accountId);
        static::assertSame('ref', $payment->externalReference);
        static::assertNull($sepa->aggregateId);
        static::assertNull($sepa->externalReference);
        static::assertNull($forgotten->aggregateId);
    }

    /**
     * @return list<string>
     */
    private static function catalogueFiles(): array
    {
        $files = glob(__DIR__ . '/../../../../src/Contract/Event/*.php');

        return false === $files ? [] : $files;
    }
}
