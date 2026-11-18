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

use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;

/**
 * Thrown by the submission handlers after a temporary URSSAF failure (unavailable, quota exceeded, transport error):
 * the affected record was moved back to pending and the message must be retried later. The original failure (with its
 * retry-after delay when known) is the previous exception.
 */
final class RetryableUrssafFailure extends RuntimeException implements RetryableException
{
    public static function causedBy(RetryableException $previous): self
    {
        return new self('URSSAF is temporarily unavailable: the submission must be retried later.', 0, $previous);
    }
}
