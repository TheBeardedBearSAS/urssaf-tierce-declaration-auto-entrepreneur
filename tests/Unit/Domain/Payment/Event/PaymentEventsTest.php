<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment\Event;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentCredentialsRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOrdered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentOutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentRequeued;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\Event\PaymentSubmissionFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Payment\PaymentSamples;

/**
 * Identifiers, periods, states and codes only: no amount, no IBAN, no SIRET (ADR 0010 item 8, ADR 0014 item 3).
 */
final class PaymentEventsTest extends TestCase
{
    public function testPaymentRecorded(): void
    {
        $event = new PaymentRecorded(
            PaymentSamples::recordId('record-2'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            null,
            PaymentSamples::period(),
            PaymentSamples::recordId('record-1'),
            PaymentSamples::instant(),
        );

        static::assertSame('record-2', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertNull($event->accountId());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('record-1', $event->supersedes()?->value());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testPaymentOrdered(): void
    {
        $event = new PaymentOrdered(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::accountId(),
            PaymentSamples::period(),
            PaymentSamples::certificate(),
            'W1dl9W2Oa4R6ab3UlQAqxF2',
            PaymentSamples::rum(),
            PaymentSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame('12345678', $event->certificate()->value());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $event->paymentId());
        static::assertSame('T1234567891234567891234568912345678', $event->rum()?->value());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testPaymentOrderedWithoutOptionalValues(): void
    {
        $event = new PaymentOrdered(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            null,
            PaymentSamples::period(),
            PaymentSamples::certificate(),
            null,
            null,
            PaymentSamples::instant(),
        );

        static::assertNull($event->accountId());
        static::assertNull($event->paymentId());
        static::assertNull($event->rum());
    }

    public function testPaymentRejected(): void
    {
        $event = new PaymentRejected(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            PaymentSamples::accountId(),
            PaymentSamples::period(),
            ['ER_1', 'ER_2'],
            PaymentSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertSame('account-1', $event->accountId()?->value());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame(['ER_1', 'ER_2'], $event->errorCodes());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testPaymentOutcomeUnknown(): void
    {
        $event = new PaymentOutcomeUnknown(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            PaymentSamples::reference(),
            null,
            PaymentSamples::period(),
            OutcomeUnknownReason::StaleSubmission,
            PaymentSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('payment-ref-1', $event->reference()->value());
        static::assertNull($event->accountId());
        static::assertSame('2026-10', $event->period()->toString());
        static::assertSame(OutcomeUnknownReason::StaleSubmission, $event->reason());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testPaymentSubmissionFailed(): void
    {
        $event = new PaymentSubmissionFailed(
            PaymentSamples::recordId('record-1'),
            PaymentSamples::tenantId(),
            ['ER_429'],
            PaymentSamples::instant(),
        );

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(['ER_429'], $event->errorCodes());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testPaymentCredentialsRejected(): void
    {
        $event = new PaymentCredentialsRejected(PaymentSamples::recordId('record-1'), PaymentSamples::tenantId(), PaymentSamples::instant());

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }

    public function testPaymentRequeued(): void
    {
        $event = new PaymentRequeued(PaymentSamples::recordId('record-1'), PaymentSamples::tenantId(), PaymentState::Error, PaymentSamples::instant());

        static::assertSame('record-1', $event->recordId()->value());
        static::assertSame('tenant-1', $event->tenantId()->value());
        static::assertSame(PaymentState::Error, $event->from());
        static::assertSame('2026-11-15T10:00:00+00:00', $event->occurredAt()->format('c'));
    }
}
