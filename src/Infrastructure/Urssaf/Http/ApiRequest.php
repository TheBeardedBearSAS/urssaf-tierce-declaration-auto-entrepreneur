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

use JsonException;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafEndpoints;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonEncoder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\JsonObject;

/**
 * Description of one API call, independent from the HTTP library (ADR 0002): GET, DELETE (query only) or POST with a
 * query and / or a JSON body.
 *
 * Queries carry the SIRET and the RUM, bodies carry bank details and birth dates (ADR 0010): the factories mark them
 * sensitive and {@see self::__debugInfo()} never shows their values. The path holds the SIRET and the RUM of 071 as
 * well, so it is never logged either. The SIRET subject ({@see self::about()}) only serves the optional `logSiret`
 * log line of the client.
 *
 * @internal
 */
final readonly class ApiRequest
{
    /**
     * @param array<string, string> $query
     */
    private function __construct(
        private Operation $operation,
        private string $method,
        private string $path,
        #[SensitiveParameter]
        private array $query,
        #[SensitiveParameter]
        private ?string $jsonBody,
        private ?Siret $subject,
    ) {}

    /**
     * @param array<string, string> $query
     */
    public static function get(Operation $operation, string $path, #[SensitiveParameter] array $query = []): self
    {
        return new self($operation, 'GET', $path, $query, null, null);
    }

    /**
     * @param array<string, string>              $query
     * @param array<array-key, mixed>|JsonObject $body
     *
     * @throws InvariantViolation "json_encoder.unsupported_value"
     * @throws JsonException      for invalid UTF-8 strings
     */
    public static function post(
        Operation $operation,
        string $path,
        #[SensitiveParameter]
        array $query,
        #[SensitiveParameter]
        array|JsonObject $body,
    ): self {
        return new self($operation, 'POST', $path, $query, JsonEncoder::encode($body), null);
    }

    /**
     * A POST without body (030).
     *
     * @param array<string, string> $query
     */
    public static function postWithoutBody(
        Operation $operation,
        string $path,
        #[SensitiveParameter]
        array $query = [],
    ): self {
        return new self($operation, 'POST', $path, $query, null, null);
    }

    /**
     * @param array<string, string> $query
     */
    public static function delete(Operation $operation, string $path, #[SensitiveParameter] array $query = []): self
    {
        return new self($operation, 'DELETE', $path, $query, null, null);
    }

    /**
     * A copy carrying the SIRET the call is about (logged only when `logSiret` is enabled, ADR 0010).
     */
    public function about(#[SensitiveParameter] Siret $siret): self
    {
        return new self($this->operation, $this->method, $this->path, $this->query, $this->jsonBody, $siret);
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    public function method(): string
    {
        return $this->method;
    }

    /**
     * Relative to {@see UrssafEndpoints::API_BASE_PATH}, already percent-encoded by the wire layer.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function jsonBody(): ?string
    {
        return $this->jsonBody;
    }

    public function subject(): ?Siret
    {
        return $this->subject;
    }

    /**
     * @return array{operation: string, method: string, path: string, query: list<int|string>, jsonBody: string|null, subject: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'operation' => $this->operation->value,
            'method' => $this->method,
            'path' => '***',
            'query' => array_keys($this->query),
            'jsonBody' => $this->jsonBody === null ? null : '***',
            'subject' => $this->subject instanceof Siret ? '***' : null,
        ];
    }
}
