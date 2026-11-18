<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;

/**
 * An unknown tenant fails before the limiter and before any HTTP call (ADR 0012).
 */
final class UrssafHttpGatewayCredentialsTest extends TestCase
{
    /**
     * @return iterable<string, array{Operation}>
     */
    public static function provideOneOperationPerRetryClass(): iterable
    {
        yield 'safe 020' => [Operation::FindAccount];
        yield 'unspecified write 050' => [Operation::Declare];
        yield 'never resent 060' => [Operation::Pay];
        yield 'unspecified write 071' => [Operation::RevokeSepaMandate];
    }

    #[DataProvider('provideOneOperationPerRetryClass')]
    public function testAnUnknownTenantIsRecordNotFoundAndNothingIsSent(Operation $operation): void
    {
        $stack = Stacks::sandbox();
        $gateway = Gateways::withCredentials($stack, new InMemoryCredentialsProvider([]));

        try {
            Gateways::call($gateway, $operation);
            static::fail('Expected a missing record.');
        } catch (RecordNotFound $exception) {
            static::assertSame('credentials', $exception->recordType());
            static::assertSame('tenant-1', $exception->identifier());
            static::assertSame('credentials.not_found', $exception->violationCode());
        }
        static::assertSame([], $stack->requests());
        static::assertSame([], $stack->rateLimiter->acquired());
    }
}
