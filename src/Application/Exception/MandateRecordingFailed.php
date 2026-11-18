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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;

/**
 * URSSAF accepted method 030 or 031 but the local state could not be recorded (ADR 0009). It is the only exception that
 * means the call was accepted: recover without any call (ImportMandate, RecordMandateCancellation), never call the use
 * case again.
 *
 * The last failure is carried as the parameter "last_code" (its violation code, else its class name): the reference
 * exceptions have no previous exception (ADR 0000 amendment).
 */
final class MandateRecordingFailed extends DomainException
{
    public static function after(Operation $operation, int $attempts, string $lastCode): self
    {
        return new self(
            \sprintf(
                'URSSAF accepted method %s but the local state could not be recorded after %d attempt(s): record it without any call (ImportMandate / RecordMandateCancellation, ADR 0009).',
                $operation->value,
                $attempts,
            ),
            'mandate.recording_failed',
            ['operation' => $operation->value, 'attempts' => $attempts, 'last_code' => $lastCode],
        );
    }
}
