<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Exception;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentAlreadyRecorded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\PaymentRecordingFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\PaymentWorld;

/**
 * The payment exceptions: codes, parameters and messages, none of which holds a SIRET, an amount or bank data (ADR 0010).
 */
final class PaymentExceptionsTest extends TestCase
{
    public function testAnAlreadyRecordedReferenceIsRefusedWithoutPersonalData(): void
    {
        $refusal = PaymentAlreadyRecorded::withReference(PaymentWorld::reference(), PaymentRecordId::fromString('record-1'));

        static::assertSame('payment_record.already_recorded', $refusal->violationCode());
        static::assertSame(['payment_reference' => 'pay-1', 'payment_record_id' => 'record-1'], $refusal->params());
        static::assertSame(
            'The payment reference is already recorded with another payment content: use a new payment reference for another payment (ADR 0009).',
            $refusal->getMessage(),
        );
        static::assertNull($refusal->getPrevious());
    }

    public function testARecordingFailureCarriesTheCertificatAndThePaymentId(): void
    {
        $failure = PaymentRecordingFailed::after(PaymentRecordId::fromString('record-1'), PaymentWorld::paid(), 3, 'payment_record.concurrent_modification');

        static::assertSame('payment_record.recording_failed', $failure->violationCode());
        static::assertSame(
            ['record_id' => 'record-1', 'attempts' => 3, 'last_code' => 'payment_record.concurrent_modification', 'certificat' => '12345678', 'id_telepaiement' => 'W1dl9W2Oa4R6ab3UlQAqxF2'],
            $failure->params(),
        );
        static::assertSame('12345678', $failure->certificate());
        static::assertSame('W1dl9W2Oa4R6ab3UlQAqxF2', $failure->paymentId());
        static::assertSame(
            'URSSAF accepted the payment but the result could not be recorded after 3 attempt(s): the payment was ordered: never order it again, reconcile then resolve it as paid with the certificat (ADR 0009).',
            $failure->getMessage(),
        );
        static::assertNull($failure->getPrevious());
        static::assertStringNotContainsString('1500', $failure->getMessage());
        static::assertStringNotContainsString(PaymentWorld::SIRET, $failure->getMessage());
    }

    public function testARecordingFailureWithoutPaymentIdHasNoPaymentIdParameter(): void
    {
        $failure = PaymentRecordingFailed::after(PaymentRecordId::fromString('record-1'), PaymentWorld::paid('87654321', null), 1, 'RuntimeException');

        static::assertSame(['record_id' => 'record-1', 'attempts' => 1, 'last_code' => 'RuntimeException', 'certificat' => '87654321'], $failure->params());
        static::assertNull($failure->paymentId());
        static::assertSame('87654321', $failure->certificate());
        static::assertStringContainsString('after 1 attempt(s)', $failure->getMessage());
    }
}
