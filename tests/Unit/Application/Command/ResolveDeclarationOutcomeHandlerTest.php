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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolveDeclarationOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolveDeclarationOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\DeclarationDeclared;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\DeclarationWorld;
use Throwable;

/**
 * @mago-expect lint:too-many-methods
 */
final class ResolveDeclarationOutcomeHandlerTest extends TestCase
{
    public function testAnOperatorResolutionAsDeclaredKeepsTheCertificatOnly(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::OutcomeUnknown, 1);

        $view = $this->resolve($world, ResolveDeclarationOutcome::asDeclared(DeclarationWorld::tenant(), self::byId('record-1'), '12345678'));

        static::assertSame('declared', $view->status->state);
        static::assertSame('12345678', $view->status->result?->certificat);
        static::assertNull($view->status->result->idDeclaration);
        static::assertNull($view->status->result->dueDate);
        static::assertNull($view->status->result->amounts);
        static::assertTrue($view->status->result->resolved);
        static::assertSame(
            ['begin', 'save:record-1', 'append:urssaf.tdae.declaration.declared', 'commit', 'publish:DeclarationDeclared'],
            $world->journal->entries(),
        );
        $event = $world->outbox->appended()[0];
        static::assertInstanceOf(DeclarationDeclared::class, $event);
        static::assertNull($event->idDeclaration);
        static::assertSame('12345678', $event->certificat);
        static::assertSame([], $world->gateway->calls());
    }

    public function testAnOperatorResolutionAsNotReceivedPutsTheRecordBackToPending(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::OutcomeUnknown, 1);

        $view = $this->resolve($world, ResolveDeclarationOutcome::asNotReceived(DeclarationWorld::tenant(), self::byId('record-1')));

        static::assertSame('pending', $view->status->state);
        static::assertSame(1, $view->status->attempts);
        static::assertSame(
            ['begin', 'save:record-1', 'append:', 'commit', 'publish:DeclarationRequeued'],
            $world->journal->entries(),
        );
        static::assertSame([], $world->gateway->calls());
    }

    public function testTheRecordIsFoundAsTheCurrentRecordOfASiretAndAPeriod(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::OutcomeUnknown, 1);

        $view = $this->resolve($world, ResolveDeclarationOutcome::asDeclared(
            DeclarationWorld::tenant(),
            DeclarationSelector::current(DeclarationWorld::siret(), DeclarationWorld::period()),
            '12345678',
        ));

        static::assertSame('record-1', $view->recordId);
        static::assertSame('declared', $view->status->state);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCertificats(): iterable
    {
        yield 'a blank certificat' => ['', 'certificate.invalid_format'];
        yield 'a whitespace certificat' => ['   ', 'certificate.invalid_format'];
        yield 'a malformed certificat' => ['ABC123', 'certificate.invalid_format'];
        yield 'a too long certificat' => ['123456789', 'certificate.invalid_format'];
    }

    #[DataProvider('invalidCertificats')]
    public function testAnInvalidCertificatIsRefusedAndNothingIsSaved(string $certificat, string $code): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::OutcomeUnknown, 1);

        $thrown = $this->resolveAndCatch($world, ResolveDeclarationOutcome::asDeclared(DeclarationWorld::tenant(), self::byId('record-1'), $certificat));

        static::assertInstanceOf(InvalidValue::class, $thrown);
        static::assertSame($code, $thrown->violationCode());
        static::assertSame(['begin', 'rollback'], $world->journal->entries());
        static::assertSame(DeclarationState::OutcomeUnknown, $world->records->get(DeclarationRecordId::fromString('record-1'))->state());
    }

    /**
     * @return iterable<string, array{DeclarationState}>
     */
    public static function otherStates(): iterable
    {
        foreach ([DeclarationState::Pending, DeclarationState::Submitting, DeclarationState::Declared, DeclarationState::Rejected, DeclarationState::Error, DeclarationState::InvalidCredentials] as $state) {
            yield $state->value => [$state];
        }
    }

    #[DataProvider('otherStates')]
    public function testOnlyAnUnknownOutcomeCanBeResolved(DeclarationState $state): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', $state, 1);

        $declared = $this->resolveAndCatch($world, ResolveDeclarationOutcome::asDeclared(DeclarationWorld::tenant(), self::byId('record-1'), '12345678'));
        $notReceived = $this->resolveAndCatch($world, ResolveDeclarationOutcome::asNotReceived(DeclarationWorld::tenant(), self::byId('record-1')));

        static::assertInstanceOf(InvalidStateTransition::class, $declared);
        static::assertInstanceOf(InvalidStateTransition::class, $notReceived);
        static::assertSame($state, $world->records->get(DeclarationRecordId::fromString('record-1'))->state());
        static::assertSame([], $world->publisher->names());
    }

    public function testARecordChangedSinceItWasReadIsNotSaved(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::OutcomeUnknown, 1);
        $world->beforeNextSaveCommit(static function () use ($world): void {
            $other = $world->records->get(DeclarationRecordId::fromString('record-1'));
            $other->resolveAsNotReceived(Instant::fromIso8601(DeclarationWorld::NOW));
            $world->records->inner()->save($other);
        });

        $thrown = $this->resolveAndCatch($world, ResolveDeclarationOutcome::asDeclared(DeclarationWorld::tenant(), self::byId('record-1'), '12345678'));

        static::assertInstanceOf(InvariantViolation::class, $thrown);
        static::assertSame('declaration_record.concurrent_modification', $thrown->violationCode());
        static::assertSame(DeclarationState::Pending, $world->records->get(DeclarationRecordId::fromString('record-1'))->state());
        static::assertSame([], $world->publisher->names());
        static::assertSame([], $world->outbox->appended());
    }

    public function testAnUnknownRecordIsNotFound(): void
    {
        $world = new DeclarationWorld();

        $thrown = $this->resolveAndCatch($world, ResolveDeclarationOutcome::asNotReceived(DeclarationWorld::tenant(), self::byId('missing')));

        static::assertInstanceOf(RecordNotFound::class, $thrown);
        static::assertSame('declaration_record.not_found', $thrown->violationCode());
        static::assertSame([], $world->journal->entries());
    }

    public function testARecordOfAnotherTenantIsNotFound(): void
    {
        $world = new DeclarationWorld();
        $world->recordIn('record-1', DeclarationState::OutcomeUnknown, 1);

        $thrown = $this->resolveAndCatch($world, ResolveDeclarationOutcome::asNotReceived(DeclarationWorld::tenant('tenant-2'), self::byId('record-1')));

        static::assertInstanceOf(RecordNotFound::class, $thrown);
        static::assertSame(DeclarationState::OutcomeUnknown, $world->records->get(DeclarationRecordId::fromString('record-1'))->state());
    }

    private static function byId(string $id): DeclarationSelector
    {
        return DeclarationSelector::byRecordId(DeclarationRecordId::fromString($id));
    }

    private function resolve(DeclarationWorld $world, ResolveDeclarationOutcome $command): DeclarationView
    {
        return (new ResolveDeclarationOutcomeHandler($world->locator, $world->records, $world->clock, $world->unitOfWork))($command);
    }

    private function resolveAndCatch(DeclarationWorld $world, ResolveDeclarationOutcome $command): ?Throwable
    {
        try {
            $this->resolve($world, $command);
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
