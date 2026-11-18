<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support;

use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\MandateNotified;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * Each scenario reaches its state through the real handlers and the scripted platform.
 *
 * @mago-expect lint:too-many-methods One test per scenario.
 */
final class ScenarioTest extends TestCase
{
    public function testATrackedAccountIsReadOnceFromUrssaf(): void
    {
        $harness = Harness::boot();

        $account = Scenario::trackedAccount($harness);

        static::assertTrue($account->hasBirthDate);
        static::assertSame(Scenario::TENANT, $account->tenantId);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_020));
    }

    public function testAnImportedMandateIsActiveWithoutAnyCall(): void
    {
        $harness = Harness::boot();

        $mandate = Scenario::importedMandate($harness);

        static::assertSame('notified', $mandate->state);
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_030));
        static::assertNotNull($harness->privateService(MandateRepository::class, MandateRepository::class)->findActive(TenantId::fromString(Scenario::TENANT), SimulatorSamples::siret()));
    }

    public function testANotifiedMandateCallsUrssaf(): void
    {
        $harness = Harness::boot();

        $mandate = Scenario::notifiedMandate($harness);

        static::assertSame('notified', $mandate->state);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_030));
    }

    public function testADeclarationIsDeclared(): void
    {
        $harness = Harness::boot();

        $submission = Scenario::declaration($harness);

        static::assertSame(ItemOutcome::Declared, $submission->outcome);
        static::assertSame(DeclarationState::Declared->value, $submission->declaration->status->state);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testAnOutcomeUnknownDeclarationFollowsAServerError(): void
    {
        $harness = Harness::boot();

        $submission = Scenario::outcomeUnknownDeclaration($harness);

        static::assertSame(DeclarationState::OutcomeUnknown->value, $submission->declaration->status->state);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
        // The route answers a success again.
        static::assertSame(200, $harness->client->sendRequest(new Request('POST', 'https://api.example.test/tiersdecl/v1/ae/declarer'))->getStatusCode());
    }

    public function testAnInvalidCredentialsDeclarationFollowsARefusedToken(): void
    {
        $harness = Harness::boot();

        $submission = Scenario::invalidCredentialsDeclaration($harness);

        static::assertSame(DeclarationState::InvalidCredentials->value, $submission->declaration->status->state);
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_050));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_AUTHENTICATION));
    }

    public function testAStaleDeclarationIsSubmittingAndOlderThanThePolicy(): void
    {
        $harness = Harness::boot();

        $id = Scenario::staleDeclaration($harness);

        $records = $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class);
        static::assertSame(DeclarationState::Submitting, $records->get($id)->state());
        $policy = $harness->privateService(WorkflowPolicy::class, WorkflowPolicy::class);
        $stale = $records->findStaleSubmissions(Instant::fromDateTime($harness->clock->now()->sub($policy->staleSubmissionAfter())), 10);
        static::assertCount(1, $stale);
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testAPaymentIsPaid(): void
    {
        $harness = Harness::boot();

        $submission = Scenario::payment($harness);

        static::assertSame(ItemOutcome::Paid, $submission->outcome);
        static::assertSame(PaymentState::Paid->value, $submission->payment->status->state);
        static::assertSame(Scenario::PAYMENT, $submission->payment->reference);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testAnOutcomeUnknownPaymentFollowsAServerErrorAndIsNeverResent(): void
    {
        $harness = Harness::boot();

        $submission = Scenario::outcomeUnknownPayment($harness);

        static::assertSame(PaymentState::OutcomeUnknown->value, $submission->payment->status->state);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testAStalePaymentIsSubmittingAndOlderThanThePolicy(): void
    {
        $harness = Harness::boot();

        $id = Scenario::stalePayment($harness);

        $records = $harness->privateService(PaymentRecordRepository::class, PaymentRecordRepository::class);
        static::assertSame(PaymentState::Submitting, $records->get($id)->state());
        $policy = $harness->privateService(WorkflowPolicy::class, WorkflowPolicy::class);
        $stale = $records->findStaleSubmissions(Instant::fromDateTime($harness->clock->now()->sub($policy->staleSubmissionAfter())), 10);
        static::assertCount(1, $stale);
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testAMandatedAccountIsTrackedAndHoldsAMandate(): void
    {
        $harness = Harness::boot();

        $account = Scenario::mandatedAccount($harness);

        static::assertTrue($account->hasBirthDate);
        static::assertNotNull($harness->privateService(MandateRepository::class, MandateRepository::class)->findActive(TenantId::fromString(Scenario::TENANT), SimulatorSamples::siret()));
    }

    public function testAnImportedMandateLeavesAPendingOutboxEntryWhenTheOutboxIsEnabled(): void
    {
        $harness = Harness::boot(['outbox' => ['enabled' => true]]);

        $entry = Scenario::pendingOutboxEntry($harness);

        static::assertInstanceOf(MandateNotified::class, $entry->event);
        static::assertCount(1, $harness->privateService(Outbox::class, Outbox::class)->pending(10));
    }
}
