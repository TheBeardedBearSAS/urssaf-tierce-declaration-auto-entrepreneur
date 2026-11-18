<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\ListSepaMandates;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\ListSepaMandatesHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FakePaymentGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\Journal;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;
use Throwable;

/**
 * ListSepaMandates (075): a pass-through with no guard (ADR 0011).
 */
final class ListSepaMandatesHandlerTest extends TestCase
{
    public function testTheMandatesAreReturnedAsUrssafAnsweredThem(): void
    {
        $journal = new Journal();
        $gateway = new FakePaymentGateway($journal);
        $first = new SepaMandate(Rum::fromString('T1234567891234567891234568912345678'), 'Bank A', 'FR76***', 'HOLDER A', PaymentWorld::siret(), null);
        $second = new SepaMandate(Rum::fromString('T1234567891234567891234568912345679'), 'Bank B', 'FR76***', 'HOLDER B', PaymentWorld::siret(), null);
        $gateway->queue(Operation::ListSepaMandates, [$first, $second]);

        $mandates = (new ListSepaMandatesHandler($gateway))(new ListSepaMandates(PaymentWorld::tenant(), PaymentWorld::siret()));

        static::assertSame([$first, $second], $mandates);
        static::assertSame(['075'], $gateway->calls());
        static::assertSame([PaymentWorld::SIRET], array_map(static fn(Siret $siret): string => $siret->toString(), $gateway->sirets()));
    }

    public function testNoMandateIsAnEmptyList(): void
    {
        $gateway = new FakePaymentGateway(new Journal());
        $gateway->queue(Operation::ListSepaMandates, []);

        static::assertSame([], (new ListSepaMandatesHandler($gateway))(new ListSepaMandates(PaymentWorld::tenant(), PaymentWorld::siret())));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'not found' => ['not_found'];
        yield 'authentication' => ['authentication'];
        yield 'unavailable' => ['unavailable'];
        yield 'no credentials' => ['no_credentials'];
    }

    #[DataProvider('failures')]
    public function testEveryFailurePropagates(string $name): void
    {
        $gateway = new FakePaymentGateway(new Journal());
        $failure = match ($name) {
            'not_found' => UrssafRequestRejected::withErrors(Operation::ListSepaMandates, 404, ApiErrors::none(), RejectionKind::NotFound),
            'authentication' => UrssafAuthenticationFailed::withStatus(401),
            'unavailable' => UrssafUnavailable::withStatus(Operation::ListSepaMandates, 503, null, ApiErrors::none()),
            default => RecordNotFound::withIdentifier('credentials', 'tenant-1'),
        };
        $gateway->queue(Operation::ListSepaMandates, $failure);

        try {
            (new ListSepaMandatesHandler($gateway))(new ListSepaMandates(PaymentWorld::tenant(), PaymentWorld::siret()));
            static::fail('Expected the failure.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
    }
}
