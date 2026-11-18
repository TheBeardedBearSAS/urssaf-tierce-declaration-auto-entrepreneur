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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\DomainException;

/**
 * ForgetAccount refused an erasure that would break a workflow guarantee: nothing was erased (ADR 0017).
 *
 * The parameter "account_id" is present only when an account is tracked (violation parameters are scalar); "count" is the
 * number of items that block the erasure. A SIRET is never carried (ADR 0010 item 5).
 */
final class AccountErasureRefused extends DomainException
{
    public static function activeMandate(?AccountId $accountId, int $count): self
    {
        return new self(
            'The auto-entrepreneur still has an active mandate: cancel it first (method 031) or allow its erasure explicitly.',
            'ae_account.forget_active_mandate',
            self::parameters($accountId, $count),
        );
    }

    public static function submissionInFlight(?AccountId $accountId, int $count): self
    {
        return new self(
            \sprintf('%d declaration(s) or payment(s) of the auto-entrepreneur are being submitted: wait for their outcome, then erase the auto-entrepreneur.', $count),
            'ae_account.forget_submission_in_flight',
            self::parameters($accountId, $count),
        );
    }

    public static function unknownOutcomes(?AccountId $accountId, int $count): self
    {
        return new self(
            \sprintf('%d declaration(s) or payment(s) of the auto-entrepreneur have an unknown outcome: resolve them first or allow their erasure explicitly.', $count),
            'ae_account.forget_unknown_outcome',
            self::parameters($accountId, $count),
        );
    }

    /**
     * @return array<string, int|string>
     */
    private static function parameters(?AccountId $accountId, int $count): array
    {
        return $accountId instanceof AccountId
            ? ['account_id' => $accountId->value(), 'count' => $count]
            : ['count' => $count];
    }
}
