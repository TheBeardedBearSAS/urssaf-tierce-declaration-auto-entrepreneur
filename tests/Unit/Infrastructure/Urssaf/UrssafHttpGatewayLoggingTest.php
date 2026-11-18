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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Gateways;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stacks;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\WireSamples;
use Throwable;

/**
 * Privacy of the logs of the nine operations (ADR 0002, ADR 0010).
 */
final class UrssafHttpGatewayLoggingTest extends TestCase
{
    private const MANDATE_SIRET = '12345678901234';

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function provideOperations(): iterable
    {
        foreach (Operation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }

    #[DataProvider('provideOperations')]
    public function testTheLogsHoldNoPersonalDataNoSecretNoPathNoQuery(Operation $operation): void
    {
        $stack = Gateways::warm();
        Gateways::respondSuccess($stack, $operation);

        Gateways::call(Gateways::gateway($stack), $operation);

        $dump = $stack->logger->dump();
        static::assertNotSame([], $stack->logger->records());
        foreach ($this->forbidden() as $needle) {
            static::assertStringNotContainsString($needle, $dump);
        }
    }

    #[DataProvider('provideOperations')]
    public function testTheLogsOfAFailureHoldNoErrorMessageNorPersonalData(Operation $operation): void
    {
        $stack = Gateways::warm();
        $stack->respond(400, '{"code":"CPTE_INCONNU","message":"Compte inconnu","description":"Aucun compte Auto-Entrepreneur actif"}');

        try {
            Gateways::call(Gateways::gateway($stack), $operation);
            static::fail('Expected a rejection.');
        } catch (Throwable) {
            $dump = $stack->logger->dump();
            static::assertStringContainsString('"error_codes":["CPTE_INCONNU"]', $dump);
            static::assertStringNotContainsString('Compte inconnu', $dump);
            static::assertStringNotContainsString('Aucun compte', $dump);
            foreach ($this->forbidden() as $needle) {
                static::assertStringNotContainsString($needle, $dump);
            }
        }
    }

    #[DataProvider('provideOperations')]
    public function testTheShapeIsLoggedOnlyForTheEstimateTheDeclarationAndThePayment(Operation $operation): void
    {
        $stack = Gateways::warm();
        Gateways::respondSuccess($stack, $operation);

        Gateways::call(Gateways::gateway($stack), $operation);

        $shapes = array_values(array_filter(
            $stack->logger->records(),
            static fn(array $record): bool => $record['message'] === 'URSSAF response shape.',
        ));
        if (\in_array($operation, [Operation::Estimate, Operation::Declare, Operation::Pay], true)) {
            static::assertSame('debug', $shapes[0]['level']);
            static::assertSame(['operation' => $operation->value, 'shape' => 'direct'], $shapes[0]['context']);
            static::assertCount(1, $shapes);

            return;
        }

        static::assertSame([], $shapes);
    }

    #[DataProvider('provideOperations')]
    public function testTheSiretAppearsUnderItsOwnKeyOnlyWhenConfigured(Operation $operation): void
    {
        $stack = Gateways::warmWith(Stacks::configurationLoggingSiret());
        Gateways::respondSuccess($stack, $operation);

        Gateways::call(Gateways::gateway($stack), $operation);

        $siret = \in_array($operation, [Operation::NotifyMandate, Operation::CancelMandate], true) ? self::MANDATE_SIRET : WireSamples::siret()->toString();
        $records = $stack->logger->records();
        static::assertSame($siret, $records[0]['context']['siret']);
        foreach ($records as $record) {
            $keysHoldingTheSiret = array_keys(array_filter(
                $record['context'],
                static fn(mixed $value): bool => $value === $siret,
            ));
            static::assertSame([], array_values(array_diff($keysHoldingTheSiret, ['siret'])));
        }
        static::assertStringNotContainsString('1975-10-01', $stack->logger->dump());
    }

    /**
     * @return list<string>
     */
    private function forbidden(): array
    {
        return [
            '12345678912345',
            self::MANDATE_SIRET,
            '1975-10-01',
            '1975',
            WireSamples::RUM,
            WireSamples::PAYMENT_IBAN,
            'FR0012345123451234567891A12',
            'ABCDEFGHIJK',
            'LEFORESTIER',
            'boulevard',
            Stack::TOKEN_RESPONSE,
            'fictitious-access-token-0001',
            StaticCredentialsProvider::SHARED_VALUE,
            '?',
            '/sepa/mandats/',
            '/tiersdecl',
            'siret',
        ];
    }
}
