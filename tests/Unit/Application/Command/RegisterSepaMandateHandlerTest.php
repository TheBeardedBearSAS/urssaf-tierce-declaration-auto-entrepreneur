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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FakePaymentGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\Journal;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\JournalingOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;
use Throwable;

/**
 * RegisterSepaMandate (070): pass-through, the integration event is appended in a transaction of its own after success
 * (ADR 0009, ADR 0014 item 5).
 */
final class RegisterSepaMandateHandlerTest extends TestCase
{
    public function testTheEventIsAppendedInItsOwnTransactionAfterTheCall(): void
    {
        $journal = new Journal();
        $gateway = new FakePaymentGateway($journal);
        $mandate = new SepaMandate(Rum::fromString(SepaSamples::RUM), 'Bank', 'FR76***', 'HOLDER', PaymentWorld::siret(), null);
        $gateway->queue(Operation::RegisterSepaMandate, $mandate);
        $outbox = new JournalingOutbox($journal);
        $transactions = new RecordingTransactionManager($journal);

        $result = $this->handler($gateway, $transactions, $outbox)(self::command());

        static::assertSame($mandate, $result);
        static::assertSame(
            ['call:070', 'begin', 'append:urssaf.tdae.sepa_mandate.registered', 'commit'],
            $journal->entries(),
        );
        static::assertSame(1, $transactions->committed());
        $events = $outbox->appended();
        static::assertCount(1, $events);
        static::assertInstanceOf(SepaMandateRegistered::class, $events[0]);
        static::assertSame(SepaSamples::RUM, $events[0]->rum);
        static::assertSame('tenant-1', $events[0]->tenantId);
        static::assertSame('2026-11-17T09:00:00Z', $events[0]->occurredAt);
        static::assertNull($events[0]->aggregateId);
        static::assertNull($events[0]->externalReference);
        static::assertSame([PaymentWorld::SIRET], array_map(static fn(Siret $siret): string => $siret->toString(), $gateway->sirets()));
        static::assertCount(1, $gateway->registered());
        // The event carries the RUM only: no bank data.
        static::assertStringNotContainsString(SepaSamples::IBAN, serialize($events));
        static::assertStringNotContainsString(SepaSamples::HOLDER, serialize($events));
    }

    public function testAnAnswerWithoutRumWritesNoEvent(): void
    {
        $journal = new Journal();
        $gateway = new FakePaymentGateway($journal);
        $mandate = new SepaMandate(null, 'Bank', null, null, null, null);
        $gateway->queue(Operation::RegisterSepaMandate, $mandate);
        $outbox = new JournalingOutbox($journal);

        $result = $this->handler($gateway, new RecordingTransactionManager($journal), $outbox)(self::command());

        static::assertSame($mandate, $result);
        static::assertSame(['call:070'], $journal->entries());
        static::assertSame([], $outbox->appended());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'already exists' => ['already_exists'];
        yield 'invalid bank details' => ['invalid_bank_details'];
        yield 'unknown outcome' => ['unknown'];
        yield 'authentication' => ['authentication'];
        yield 'unavailable' => ['unavailable'];
        yield 'rate limit' => ['rate_limit'];
        yield 'no credentials' => ['no_credentials'];
        yield 'foreign' => ['foreign'];
    }

    #[DataProvider('failures')]
    public function testEveryFailureOfTheCallPropagatesAndWritesNoEvent(string $name): void
    {
        $journal = new Journal();
        $gateway = new FakePaymentGateway($journal);
        $failure = self::failure($name);
        $gateway->queue(Operation::RegisterSepaMandate, $failure);
        $outbox = new JournalingOutbox($journal);

        try {
            $this->handler($gateway, new RecordingTransactionManager($journal), $outbox)(self::command());
            static::fail('Expected the failure.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertSame(['call:070'], $journal->entries());
        static::assertSame([], $outbox->appended());
    }

    private static function failure(string $name): Throwable
    {
        return match ($name) {
            'already_exists' => UrssafRequestRejected::withErrors(
                Operation::RegisterSepaMandate,
                409,
                ApiErrors::of(ApiError::of('ER_API_070_0409', null, null)),
                RejectionKind::SepaMandateAlreadyExists,
            ),
            'invalid_bank_details' => UrssafRequestRejected::withErrors(Operation::RegisterSepaMandate, 400, ApiErrors::none(), RejectionKind::InvalidBankDetails),
            'unknown' => OutcomeUnknown::fromUnavailable(UrssafUnavailable::withStatus(Operation::RegisterSepaMandate, 500, null, ApiErrors::none())),
            'authentication' => UrssafAuthenticationFailed::withStatus(401),
            'unavailable' => UrssafUnavailable::withStatus(Operation::RegisterSepaMandate, 429, null, ApiErrors::none()),
            'rate_limit' => RateLimitExceeded::forOperation(Operation::RegisterSepaMandate, 5),
            'no_credentials' => RecordNotFound::withIdentifier('credentials', 'tenant-1'),
            default => new RuntimeException('foreign'),
        };
    }

    public function testAnAppendFailurePropagatesUnchangedAfterTheCall(): void
    {
        $journal = new Journal();
        $gateway = new FakePaymentGateway($journal);
        $gateway->queue(Operation::RegisterSepaMandate, new SepaMandate(Rum::fromString(SepaSamples::RUM), null, null, null, null, null));
        $storage = new RuntimeException('outbox down');

        try {
            $this->handler($gateway, new RecordingTransactionManager($journal), new FailingOutbox($storage))(self::command());
            static::fail('Expected the append failure.');
        } catch (RuntimeException $thrown) {
            static::assertSame($storage, $thrown);
        }
        static::assertSame(['call:070', 'begin', 'failed'], $journal->entries());
        static::assertSame(['070'], $gateway->calls());
    }

    private static function command(): RegisterSepaMandate
    {
        return new RegisterSepaMandate(PaymentWorld::tenant(), PaymentWorld::siret(), SepaSamples::newMandate());
    }

    private function handler(FakePaymentGateway $gateway, TransactionManager $transactions, JournalingOutbox|FailingOutbox $outbox): RegisterSepaMandateHandler
    {
        return new RegisterSepaMandateHandler($gateway, $transactions, $outbox, new FixedClock(PaymentWorld::NOW));
    }
}
