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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;

/**
 * Thrown by DeclareTurnover when URSSAF accepted 050 but the accepted result could not be recorded (ADR 0009, settlement
 * table): the record was erased (ForgetAccount), or the save failed for another reason (storage, outbox) or kept meeting
 * conflicts after the bounded retry. The call is made: never send it again.
 *
 * Recovery: run ReconcileStaleDeclarations (the record then leaves "submitting"), then ResolveDeclarationOutcome::asDeclared()
 * with {@see self::certificate()} (the `certificat` of the answer). With an erased record there is nothing to recover
 * locally.
 *
 * Violation code: "declaration_record.recording_failed". Parameters: the record id, the number of attempts made, the
 * violation code of the last failure (the class name of a failure that carries none), the `certificat` and, when there
 * is one, the `idDeclaration`. None of them is personal data (ADR 0014 item 3). The last failure is not kept as the
 * previous exception: application exceptions have none (ADR 0000 amendment).
 */
final class DeclarationRecordingFailed extends DomainException
{
    /**
     * @param array<string, scalar> $params
     */
    private function __construct(
        string $message,
        string $code,
        array $params,
        private readonly string $certificate,
        private readonly ?string $declarationId,
    ) {
        parent::__construct($message, $code, $params);
    }

    public static function after(DeclarationRecordId $recordId, DeclarationResult $accepted, int $attempts, string $lastCode): self
    {
        $certificate = $accepted->certificate()->value();
        $declarationId = $accepted->declarationId();
        $params = [
            'record_id' => $recordId->value(),
            'attempts' => $attempts,
            'last_code' => $lastCode,
            'certificat' => $certificate,
        ];
        if ($declarationId !== null) {
            $params['id_declaration'] = $declarationId;
        }

        return new self(
            \sprintf(
                'URSSAF accepted the declaration but the result could not be recorded after %d attempt(s): never send it again, reconcile then resolve it as declared with the certificat (ADR 0009).',
                $attempts,
            ),
            'declaration_record.recording_failed',
            $params,
            $certificate,
            $declarationId,
        );
    }

    /**
     * The `certificat` URSSAF answered.
     */
    public function certificate(): string
    {
        return $this->certificate;
    }

    /**
     * The `idDeclaration` URSSAF answered, null for a result without one.
     */
    public function declarationId(): ?string
    {
        return $this->declarationId;
    }
}
