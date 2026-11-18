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

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\JsonApiClient;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafHttpGateway;

/**
 * Wires the gateway on a {@see Stack} and calls each operation with the {@see WireSamples} inputs.
 *
 * @mago-expect lint:too-many-methods
 */
final class Gateways
{
    private function __construct() {}

    public static function gateway(Stack $stack): UrssafHttpGateway
    {
        return self::wired($stack, $stack->credentials, $stack->jsonClient());
    }

    public static function withCredentials(Stack $stack, CredentialsProvider $credentials): UrssafHttpGateway
    {
        return self::wired($stack, $credentials, $stack->jsonClient());
    }

    public static function withRateLimiter(Stack $stack, RateLimiter $rateLimiter): UrssafHttpGateway
    {
        return self::wired($stack, $stack->credentials, $stack->jsonClientWith($rateLimiter));
    }

    /**
     * A sandbox stack whose token is already cached, so that the next queued answer is the one of the API call.
     */
    public static function warm(): Stack
    {
        return self::warmWith(ApiConfiguration::forEnvironment(Environment::Sandbox));
    }

    public static function warmWith(ApiConfiguration $configuration): Stack
    {
        $stack = Stacks::configured($configuration);
        $stack->warmUpToken();

        return $stack;
    }

    /**
     * Calls the operation with the samples; the result of the writes that answer nothing is null.
     */
    public static function call(UrssafHttpGateway $gateway, Operation $operation): mixed
    {
        $tenant = Stacks::tenant();
        $siret = WireSamples::siret();

        return match ($operation) {
            Operation::FindAccount => $gateway->findAccount($tenant, $siret),
            Operation::NotifyMandate => self::notify($gateway),
            Operation::CancelMandate => self::cancel($gateway),
            Operation::Estimate => $gateway->estimateContributions($tenant, $siret, WireSamples::turnover()),
            Operation::Declare => $gateway->declareTurnover($tenant, $siret, WireSamples::declaration()),
            Operation::Pay => $gateway->payContributions($tenant, $siret, WireSamples::paymentOrder()),
            Operation::RegisterSepaMandate => $gateway->registerSepaMandate($tenant, $siret, WireSamples::newSepaMandate()),
            Operation::RevokeSepaMandate => self::revoke($gateway),
            Operation::ListSepaMandates => $gateway->listSepaMandates($tenant, $siret),
        };
    }

    /**
     * Queues the official success answer of the operation.
     */
    public static function respondSuccess(Stack $stack, Operation $operation): void
    {
        match ($operation) {
            Operation::FindAccount => $stack->respond(200, Fixtures::load('account_response')),
            Operation::NotifyMandate, Operation::CancelMandate => $stack->respond(200, ''),
            Operation::Estimate => $stack->respond(200, Fixtures::load('estimate_response')),
            Operation::Declare => $stack->respond(200, Fixtures::load('declare_response')),
            Operation::Pay => $stack->respond(200, Fixtures::load('pay_response')),
            Operation::RegisterSepaMandate => $stack->respond(200, Fixtures::load('sepa_mandate_response')),
            Operation::RevokeSepaMandate => $stack->respond(204, ''),
            Operation::ListSepaMandates => $stack->respond(200, Fixtures::load('sepa_mandates_search_response')),
        };
    }

    private static function wired(Stack $stack, CredentialsProvider $credentials, JsonApiClient $client): UrssafHttpGateway
    {
        return new UrssafHttpGateway($credentials, $client, $stack->logger);
    }

    private static function notify(UrssafHttpGateway $gateway): null
    {
        $gateway->notifyMandate(Stacks::tenant(), WireSamples::accountReference());

        return null;
    }

    private static function cancel(UrssafHttpGateway $gateway): null
    {
        $gateway->cancelMandate(Stacks::tenant(), WireSamples::accountReference());

        return null;
    }

    private static function revoke(UrssafHttpGateway $gateway): null
    {
        $gateway->revokeSepaMandate(Stacks::tenant(), WireSamples::revocation());

        return null;
    }
}
