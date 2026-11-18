<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OperationRetryClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\ApiResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Http\JsonApiClient;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\AccountQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\AccountResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ApiPaths;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\DeclarationResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EmptyResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EstimateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\EstimateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\MandateQuery;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\Payload;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\PaymentResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ResponseEnvelope;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateRequestMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaMandateResponseMapper;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\SepaRevocationQuery;

/**
 * HTTP implementation of the nine operations of the API Tierce Déclaration auto-entrepreneur (020 to 075). Build it with
 * {@see UrssafGatewayFactory}.
 *
 * Status-level failures (transport, 4xx, 5xx, authentication) are mapped by the {@see JsonApiClient} (ADR 0002, ADR 0003).
 * This class only decides what an unreadable 2xx answer means (ADR 0003): on a safe operation it is an
 * {@see UnexpectedUrssafResponse}; on every other operation, a write declared safe to retry included, URSSAF accepted
 * the call and a resend cannot help reading the answer, so it is an {@see OutcomeUnknown} that must not be resent.
 *
 * An unknown tenant raises a RecordNotFound before any limiter or HTTP call (ADR 0012).
 *
 * One method per operation of the {@see TierceDeclarationGateway} port, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final readonly class UrssafHttpGateway implements TierceDeclarationGateway
{
    public function __construct(
        private CredentialsProvider $credentials,
        private JsonApiClient $client,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function findAccount(TenantId $tenantId, Siret $siret): AccountStatus
    {
        $request = ApiRequest::get(Operation::FindAccount, ApiPaths::findAccount(), AccountQuery::of($siret))->about($siret);

        return $this->read(Operation::FindAccount, $this->send($tenantId, $request), AccountResponseMapper::map(...));
    }

    public function notifyMandate(TenantId $tenantId, #[SensitiveParameter] AccountReference $account): void
    {
        $request = ApiRequest::postWithoutBody(Operation::NotifyMandate, ApiPaths::notifyMandate(), MandateQuery::of($account))
            ->about($account->siret());
        $response = $this->send($tenantId, $request);

        $this->guard(Operation::NotifyMandate, $response, static function () use ($response): void {
            EmptyResponse::assertEmpty($response->body());
        });
    }

    public function cancelMandate(TenantId $tenantId, #[SensitiveParameter] AccountReference $account): void
    {
        $request = ApiRequest::delete(Operation::CancelMandate, ApiPaths::cancelMandate(), MandateQuery::of($account))
            ->about($account->siret());
        $response = $this->send($tenantId, $request);

        $this->guard(Operation::CancelMandate, $response, static function () use ($response): void {
            EmptyResponse::assertEmpty($response->body());
        });
    }

    public function estimateContributions(TenantId $tenantId, Siret $siret, Turnover $turnover): Estimate
    {
        $request = ApiRequest::post(
            Operation::Estimate,
            ApiPaths::estimateContributions(),
            AccountQuery::of($siret),
            EstimateRequestMapper::body($turnover),
        )->about($siret);

        return $this->readShaped(
            Operation::Estimate,
            $this->send($tenantId, $request),
            ResponseEnvelope::ESTIMATE_OR_DECLARATION,
            EstimateResponseMapper::map(...),
        );
    }

    public function declareTurnover(TenantId $tenantId, Siret $siret, TurnoverDeclaration $declaration): DeclarationResult
    {
        $request = ApiRequest::post(
            Operation::Declare,
            ApiPaths::declareTurnover(),
            AccountQuery::of($siret),
            DeclarationRequestMapper::body($declaration),
        )->about($siret);

        return $this->readShaped(
            Operation::Declare,
            $this->send($tenantId, $request),
            ResponseEnvelope::ESTIMATE_OR_DECLARATION,
            DeclarationResponseMapper::map(...),
        );
    }

    public function payContributions(TenantId $tenantId, Siret $siret, #[SensitiveParameter] PaymentOrder $order): PaymentResult
    {
        $request = ApiRequest::post(
            Operation::Pay,
            ApiPaths::payContributions(),
            AccountQuery::of($siret),
            PaymentRequestMapper::body($order),
        )->about($siret);

        return $this->readShaped(
            Operation::Pay,
            $this->send($tenantId, $request),
            ResponseEnvelope::PAYMENT,
            PaymentResponseMapper::map(...),
        );
    }

    public function registerSepaMandate(TenantId $tenantId, Siret $siret, #[SensitiveParameter] NewSepaMandate $mandate): SepaMandate
    {
        $request = ApiRequest::post(
            Operation::RegisterSepaMandate,
            ApiPaths::registerSepaMandate(),
            [],
            SepaMandateRequestMapper::body($siret, $mandate),
        )->about($siret);

        return $this->read(
            Operation::RegisterSepaMandate,
            $this->send($tenantId, $request),
            SepaMandateResponseMapper::mapRegistered(...),
        );
    }

    public function revokeSepaMandate(TenantId $tenantId, SepaRevocation $revocation): void
    {
        $request = ApiRequest::delete(
            Operation::RevokeSepaMandate,
            ApiPaths::revokeSepaMandate($revocation->siret(), $revocation->rum()),
            SepaRevocationQuery::of($revocation),
        )->about($revocation->siret());
        $response = $this->send($tenantId, $request);

        $this->guard(Operation::RevokeSepaMandate, $response, static function () use ($response): void {
            EmptyResponse::assertNoContent($response->body());
        });
    }

    public function listSepaMandates(TenantId $tenantId, Siret $siret): array
    {
        $request = ApiRequest::get(Operation::ListSepaMandates, ApiPaths::listSepaMandates(), AccountQuery::of($siret))
            ->about($siret);

        return $this->read(Operation::ListSepaMandates, $this->send($tenantId, $request), SepaMandateResponseMapper::mapList(...));
    }

    private function send(TenantId $tenantId, ApiRequest $request): ApiResponse
    {
        // The credentials come first: an unknown tenant fails before the limiter or any HTTP call (ADR 0012).
        return $this->client->send($tenantId, $this->credentials->forTenant($tenantId), $request);
    }

    /**
     * @template T
     *
     * @param-immediately-invoked-callable $map
     * @param callable(Payload): T $map
     *
     * @return T
     */
    private function read(Operation $operation, ApiResponse $response, callable $map): mixed
    {
        return $this->guard($operation, $response, static fn(): mixed => $map($response->payload()));
    }

    /**
     * Decodes, maps, logs the shape of the answer (debug) and returns, all inside the guard: bad JSON on 050 or 060 is
     * an unknown outcome.
     *
     * @template T
     *
     * @param-immediately-invoked-callable $map
     * @param callable(Payload): T $map
     *
     * @return T
     */
    private function readShaped(Operation $operation, ApiResponse $response, string $wrapperKey, callable $map): mixed
    {
        return $this->guard($operation, $response, function () use ($operation, $response, $wrapperKey, $map): mixed {
            $payload = $response->payload();
            $result = $map($payload);
            $this->logger->debug('URSSAF response shape.', [
                'operation' => $operation->value,
                'shape' => ResponseEnvelope::shapeOf($payload, $wrapperKey)->value,
            ]);

            return $result;
        });
    }

    /**
     * @template T
     *
     * @param-immediately-invoked-callable $decode
     * @param callable(): T $decode
     *
     * @return T
     *
     * @throws UnexpectedUrssafResponse on a safe operation
     * @throws OutcomeUnknown           on every other class, a write declared safe to retry included
     */
    private function guard(Operation $operation, ApiResponse $response, callable $decode): mixed
    {
        try {
            return $decode();
        } catch (UnexpectedUrssafResponse $unreadable) {
            if ($operation->retryClass() === OperationRetryClass::Safe) {
                throw $unreadable;
            }

            throw OutcomeUnknown::afterUnreadableResponse($operation, $response->status(), $unreadable);
        }
    }
}
