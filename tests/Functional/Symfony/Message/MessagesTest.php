<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Message;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountMessage;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Message\SyncAccountsMessage;

/**
 * The messages of the bundle hold scalar identifiers only: no SIRET, no birth date, nothing personal (ADR 0010), and
 * they only ask for a read (020, ADR 0003).
 */
final class MessagesTest extends TestCase
{
    public function testTheBatchMessageHoldsTheTenantOnly(): void
    {
        $message = new SyncAccountsMessage('tenant-1');

        static::assertSame('tenant-1', $message->tenantId);
        static::assertSame(['tenantId' => 'string'], self::properties(SyncAccountsMessage::class));
    }

    public function testTheAccountMessageHoldsTheTenantAndTheAccountIdentifierOnly(): void
    {
        $message = new SyncAccountMessage('tenant-1', 'account-1');

        static::assertSame('tenant-1', $message->tenantId);
        static::assertSame('account-1', $message->accountId);
        static::assertSame(['tenantId' => 'string', 'accountId' => 'string'], self::properties(SyncAccountMessage::class));
    }

    public function testNoPropertyIsNamedAfterPersonalData(): void
    {
        foreach ([SyncAccountsMessage::class, SyncAccountMessage::class] as $class) {
            foreach (array_keys(self::properties($class)) as $name) {
                static::assertDoesNotMatchRegularExpression('/siret|birth|naissance|iban|bic|holder|address/i', $name, $class);
            }
        }
    }

    /**
     * @param class-string $class
     *
     * @return array<string, string> the type of each property by name
     */
    private static function properties(string $class): array
    {
        $properties = [];
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            static::assertTrue($property->isPublic());
            static::assertTrue($property->isReadOnly());
            $properties[$property->getName()] = self::typeName($property);
        }

        return $properties;
    }

    private static function typeName(ReflectionProperty $property): string
    {
        return (string) $property->getType();
    }
}
