<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http;

use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonDecoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\Payload;

/**
 * Successful (2xx) answer of an API call. The body is kept raw and decoded lazily, so that the gateway (not the
 * client) decides what an unreadable 2xx means: on an operation that is not safe to read again it is an unknown
 * outcome (ADR 0003), the gateway catches the {@see UnexpectedUrssafResponse} of {@see self::payload()} and of the
 * response mappers.
 *
 * @internal
 */
final readonly class ApiResponse
{
    public function __construct(
        private int $status,
        #[SensitiveParameter]
        private string $body,
        private ?string $requestId,
    ) {}

    /**
     * The 2xx status actually received.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * No payload at all (ADR 0002): an empty or blank body (030 and 031 answer an empty 200).
     */
    public function isEmpty(): bool
    {
        return trim($this->body) === '';
    }

    /**
     * The raw body (personal data: never log it).
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * @throws UnexpectedUrssafResponse when the body is not valid JSON
     */
    public function payload(): Payload
    {
        return JsonDecoder::decode($this->body);
    }

    /**
     * The request id sent with the last attempt, when a request-id header is configured.
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array{status: int, body: string, requestId: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'body' => $this->body === '' ? '' : '***',
            'requestId' => $this->requestId,
        ];
    }
}
