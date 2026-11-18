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

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\CancelMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\CancelMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use Throwable;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class CancelMandateHandlerTest extends TestCase
{
    public function testTheMandateIsCancelledOnceTheCallIsAccepted(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();
        $world->gateway->queue(Operation::CancelMandate, null);

        $view = self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('mandate-1', $view->mandateId);
        static::assertSame('cancelled', $view->state);
        static::assertSame('cancelled', $view->cancellationOrigin);
        static::assertSame('2026-11-17T09:00:00Z', $view->cancelledAt);
        static::assertSame(['031'], $world->gateway->calls());
        static::assertSame(
            ['call:031', 'begin', 'save:mandate-1', 'append:urssaf.tdae.mandate.cancelled', 'commit', 'publish:MandateCancelled'],
            $world->journal->entries(),
        );
        static::assertSame('1975-02-23', $world->gateway->references()[0]->birthDate()->toString());
    }

    public function testTheGivenBirthDateIsSentAndStored(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $world->activeMandate();
        $world->gateway->queue(Operation::CancelMandate, null);

        self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17')));

        static::assertSame('1980-05-17', $world->gateway->references()[0]->birthDate()->toString());
        static::assertSame('1980-05-17', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testWithoutAnActiveMandateNothingIsSent(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->cancelledMandate();

        try {
            self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), null));
            static::fail('There is no active mandate to cancel.');
        } catch (RecordNotFound $notFound) {
            static::assertSame('mandate.not_found', $notFound->violationCode());
            static::assertSame('***', $notFound->identifier());
        }
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->journal->entries());
    }

    public function testAMissingBirthDateRefusesTheCallBeforeSending(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();

        try {
            self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), null));
            static::fail('The birth date is required.');
        } catch (ValidationFailed $failure) {
            static::assertSame('validation.failed', $failure->violationCode());
        }
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->journal->entries());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'rejected' => ['rejected'];
        yield 'outcome unknown' => ['outcome unknown'];
        yield 'unavailable' => ['unavailable'];
        yield 'rate limit' => ['rate limit'];
        yield 'credentials' => ['credentials'];
        yield 'foreign' => ['foreign'];
    }

    #[DataProvider('failures')]
    public function testEveryFailureOfTheCallPropagatesAndCancelsNothing(string $case): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();
        $failure = self::failure($case);
        $world->gateway->queue(Operation::CancelMandate, $failure);

        try {
            self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), null));
            static::fail('The failure must propagate.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertTrue($world->mandates->get(MandateId::fromString('mandate-1'))->isActive());
        static::assertSame(['call:031'], $world->journal->entries());
    }

    public function testAnAcceptedCallThatCannotBeRecordedFailsWithMandateRecordingFailedAndIsNeverResent(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();
        $world->gateway->queue(Operation::CancelMandate, null);
        self::conflictEveryTime($world);

        try {
            self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17')));
            static::fail('The recording must fail.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '031', 'attempts' => 3, 'last_code' => AeAccountRepository::CONCURRENT_MODIFICATION], $failure->params());
        }
        static::assertSame(['031'], $world->gateway->calls());
        static::assertTrue($world->mandates->get(MandateId::fromString('mandate-1'))->isActive());
    }

    public function testACancellationAnotherWriterRecordedDuringTheCallIsKept(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();
        $world->gateway->queue(Operation::CancelMandate, null);
        $world->gateway->duringNextCall(static function () use ($world): void {
            $other = $world->mandates->get(MandateId::fromString('mandate-1'));
            $other->recordCancellation(Instant::fromIso8601('2026-11-16T08:00:00Z'));
            $world->mandates->add($other);
        });

        $view = self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('recorded', $view->cancellationOrigin);
        static::assertSame('2026-11-16T08:00:00Z', $view->cancelledAt);
    }

    public function testAConflictOnTheMandateIsRetried(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->activeMandate();
        $world->gateway->queue(Operation::CancelMandate, null);
        $world->beforeNextMandateSave(static function (InMemoryMandateRepository $inner): void {
            $inner->save($inner->get(MandateId::fromString('mandate-1')));
        });

        $view = self::handler($world)(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('cancelled', $view->cancellationOrigin);
        static::assertSame(['031'], $world->gateway->calls());
    }

    public function testAFailureOfTheRecordingOtherThanAConflictIsReportedAfterTheCall(): void
    {
        $world = new AccountWorld();
        $world->activeMandate();
        $world->trackedAccount();
        $world->gateway->queue(Operation::CancelMandate, null);
        $recorder = $world->recorderWithFailingOutbox(InvariantViolation::because('outbox.refused', 'Refused.'));
        $handler = new CancelMandateHandler($world->accounts, $world->mandates, $world->gateway, $recorder);

        try {
            $handler(new CancelMandate(AccountWorld::tenant(), AccountWorld::siret(), null));
            static::fail('The recording must fail.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '031', 'attempts' => 1, 'last_code' => 'outbox.refused'], $failure->params());
        }
        static::assertTrue($world->mandates->get(MandateId::fromString('mandate-1'))->isActive());
    }

    private static function conflictEveryTime(AccountWorld $world): void
    {
        $world->beforeNextAccountSave(static function (InMemoryAeAccountRepository $inner) use ($world): void {
            $other = $inner->get(AccountId::fromString('account-1'));
            $other->sync(AccountWorld::status(), Instant::fromIso8601('2026-11-17T08:00:00Z'));
            $inner->save($other);
            self::conflictEveryTime($world);
        });
    }

    private static function handler(AccountWorld $world): CancelMandateHandler
    {
        return new CancelMandateHandler($world->accounts, $world->mandates, $world->gateway, $world->recorder());
    }

    private static function failure(string $case): Throwable
    {
        $unavailable = UrssafUnavailable::withStatus(Operation::CancelMandate, 503, 30, ApiErrors::none());

        return match ($case) {
            'rejected' => UrssafRequestRejected::withErrors(Operation::CancelMandate, 400, ApiErrors::none(), RejectionKind::MandateMissing),
            'outcome unknown' => OutcomeUnknown::fromUnavailable($unavailable),
            'unavailable' => $unavailable,
            'rate limit' => RateLimitExceeded::forOperation(Operation::CancelMandate, 5),
            'credentials' => UrssafAuthenticationFailed::withStatus(401),
            default => new LogicException('boom'),
        };
    }
}
