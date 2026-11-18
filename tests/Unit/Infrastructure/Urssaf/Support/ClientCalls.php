<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\TransportFailure;
use Throwable;

/**
 * Runs one call of the client against a scripted answer and returns what came out of it.
 */
final class ClientCalls
{
    private function __construct() {}

    public static function stack(): Stack
    {
        return self::stackWith(ApiConfiguration::forEnvironment(Environment::Sandbox));
    }

    public static function stackWith(ApiConfiguration $configuration): Stack
    {
        $stack = Stacks::configured($configuration);
        $stack->warmUpToken();

        return $stack;
    }

    public static function send(Stack $stack, Operation $operation): ApiResponse
    {
        return $stack->jsonClient()->send(Stacks::tenant(), $stack->tenantCredentials(), ApiRequests::of($operation));
    }

    /**
     * The answer of the operation to one HTTP status on a sandbox stack, or the exception it raised.
     *
     * @param array<string, string> $headers
     */
    public static function answer(Operation $operation, int $status, string $body = '', array $headers = []): ApiResponse|Throwable
    {
        return self::answerWith(ApiConfiguration::forEnvironment(Environment::Sandbox), $operation, $status, $body, $headers);
    }

    /**
     * The status is queued twice around a token, for the replay of a 401.
     *
     * @param array<string, string> $headers
     */
    public static function answerWith(
        ApiConfiguration $configuration,
        Operation $operation,
        int $status,
        string $body = '',
        array $headers = [],
    ): ApiResponse|Throwable {
        $stack = self::stackWith($configuration);
        $stack->respond($status, $body, $headers);
        $stack->respondToken();
        $stack->respond($status, $body, $headers);

        try {
            return self::send($stack, $operation);
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    public static function transportFailure(Operation $operation): ApiResponse|Throwable
    {
        return self::transportFailureWith(ApiConfiguration::forEnvironment(Environment::Sandbox), $operation);
    }

    public static function transportFailureWith(ApiConfiguration $configuration, Operation $operation): ApiResponse|Throwable
    {
        $stack = self::stackWith($configuration);
        $stack->fail(new TransportFailure('timeout for https://h/sepa/mandats?siret=12345678912345'));

        try {
            return self::send($stack, $operation);
        } catch (Throwable $exception) {
            return $exception;
        }
    }
}
