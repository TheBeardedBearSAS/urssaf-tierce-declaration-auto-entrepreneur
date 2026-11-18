<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Submission\SubmissionError;

/**
 * Read model of an error stored with a submission (ADR 0010): the code and the kind are plain, the URSSAF message and
 * description are masked when the view is dumped.
 */
final readonly class SubmissionErrorView
{
    private function __construct(
        public string $code,
        public string $kind,
        #[SensitiveParameter]
        public ?string $message,
        #[SensitiveParameter]
        public ?string $description,
    ) {}

    /**
     * @param list<SubmissionError> $errors
     *
     * @return list<self>
     */
    public static function fromErrors(array $errors): array
    {
        return array_map(
            static fn(SubmissionError $error): self => new self($error->code(), $error->kind(), $error->message(), $error->description()),
            $errors,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'code' => $this->code,
            'kind' => $this->kind,
            'message' => $this->message === null ? null : '***',
            'description' => $this->description === null ? null : '***',
        ];
    }
}
