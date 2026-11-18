<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf;

use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OperationRetryClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\OutcomeUnknownReason;
use Throwable;

/**
 * A write that is never resent by default ({@see OperationRetryClass}) may have been processed by URSSAF, but the
 * answer is lost, a 5xx or unreadable (ADR 0003). It must not be resent automatically: an operator reconciles it.
 * Deliberately not a {@see RetryableException}.
 *
 * The message holds the operation number and the cause only (ADR 0010).
 */
final class OutcomeUnknown extends RuntimeException implements UrssafException
{
    private function __construct(
        string $message,
        private readonly Operation $operation,
        private readonly OutcomeUnknownCause $cause,
        private readonly ?int $httpStatus,
        private readonly ApiErrors $errors,
        Throwable $previous,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * Converts a transient failure that may have reached URSSAF: a transport error or a 5xx.
     *
     * @throws InvariantViolation "outcome_unknown.not_reached" when the failure cannot have reached URSSAF
     */
    public static function fromUnavailable(UrssafUnavailable $cause): self
    {
        if (!$cause->mayHaveReachedUrssaf()) {
            throw InvariantViolation::because(
                'outcome_unknown.not_reached',
                'Only a failure that may have reached URSSAF has an unknown outcome.',
            );
        }

        $status = $cause->httpStatus();
        $kind = $status === null ? OutcomeUnknownCause::Transport : OutcomeUnknownCause::ServerError;

        return new self(
            self::describe($cause->operation(), $kind),
            $cause->operation(),
            $kind,
            $status,
            $cause->errors(),
            $cause,
        );
    }

    /**
     * The write was answered 2xx but the answer could not be read: URSSAF processed it.
     */
    public static function afterUnreadableResponse(Operation $operation, int $httpStatus, UrssafException $cause): self
    {
        return new self(
            self::describe($operation, OutcomeUnknownCause::UnreadableResponse),
            $operation,
            OutcomeUnknownCause::UnreadableResponse,
            $httpStatus,
            ApiErrors::none(),
            $cause,
        );
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    public function cause(): OutcomeUnknownCause
    {
        return $this->cause;
    }

    /**
     * Null when no HTTP answer was received (transport error).
     */
    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function errors(): ApiErrors
    {
        return $this->errors;
    }

    /**
     * Always true: that is the definition of an unknown outcome.
     */
    public function mayHaveReachedUrssaf(): true
    {
        return true;
    }

    /**
     * The reason stored on the declaration or payment record (ADR 0003).
     */
    public function recordReason(): OutcomeUnknownReason
    {
        return OutcomeUnknownReason::MayHaveReachedUrssaf;
    }

    private static function describe(Operation $operation, OutcomeUnknownCause $cause): string
    {
        return \sprintf(
            'The outcome of URSSAF method %s is unknown (%s): the call may have been processed and must not be resent automatically.',
            $operation->value,
            $cause->value,
        );
    }
}
