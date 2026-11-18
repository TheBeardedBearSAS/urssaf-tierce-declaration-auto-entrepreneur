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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RequeueAfterInvalidCredentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RequeueAfterInvalidCredentialsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\RequeueReport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

/**
 * RequeueAfterInvalidCredentials: both record kinds go back to pending in one transaction, nothing is sent.
 */
final class RequeueAfterInvalidCredentialsHandlerTest extends TestCase
{
    public function testBothKindsOfRecordsAreRequeuedAndTheReportCountsEach(): void
    {
        $world = $this->worldWithInvalidCredentials();

        $report = $this->requeue($world, null);

        static::assertEquals(new RequeueReport(2, 3), $report);
        static::assertSame(2, $report->declarations);
        static::assertSame(3, $report->payments);
        foreach (['d1', 'd2'] as $id) {
            static::assertSame(DeclarationState::Pending, $world->declarations->get(DeclarationRecordId::fromString($id))->state());
        }
        foreach (['p1', 'p2', 'p3'] as $id) {
            static::assertSame(PaymentState::Pending, $world->payments->get(PaymentRecordId::fromString($id))->state());
        }
        static::assertSame(
            ['DeclarationRequeued', 'DeclarationRequeued', 'PaymentRequeued', 'PaymentRequeued', 'PaymentRequeued'],
            $world->publisher->names(),
        );
        static::assertSame([], $world->gateway->calls());
        static::assertSame(1, $world->transactions->committed());
    }

    public function testOtherStatesAndOtherTenantsAreLeftAlone(): void
    {
        $world = new PaymentWorld();
        $world->declarationIn('d-unknown', DeclarationState::OutcomeUnknown);
        $world->declarationIn('d-other', DeclarationState::InvalidCredentials, 'tenant-2');
        $world->paymentWith('p-unknown', PaymentState::OutcomeUnknown, 1, PaymentWorld::order(), 'ref-1', PaymentWorld::SIRET, 'tenant-1');
        $world->paymentWith('p-other', PaymentState::InvalidCredentials, 1, PaymentWorld::order(), 'ref-2', PaymentWorld::SIRET, 'tenant-2');

        $report = $this->requeue($world, null);

        static::assertEquals(new RequeueReport(0, 0), $report);
        static::assertSame([], $world->publisher->names());
        static::assertSame(PaymentState::InvalidCredentials, $world->payments->get(PaymentRecordId::fromString('p-other'))->state());
        static::assertSame(DeclarationState::InvalidCredentials, $world->declarations->get(DeclarationRecordId::fromString('d-other'))->state());
    }

    public function testTheLimitAppliesToEachKind(): void
    {
        $world = $this->worldWithInvalidCredentials();

        $first = $this->requeue($world, 1);
        $second = $this->requeue($world, 1);
        $third = $this->requeue($world, 5);

        static::assertEquals(new RequeueReport(1, 1), $first);
        static::assertEquals(new RequeueReport(1, 1), $second);
        static::assertEquals(new RequeueReport(0, 1), $third);
    }

    public function testTheLimitIsCappedByThePolicy(): void
    {
        $world = new PaymentWorld((new WorkflowPolicy())->withPageSizes(1, 2));
        foreach (['p1', 'p2', 'p3'] as $index => $id) {
            $world->paymentWith($id, PaymentState::InvalidCredentials, 1, PaymentWorld::order(), 'ref-' . $index, PaymentWorld::SIRET, 'tenant-1');
        }

        static::assertEquals(new RequeueReport(0, 1), $this->requeue($world, null));
        static::assertEquals(new RequeueReport(0, 2), $this->requeue($world, 50));
    }

    public function testAnInvalidLimitIsRefused(): void
    {
        $world = $this->worldWithInvalidCredentials();

        $this->expectException(InvariantViolation::class);
        $this->expectExceptionMessage('The limit must be a positive integer.');

        $this->requeue($world, 0);
    }

