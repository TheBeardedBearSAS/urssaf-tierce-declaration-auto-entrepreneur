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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RevokeSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RevokeSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRevoked;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
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
 * RevokeSepaMandate (071): the effective date guard of ADR 0011 item 4, then a pass-through call, then the event in a
 * transaction of its own.
 *
 * @mago-expect lint:too-many-methods
 */
final class RevokeSepaMandateHandlerTest extends TestCase
{
    public function testTheMandateIsRevokedThenTheEventIsAppendedInItsOwnTransaction(): void
    {
        [$journal, $gateway, $outbox] = self::parts();
        $gateway->queue(Operation::RevokeSepaMandate, null);

        $this->handler($gateway, $outbox, $journal, PaymentWorld::NOW)(self::command('2026-11-18'));

        static::assertSame(
            ['call:071', 'begin', 'append:urssaf.tdae.sepa_mandate.revoked', 'commit'],
            $journal->entries(),
        );
        $events = $outbox->appended();
        static::assertCount(1, $events);
        static::assertInstanceOf(SepaMandateRevoked::class, $events[0]);
        static::assertSame(SepaSamples::RUM, $events[0]->rum);
        static::assertSame('tenant-1', $events[0]->tenantId);
        static::assertSame('2026-11-17T09:00:00Z', $events[0]->occurredAt);
        static::assertCount(1, $gateway->revoked());
        static::assertSame('2026-11-18', $gateway->revoked()[0]->effectiveDate()?->toString());
    }

    /**
     * [now, effective date, accepted]: the business day is the Paris one, which is not the UTC one late in the evening.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function effectiveDates(): iterable
    {
        yield 'today is refused' => ['2026-11-17T09:00:00Z', '2026-11-17', false];
        yield 'a past date is refused' => ['2026-11-17T09:00:00Z', '2026-11-01', false];
        yield 'tomorrow is accepted' => ['2026-11-17T09:00:00Z', '2026-11-18', true];
        yield 'at 23:30 UTC it is already tomorrow in Paris: that day is refused' => ['2026-11-17T23:30:00Z', '2026-11-18', false];
        yield 'at 23:30 UTC, the day after the Paris day is accepted' => ['2026-11-17T23:30:00Z', '2026-11-19', true];
    }

    #[DataProvider('effectiveDates')]
    public function testTheEffectiveDateMustBeAfterTodayInTheBusinessTimeZone(string $now, string $date, bool $accepted): void
    {
        [$journal, $gateway, $outbox] = self::parts();
        $gateway->queue(Operation::RevokeSepaMandate, null);

        try {
            $this->handler($gateway, $outbox, $journal, $now)(self::command($date));
            static::assertTrue($accepted);
            static::assertSame(['071'], $gateway->calls());

            return;
        } catch (ValidationFailed $refusal) {
            static::assertFalse($accepted);
            $errors = $refusal->result()->errors();
            static::assertCount(1, $errors);
            static::assertSame('sepa_revocation.effective_date_too_early', $errors[0]->code());
            static::assertSame('effective_date', $errors[0]->path());
        }
        static::assertSame([], $journal->entries());
        static::assertSame([], $gateway->calls());
        static::assertSame([], $outbox->appended());
    }

    public function testTheDateCheckCanBeSkipped(): void
    {
        [$journal, $gateway, $outbox] = self::parts();
        $gateway->queue(Operation::RevokeSepaMandate, null);

        $this->handler($gateway, $outbox, $journal, PaymentWorld::NOW)(new RevokeSepaMandate(
            PaymentWorld::tenant(),
            SepaRevocation::of(PaymentWorld::siret(), Rum::fromString(SepaSamples::RUM), CalendarDate::fromString('2026-11-01')),
            true,
        ));

        static::assertSame(['071'], $gateway->calls());
        static::assertCount(1, $outbox->appended());
    }

    public function testAnOmittedDatePassesTheGuard(): void
    {
        [$journal, $gateway, $outbox] = self::parts();
        $gateway->queue(Operation::RevokeSepaMandate, null);

        $this->handler($gateway, $outbox, $journal, PaymentWorld::NOW)(self::command(null));

        static::assertSame(['071'], $gateway->calls());
        static::assertNull($gateway->revoked()[0]->effectiveDate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'not found' => ['not_found'];
        yield 'unknown outcome' => ['unknown'];
        yield 'authentication' => ['authentication'];
        yield 'unavailable' => ['unavailable'];
        yield 'foreign' => ['foreign'];
    }

    #[DataProvider('failures')]
    public function testEveryFailureOfTheCallPropagatesAndWritesNoEvent(string $name): void
    {
        [$journal, $gateway, $outbox] = self::parts();
        $failure = self::failure($name);
        $gateway->queue(Operation::RevokeSepaMandate, $failure);

        try {
            $this->handler($gateway, $outbox, $journal, PaymentWorld::NOW)(self::command('2026-11-18'));
            static::fail('Expected the failure.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertSame(['call:071'], $journal->entries());
        static::assertSame([], $outbox->appended());
    }

    private static function failure(string $name): Throwable
    {
        return match ($name) {
            'not_found' => UrssafRequestRejected::withErrors(Operation::RevokeSepaMandate, 404, ApiErrors::none(), RejectionKind::NotFound),
            'unknown' => OutcomeUnknown::fromUnavailable(UrssafUnavailable::withStatus(Operation::RevokeSepaMandate, 500, null, ApiErrors::none())),
            'authentication' => UrssafAuthenticationFailed::withStatus(403),
            'unavailable' => UrssafUnavailable::withStatus(Operation::RevokeSepaMandate, 429, null, ApiErrors::none()),
            default => new RuntimeException('foreign'),
        };
    }

    public function testAnAppendFailurePropagatesUnchangedAfterTheCall(): void
    {
        [$journal, $gateway] = self::parts();
        $gateway->queue(Operation::RevokeSepaMandate, null);
        $storage = new RuntimeException('outbox down');

        try {
            $this->handler($gateway, new FailingOutbox($storage), $journal, PaymentWorld::NOW)(self::command('2026-11-18'));
            static::fail('Expected the append failure.');
        } catch (RuntimeException $thrown) {
            static::assertSame($storage, $thrown);
        }
        static::assertSame(['call:071', 'begin', 'failed'], $journal->entries());
    }

    /**
     * @return array{Journal, FakePaymentGateway, JournalingOutbox}
     */
    private static function parts(): array
    {
        $journal = new Journal();

        return [$journal, new FakePaymentGateway($journal), new JournalingOutbox($journal)];
    }

    private static function command(?string $effectiveDate): RevokeSepaMandate
    {
        return new RevokeSepaMandate(
            PaymentWorld::tenant(),
            SepaRevocation::of(
                PaymentWorld::siret(),
                Rum::fromString(SepaSamples::RUM),
                $effectiveDate === null ? null : CalendarDate::fromString($effectiveDate),
            ),
        );
    }

    private function handler(FakePaymentGateway $gateway, Outbox $outbox, Journal $journal, string $now): RevokeSepaMandateHandler
    {
        return new RevokeSepaMandateHandler($gateway, new RecordingTransactionManager($journal), $outbox, new FixedClock($now), new WorkflowPolicy());
    }
}
