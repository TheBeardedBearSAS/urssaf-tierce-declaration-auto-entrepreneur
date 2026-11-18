<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;

/**
 * Thrown by PayContributions when URSSAF accepted 060 but the accepted result could not be recorded (ADR 0009, settlement
 * table): the record was erased (ForgetAccount), or the save failed for another reason (storage, outbox) or kept meeting
 * conflicts after the bounded retry. The payment was ordered: never order it again.
 *
 * Recovery: run ReconcileStalePayments (the record then leaves "submitting"), then
 * ResolvePaymentOutcome::asPaid() with {@see self::certificate()}. With an erased record there is nothing to recover
 * locally.
 *
 * Violation code: "payment_record.recording_failed". Parameters: the record id, the number of attempts made, the last
 * failure ("last_code": its violation code, the class name of a failure that carries none), the certificat and, when
 * there is one, the payment id as "id_telepaiement". These are URSSAF technical ids, not personal data (ADR 0014 item 3);
 * the amount and the bank data never appear.
 */
final class PaymentRecordingFailed extends DomainException
{
    /**
     * @param array<string, scalar> $params
     */
    private function __construct(
        string $message,
        string $code,
        array $params,
        private readonly string $certificate,
        private readonly ?string $paymentId,
    ) {
        parent::__construct($message, $code, $params);
    }

    public static function after(PaymentRecordId $recordId, PaymentResult $accepted, int $attempts, string $lastCode): self
    {
        $certificate = $accepted->certificate()->value();
        $paymentId = $accepted->paymentId();
        $params = [
            'record_id' => $recordId->value(),
            'attempts' => $attempts,
            'last_code' => $lastCode,
            'certificat' => $certificate,
        ];
        if ($paymentId !== null) {
            $params['id_telepaiement'] = $paymentId;
        }

        return new self(
            \sprintf(
                'URSSAF accepted the payment but the result could not be recorded after %d attempt(s): the payment was ordered: never order it again, reconcile then resolve it as paid with the certificat (ADR 0009).',
                $attempts,
            ),
            'payment_record.recording_failed',
            $params,
            $certificate,
            $paymentId,
        );
    }

    /**
     * The certificat URSSAF answered.
     */
    public function certificate(): string
    {
        return $this->certificate;
    }

    /**
     * The `idTelepaiement` URSSAF answered, null when the answer had none.
     */
    public function paymentId(): ?string
    {
        return $this->paymentId;
    }
}
