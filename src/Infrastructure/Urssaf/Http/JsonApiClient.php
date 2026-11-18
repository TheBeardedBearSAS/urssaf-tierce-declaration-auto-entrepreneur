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

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Credentials;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpHeader;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorClassifier;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorPayloadParser;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\QueryEncoding;

/**
 * Authenticated JSON transport over PSR-18 (ADR 0002, ADR 0003, ADR 0006, ADR 0010, ADR 0012, ADR 0013). Build the
 * gateway with {@see \TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafGatewayFactory}.
 *
 * Per attempt: the tenant's rate limiter, bearer token, optional request-id header (a new id per attempt) and
 * optional application-id header. A 401 invalidates the cached token and the call is replayed exactly once, for every
 * operation (a 401 proves the call was not processed). The statuses are then mapped to the gateway exceptions; on an
 * operation that is not safe to retry (see {@see ApiConfiguration::isSafeToRetry()}) a transport error or any 5xx
 * becomes an {@see OutcomeUnknown}. A 429 stays a retryable {@see UrssafUnavailable} for every operation.
 *
 * A 2xx answer is returned raw: the gateway decodes it and turns an unreadable one into an unknown outcome when the
 * operation is not a read.
 *
 * Neither the token, the URI, the path, the query nor the request / response bodies are logged or put in an
 * exception message (ADR 0010). The tenant id is not personal data (ADR 0012); the SIRET is logged only when
 * {@see ApiConfiguration::logSiret()} is enabled.
 *
 * The status table of ADR 0002 / 0003 is one decision per branch, hence the complexity.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class JsonApiClient
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private OAuth2TokenProvider $tokenProvider,
        private RateLimiter $rateLimiter,
        private IdGenerator $idGenerator,
        private Clock $clock,
        private ApiConfiguration $configuration,
        private LoggerInterface $logger = new NullLogger(),
        private ErrorPayloadParser $errorParser = new ErrorPayloadParser(),
    ) {}

    /**
     * Sends one call (plus one replay after a 401) and returns the raw 2xx answer; the caller decodes it.
     *
     * @throws RateLimitExceeded          local limiter (before any HTTP call)
     * @throws UrssafRequestRejected      HTTP 400, 404 and 409 where they are definitive rejections, classified by
     *                                    {@see ErrorClassifier::classify()}
     * @throws UrssafAuthenticationFailed 401 after the replay, 403; token 400 / 401 / 403
     * @throws UrssafUnavailable          429; 500 / 502 / 503 / 504 and transport errors of operations safe to retry;
     *                                    token failures
     * @throws OutcomeUnknown             operations not safe to retry: transport error, any 5xx
     * @throws UnexpectedUrssafResponse   any other status; token response unreadable
     */
    public function send(TenantId $tenantId, Credentials $credentials, ApiRequest $request): ApiResponse
    {
        [$response, $requestId] = $this->attempt($tenantId, $credentials, $request);

        if ($response->getStatusCode() === 401) {
            $this->tokenProvider->invalidate($credentials);
            $this->logger->info('URSSAF call answered 401: token invalidated, replaying once.', [
                'tenant' => $tenantId->value(),
                'operation' => $request->operation()->value,
            ]);
            [$response, $requestId] = $this->attempt($tenantId, $credentials, $request);
        }

        return $this->interpret($tenantId, $request->operation(), $response, $requestId);
    }

    /**
     * @return array{ResponseInterface, string|null} the response and the request id of this attempt
     */
    private function attempt(TenantId $tenantId, Credentials $credentials, ApiRequest $request): array
    {
        $operation = $request->operation();
        $this->rateLimiter->acquire($tenantId, $operation);

        $token = $this->tokenProvider->accessToken($credentials, $operation);
        $requestIdHeaderName = $this->configuration->requestIdHeaderName();
        $requestIdHeader = $requestIdHeaderName === null
            ? null
            : HttpHeader::of($requestIdHeaderName, $this->idGenerator->generate());
        $requestId = $requestIdHeader?->value();
        $httpRequest = $this->buildRequest($request, $token, $requestIdHeader);
        $context = [
            'tenant' => $tenantId->value(),
            'operation' => $operation->value,
            'method' => $request->method(),
        ];
        $startedAt = $this->clock->now();

        try {
            $response = $this->httpClient->sendRequest($httpRequest);
        } catch (ClientExceptionInterface $exception) {
            $this->logger->warning('URSSAF call failed: transport error.', $this->decorate($context + [
                'duration_ms' => Duration::millisecondsSince($startedAt, $this->clock->now()),
                'exception' => $exception::class,
            ], $request, $requestId));

            throw $this->transportFailure($operation, $exception);
        }

        $this->logger->info('URSSAF call.', $this->decorate($context + [
            'status' => $response->getStatusCode(),
            'duration_ms' => Duration::millisecondsSince($startedAt, $this->clock->now()),
        ], $request, $requestId));

        return [$response, $requestId];
    }

    /**
     * A transport error may have reached URSSAF: retryable on an operation safe to retry, an unknown outcome
     * otherwise. The original exception is never chained: its message holds the URI.
     */
    private function transportFailure(
        Operation $operation,
        ClientExceptionInterface $exception,
    ): UrssafUnavailable|OutcomeUnknown {
        $unavailable = UrssafUnavailable::dueToTransportError($operation, TransportError::fromClientException($exception));

        return $this->configuration->isSafeToRetry($operation) ? $unavailable : OutcomeUnknown::fromUnavailable($unavailable);
    }

    /**
     * @param array<string, int|string> $context
     *
     * @return array<string, int|string>
     */
    private function decorate(array $context, ApiRequest $request, ?string $requestId): array
    {
        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        $subject = $request->subject();
        if ($subject instanceof Siret && $this->configuration->logSiret()) {
            $context['siret'] = $subject->toString();
        }

        return $context;
    }

    private function buildRequest(
        ApiRequest $request,
        #[SensitiveParameter]
        string $token,
        ?HttpHeader $requestIdHeader,
    ): RequestInterface {
        $uri = $this->configuration->apiUri($request->path());
        if ($request->query() !== []) {
            $uri .= '?' . QueryEncoding::build($request->query());
        }

        $httpRequest = $this->requestFactory
            ->createRequest($request->method(), $uri)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->configuration->userAgent());

        if ($requestIdHeader instanceof HttpHeader) {
            $httpRequest = $httpRequest->withHeader($requestIdHeader->name(), $requestIdHeader->value());
        }

        $applicationId = $this->configuration->applicationIdHeader();
        if ($applicationId instanceof HttpHeader) {
            $httpRequest = $httpRequest->withHeader($applicationId->name(), $applicationId->value());
        }

        $body = $request->jsonBody();
        if ($body !== null) {
            $httpRequest = $httpRequest
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        return $httpRequest;
    }

    private function interpret(
        TenantId $tenantId,
        Operation $operation,
        ResponseInterface $response,
        ?string $requestId,
    ): ApiResponse {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 200 && $status <= 299) {
            return new ApiResponse($status, $body, $requestId);
        }

        $errors = $this->errorParser->parseBody($body);
        $this->logger->warning('URSSAF call failed.', [
            'tenant' => $tenantId->value(),
            'operation' => $operation->value,
            'status' => $status,
            'error_codes' => $errors->codes(),
        ]);

        throw $this->failure($operation, $response, $errors);
    }

    /**
     * The exception of a non-2xx answer (status table of ADR 0002 / 0003).
     */
    private function failure(
        Operation $operation,
        ResponseInterface $response,
        ApiErrors $errors,
    ): UrssafRequestRejected|UrssafAuthenticationFailed|UrssafUnavailable|OutcomeUnknown|UnexpectedUrssafResponse {
        $status = $response->getStatusCode();

        if (ErrorClassifier::rejects($operation, $status)) {
            return UrssafRequestRejected::withErrors(
                $operation,
                $status,
                $errors,
                ErrorClassifier::classify($operation, $status, $errors),
            );
        }

        if ($status === 401 || $status === 403) {
            return UrssafAuthenticationFailed::withStatus($status);
        }

        if ($status === 429) {
            return UrssafUnavailable::withStatus($operation, $status, RetryAfter::seconds($response), $errors);
        }

        if ($status >= 500 && $status <= 599) {
            return $this->serverFailure($operation, $response, $errors);
        }

        return UnexpectedUrssafResponse::unexpectedStatus($status);
    }

    private function serverFailure(
        Operation $operation,
        ResponseInterface $response,
        ApiErrors $errors,
    ): UrssafUnavailable|OutcomeUnknown|UnexpectedUrssafResponse {
        $status = $response->getStatusCode();
        $safe = $this->configuration->isSafeToRetry($operation);

        if (!$safe || \in_array($status, [500, 502, 503, 504], true)) {
            $unavailable = UrssafUnavailable::withStatus($operation, $status, RetryAfter::seconds($response), $errors);

            return $safe ? $unavailable : OutcomeUnknown::fromUnavailable($unavailable);
        }

        return UnexpectedUrssafResponse::unexpectedStatus($status);
    }
}
