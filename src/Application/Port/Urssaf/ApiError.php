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

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;

/**
 * Error item returned by URSSAF: {"code": "...", "message": "...", "description": "..."} (api-reference §1.3). Kept
 * verbatim (French); message and description may be absent. Both may echo host values (personal data): they are masked
 * in debug output, never put in an exception message and never logged (ADR 0010).
 */
final readonly class ApiError
{
    private const MASK = '***';

    private function __construct(
        private string $code,
        #[SensitiveParameter]
        private ?string $message,
        #[SensitiveParameter]
        private ?string $description,
    ) {}

    /**
     * @throws InvariantViolation "api_error.empty_code"
     */
    public static function of(string $code, #[SensitiveParameter] ?string $message, #[SensitiveParameter] ?string $description): self
    {
        $trimmed = trim($code);
        if ($trimmed === '') {
            throw InvariantViolation::because('api_error.empty_code', 'An URSSAF error must carry a code.');
        }

        return new self($trimmed, $message, $description);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function hasCode(string $code): bool
    {
        return $this->code === $code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code
            && $this->message === $other->message
            && $this->description === $other->description;
    }

    /**
     * @return array{code: string, message: string|null, description: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message === null ? null : self::MASK,
            'description' => $this->description === null ? null : self::MASK,
        ];
    }
}
