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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandateHandler;
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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\AccountWorld;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\DomainAssertions;
use Throwable;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class NotifyMandateHandlerTest extends TestCase
{
    public function testTheMandateIsRecordedOnceTheCallIsAccepted(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->gateway->queue(Operation::NotifyMandate, null);

        $view = self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('id-1', $view->mandateId);
        static::assertSame('notified', $view->origin);
        static::assertSame('notified', $view->state);
        static::assertSame('account-1', $view->accountId);
        static::assertSame(['030'], $world->gateway->calls());
        static::assertSame(
            ['call:030', 'begin', 'save:id-1', 'append:urssaf.tdae.mandate.notified', 'commit', 'publish:MandateNotified'],
            $world->journal->entries(),
        );
        $reference = $world->gateway->references()[0];
        static::assertSame(AccountWorld::SIRET, $reference->siret()->toString());
        static::assertSame('1975-02-23', $reference->birthDate()->toString());
    }

    public function testTheBirthDateOfTheCommandWinsAndIsStoredOnTheAccount(): void
    {
        $world = new AccountWorld();
        $world->trackedAccountWithoutBirthDate();
        $world->gateway->queue(Operation::NotifyMandate, null);

        self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17')));

        static::assertSame('1980-05-17', $world->gateway->references()[0]->birthDate()->toString());
        static::assertSame('1980-05-17', $world->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testAnUntrackedSiretIsNotifiedWithTheGivenBirthDate(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::NotifyMandate, null);

        $view = self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertNull($view->accountId);
        static::assertNull($world->accounts->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAnActiveLocalMandateIsReturnedWithoutAnyCallOrWrite(): void
    {
        $world = new AccountWorld();
        $world->activeMandate('mandate-existing');

        $view = self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), null));

        static::assertSame('mandate-existing', $view->mandateId);
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->journal->entries());
    }

    public function testAMandateOfAnotherSiretOrAnotherTenantDoesNotShortCircuit(): void
    {
        $world = new AccountWorld();
        $world->activeMandate('mandate-other', AccountWorld::OTHER_SIRET);
        $world->gateway->queue(Operation::NotifyMandate, null);

        $view = self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('id-1', $view->mandateId);
        static::assertSame(['030'], $world->gateway->calls());
    }

    public function testAMissingBirthDateRefusesTheCallBeforeSending(): void
    {
        $world = new AccountWorld();

        foreach ([false, true] as $tracked) {
            if ($tracked) {
                $world->trackedAccountWithoutBirthDate();
            }
            try {
                self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), null));
                static::fail('The birth date is required.');
            } catch (ValidationFailed $failure) {
                static::assertSame(['error birthDate account_reference.birth_date_required'], DomainAssertions::summary($failure->result()));
            }
        }
        static::assertSame([], $world->gateway->calls());
        static::assertSame([], $world->journal->entries());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'mandate held elsewhere' => ['held elsewhere'];
        yield 'rejected' => ['rejected'];
        yield 'outcome unknown' => ['outcome unknown'];
        yield 'unavailable' => ['unavailable'];
        yield 'rate limit' => ['rate limit'];
        yield 'credentials' => ['credentials'];
        yield 'foreign' => ['foreign'];
    }

    #[DataProvider('failures')]
    public function testEveryFailureOfTheCallPropagatesAndRecordsNothing(string $case): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $failure = self::failure($case);
        $world->gateway->queue(Operation::NotifyMandate, $failure);

        try {
            self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), null));
            static::fail('The failure must propagate.');
        } catch (Throwable $thrown) {
            static::assertSame($failure, $thrown);
        }
        static::assertNotInstanceOf(Mandate::class, $world->mandates->findActive(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame(['call:030'], $world->journal->entries());
        static::assertSame(['030'], $world->gateway->calls());
    }

    public function testAnAcceptedCallThatCannotBeRecordedFailsWithMandateRecordingFailedAndIsNeverResent(): void
    {
        $world = new AccountWorld();
        $world->trackedAccount();
        $world->gateway->queue(Operation::NotifyMandate, null);
        self::conflictEveryTime($world);

        try {
            self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate('1980-05-17')));
            static::fail('The recording must fail.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '030', 'attempts' => 3, 'last_code' => AeAccountRepository::CONCURRENT_MODIFICATION], $failure->params());
        }
        static::assertSame(['030'], $world->gateway->calls());
        static::assertNotInstanceOf(Mandate::class, $world->mandates->findActive(AccountWorld::tenant(), AccountWorld::siret()));
        static::assertSame([], $world->publishedEvents());
    }

    public function testAFailureOfTheRecordingOtherThanAConflictIsReportedAfterTheCall(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::NotifyMandate, null);
        $recorder = $world->recorderWithFailingOutbox(InvariantViolation::because('outbox.refused', 'Refused.'));
        $handler = new NotifyMandateHandler($world->accounts, $world->mandates, $world->gateway, $recorder);

        try {
            $handler(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));
            static::fail('The recording must fail.');
        } catch (MandateRecordingFailed $failure) {
            static::assertSame(['operation' => '030', 'attempts' => 1, 'last_code' => 'outbox.refused'], $failure->params());
        }
        static::assertSame(['call:030', 'begin', 'save:id-1', 'rollback'], $world->journal->entries());
    }

    public function testAMandateAnotherWriterRecordedDuringTheCallIsKept(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::NotifyMandate, null);
        $world->gateway->duringNextCall(static function () use ($world): void {
            $world->activeMandate('mandate-other');
        });

        $view = self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('mandate-other', $view->mandateId);
        static::assertCount(1, $world->mandates->findBySiret(AccountWorld::tenant(), AccountWorld::siret()));
    }

    public function testAMandateCreatedBetweenTheReadAndTheSaveIsRetriedAndKept(): void
    {
        $world = new AccountWorld();
        $world->gateway->queue(Operation::NotifyMandate, null);
        $world->beforeNextMandateSave(static function (InMemoryMandateRepository $inner): void {
            $inner->save(Mandate::import(MandateId::fromString('mandate-other'), AccountWorld::tenant(), AccountWorld::siret(), null, Instant::fromIso8601('2026-11-16T08:00:00Z')));
        });

        $view = self::handler($world)(new NotifyMandate(AccountWorld::tenant(), AccountWorld::siret(), AccountWorld::birthDate()));

        static::assertSame('mandate-other', $view->mandateId);
        static::assertSame(['030'], $world->gateway->calls());
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

    private static function handler(AccountWorld $world): NotifyMandateHandler
    {
        return new NotifyMandateHandler($world->accounts, $world->mandates, $world->gateway, $world->recorder());
    }

    private static function failure(string $case): Throwable
    {
        $unavailable = UrssafUnavailable::withStatus(Operation::NotifyMandate, 503, 30, ApiErrors::none());

        return match ($case) {
            'held elsewhere' => UrssafRequestRejected::withErrors(Operation::NotifyMandate, 400, ApiErrors::none(), RejectionKind::MandateHeldElsewhere),
            'rejected' => UrssafRequestRejected::withErrors(Operation::NotifyMandate, 400, ApiErrors::none(), RejectionKind::AccountUnknown),
            'outcome unknown' => OutcomeUnknown::fromUnavailable($unavailable),
            'unavailable' => $unavailable,
            'rate limit' => RateLimitExceeded::forOperation(Operation::NotifyMandate, 5),
            'credentials' => UrssafAuthenticationFailed::withStatus(403),
            default => new LogicException('boom'),
        };
    }
}