    public function testARecordThatUsedAllItsAttemptsIsRequeuedAnyway(): void
    {
        // An invalid_credentials record is always retryable: the credentials were wrong, no attempt was really lost.
        $world = new PaymentWorld((new WorkflowPolicy())->withMaxSubmissionAttempts(1));
        $world->declarationIn('d1', DeclarationState::InvalidCredentials);
        $world->paymentIn('p1', PaymentState::InvalidCredentials);

        static::assertEquals(new RequeueReport(1, 1), $this->requeue($world, null));
        static::assertSame(1, $world->payments->get(PaymentRecordId::fromString('p1'))->attempts());
    }

    public function testAPaymentConflictFailsTheWholeTransactionAndRequeuesNothing(): void
    {
        $world = $this->worldWithInvalidCredentials();
        $world->otherWriterBeforeNextSave(static function (InMemoryPaymentRecordRepository $inner): void {
            $other = $inner->get(PaymentRecordId::fromString('p2'));
            $other->markPaid(PaymentWorld::paid(), Instant::fromIso8601(PaymentWorld::NOW));
            $inner->save($other);
        });

        try {
            $this->requeue($world, null);
            static::fail('Expected the conflict.');
        } catch (InvariantViolation $conflict) {
            static::assertSame('payment_record.concurrent_modification', $conflict->violationCode());
        }
        static::assertSame(DeclarationState::InvalidCredentials, $world->declarations->get(DeclarationRecordId::fromString('d1'))->state());
        static::assertSame(DeclarationState::InvalidCredentials, $world->declarations->get(DeclarationRecordId::fromString('d2'))->state());
        static::assertSame(PaymentState::InvalidCredentials, $world->payments->get(PaymentRecordId::fromString('p1'))->state());
        static::assertSame(PaymentState::Paid, $world->payments->get(PaymentRecordId::fromString('p2'))->state());
        static::assertSame([], $world->publisher->names());
        static::assertContains('rollback', $world->journal->entries());
    }

    public function testADeclarationConflictFailsTheWholeTransactionAndRequeuesNothing(): void
    {
        $world = $this->worldWithInvalidCredentials();
        $world->declarations->beforeNextSave(static function (InMemoryDeclarationRecordRepository $inner) use ($world): void {
            $world->transactions->outside(static function () use ($inner): void {
                $other = $inner->get(DeclarationRecordId::fromString('d1'));
                $other->retry(Instant::fromIso8601(PaymentWorld::NOW));
                $inner->save($other);
            });
        });

        try {
            $this->requeue($world, null);
            static::fail('Expected the conflict.');
        } catch (InvariantViolation $conflict) {
            static::assertSame('declaration_record.concurrent_modification', $conflict->violationCode());
        }
        static::assertSame(PaymentState::InvalidCredentials, $world->payments->get(PaymentRecordId::fromString('p1'))->state());
        static::assertSame(DeclarationState::Pending, $world->declarations->get(DeclarationRecordId::fromString('d1'))->state());
        static::assertSame(DeclarationState::InvalidCredentials, $world->declarations->get(DeclarationRecordId::fromString('d2'))->state());
        static::assertSame([], $world->publisher->names());
    }

    private function worldWithInvalidCredentials(): PaymentWorld
    {
        $world = new PaymentWorld();
        $world->declarationIn('d1', DeclarationState::InvalidCredentials);
        $world->declarationIn('d2', DeclarationState::InvalidCredentials, 'tenant-1', 9);
        foreach (['p1', 'p2', 'p3'] as $index => $id) {
            $world->paymentWith($id, PaymentState::InvalidCredentials, 1, PaymentWorld::order(), 'ref-' . $index, PaymentWorld::SIRET, 'tenant-1');
        }

        return $world;
    }

    private function requeue(PaymentWorld $world, ?int $limit): RequeueReport
    {
        return (new RequeueAfterInvalidCredentialsHandler($world->declarations, $world->payments, $world->clock, $world->unitOfWork, $world->policy))(
            new RequeueAfterInvalidCredentials(PaymentWorld::tenant(), $limit),
        );
    }
}
