<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Port;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OperationRetryClass;

final class OperationTest extends TestCase
{
    /**
     * @return iterable<string, array{Operation, string, OperationRetryClass, string, bool}>
     */
    public static function provideOperations(): iterable
    {
        yield 'find account' => [Operation::FindAccount, '020', OperationRetryClass::Safe, 'find account', false];
        yield 'notify mandate' => [
            Operation::NotifyMandate,
            '030',
            OperationRetryClass::UnspecifiedWrite,
            'notify mandate',
            false,
        ];
        yield 'cancel mandate' => [
            Operation::CancelMandate,
            '031',
            OperationRetryClass::UnspecifiedWrite,
            'cancel mandate',
            false,
        ];
        yield 'estimate' => [Operation::Estimate, '040', OperationRetryClass::Safe, 'estimate contributions', false];
        yield 'declare' => [
            Operation::Declare,
            '050',
            OperationRetryClass::UnspecifiedWrite,
            'declare turnover',
            false,
        ];
        yield 'pay' => [Operation::Pay, '060', OperationRetryClass::NeverResent, 'pay contributions', false];
        yield 'register SEPA mandate' => [
            Operation::RegisterSepaMandate,
            '070',
            OperationRetryClass::UnspecifiedWrite,
            'register SEPA mandate',
            true,
        ];
        yield 'revoke SEPA mandate' => [
            Operation::RevokeSepaMandate,
            '071',
            OperationRetryClass::UnspecifiedWrite,
            'revoke SEPA mandate',
            true,
        ];
        yield 'list SEPA mandates' => [
            Operation::ListSepaMandates,
            '075',
            OperationRetryClass::Safe,
            'list SEPA mandates',
            true,
        ];
    }

    #[DataProvider('provideOperations')]
    public function testEveryOperation(
        Operation $operation,
        string $number,
        OperationRetryClass $retryClass,
        string $label,
        bool $isSepa,
    ): void {
        static::assertSame($number, $operation->value);
        static::assertSame($retryClass, $operation->retryClass());
        static::assertSame($label, $operation->label());
        static::assertSame($isSepa, $operation->isSepa());
    }

    public function testThereAreNineOperationsAndNoTokenCase(): void
    {
        static::assertCount(9, Operation::cases());
        static::assertSame(
            ['020', '030', '031', '040', '050', '060', '070', '071', '075'],
            array_map(static fn(Operation $operation): string => $operation->value, Operation::cases()),
        );
    }

    public function testRetryClassValues(): void
    {
        static::assertSame(
            ['safe', 'never_resent', 'unspecified_write'],
            array_map(static fn(OperationRetryClass $class): string => $class->value, OperationRetryClass::cases()),
        );
    }
}
