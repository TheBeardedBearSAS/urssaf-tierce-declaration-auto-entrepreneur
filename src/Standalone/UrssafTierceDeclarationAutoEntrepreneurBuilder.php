<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Standalone;

use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\EventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\FailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredFailures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\NullEventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\RandomUuidGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Id\SecureRandomBytes;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryWorkflowStatistics;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\GatewayOptions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafGatewayFactory;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UtcSystemClock;

/**
 * Wires the package without a framework. Every "with" method returns a new builder.
 *
 * Defaults: in-memory repositories, statistics and token store (tests and prototypes: give your own repositories with
 * withRepositories(), and a shared token store in production), an immediate transaction manager (no rollback), the UTC
 * system clock, random UUIDs, no event publisher, no failure reporter, no outbox, the HTTP gateway of
 * {@see UrssafGatewayFactory}, and a workflow policy whose business time zone is the one of the ApiConfiguration (one
 * setting, two readers, ADR 0011).
 *
 * There is no withDoctrine() yet: it comes with the Doctrine bridge (lot 6). Until then give your own repositories and
 * transaction manager with withRepositories().
 *
 * One property and one "with" method per collaborator, hence the counts.
 *
 * @mago-expect lint:too-many-properties
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:excessive-parameter-list
 */
final class UrssafTierceDeclarationAutoEntrepreneurBuilder
{
    private const GATEWAY_REFUSED_IN_PRODUCTION = 'A custom gateway (a simulator) is refused with the production environment: use the HTTP gateway, or wire the handlers yourself.';

    private ?TierceDeclarationGateway $gateway = null;

    private TokenStore $tokenStore;

    private Clock $clock;

    private IdGenerator $ids;

    private EventPublisher $events;

    private FailureReporter $failureReporter;

    private ?LoggerInterface $logger = null;

    private ?RateLimiter $rateLimiter = null;

    /** @var array{limit: int, window: int}|null */
    private ?array $rateLimit = null;

    private WorkflowPolicy $policy;

    private AeAccountRepository $accounts;

    private MandateRepository $mandates;

    private DeclarationRecordRepository $declarations;

    private PaymentRecordRepository $payments;

    private TransactionManager $transactions;

    private ?WorkflowStatistics $statistics;

    private Outbox $outbox;

    private ?IntegrationEventSink $sink = null;

    private function __construct(
        private readonly HttpTransport $transport,
        private readonly CredentialsProvider $credentials,
        private readonly ApiConfiguration $configuration,
    ) {
        $this->tokenStore = new InMemoryTokenStore();
        $this->clock = new UtcSystemClock();
        $this->ids = new RandomUuidGenerator(new SecureRandomBytes());
        $this->events = new NullEventPublisher();
        $this->failureReporter = new IgnoredFailures();
        $this->policy = new WorkflowPolicy($configuration->businessTimeZone());
        $accounts = new InMemoryAeAccountRepository();
        $mandates = new InMemoryMandateRepository();
        $declarations = new InMemoryDeclarationRecordRepository();
        $payments = new InMemoryPaymentRecordRepository();
        $this->accounts = $accounts;
        $this->mandates = $mandates;
        $this->declarations = $declarations;
        $this->payments = $payments;
        $this->transactions = new ImmediateTransactionManager();
        $this->statistics = new InMemoryWorkflowStatistics($mandates, $declarations, $payments);
        $this->outbox = new IgnoredOutbox();
    }

    public static function create(HttpTransport $transport, CredentialsProvider $credentials, ApiConfiguration $configuration): self
    {
        return new self($transport, $credentials, $configuration);
    }

    /**
     * A token store shared by every process that calls URSSAF (PSR-6 / PSR-16 store).
     */
    public function withTokenStore(TokenStore $tokenStore): self
    {
        return $this->with(static function (self $builder) use ($tokenStore): void {
            $builder->tokenStore = $tokenStore;
        });
    }

    public function withClock(Clock $clock): self
    {
        return $this->with(static function (self $builder) use ($clock): void {
            $builder->clock = $clock;
        });
    }

    public function withIdGenerator(IdGenerator $ids): self
    {
        return $this->with(static function (self $builder) use ($ids): void {
            $builder->ids = $ids;
        });
    }

    /**
     * Where the domain events are published after each commit.
     */
    public function withEventPublisher(EventPublisher $events): self
    {
        return $this->with(static function (self $builder) use ($events): void {
            $builder->events = $events;
        });
    }

    /**
     * Where the failures of the after-commit publication are reported (they never roll the commit back).
     */
    public function withFailureReporter(FailureReporter $reporter): self
    {
        return $this->with(static function (self $builder) use ($reporter): void {
            $builder->failureReporter = $reporter;
        });
    }

    /**
     * The logger of the HTTP gateway (never personal data, ADR 0010).
     */
    public function withLogger(LoggerInterface $logger): self
    {
        return $this->with(static function (self $builder) use ($logger): void {
            $builder->logger = $logger;
        });
    }

    /**
     * Replaces the default SlidingWindowRateLimiter of the HTTP gateway (for instance NoopRateLimiter), ADR 0013.
     */
    public function withRateLimiter(RateLimiter $rateLimiter): self
    {
        return $this->with(static function (self $builder) use ($rateLimiter): void {
            $builder->rateLimiter = $rateLimiter;
        });
    }

    /**
     * The size of the default sliding window of the HTTP gateway: calls per window, per tenant (ADR 0013 item 3).
     */
    public function withRateLimit(int $limit, int $windowSeconds): self
    {
        return $this->with(static function (self $builder) use ($limit, $windowSeconds): void {
            $builder->rateLimit = ['limit' => $limit, 'window' => $windowSeconds];
        });
    }

    /**
     * Replaces the policy as given, including its business time zone (by default the one of the ApiConfiguration).
     */
    public function withPolicy(WorkflowPolicy $policy): self
    {
        return $this->with(static function (self $builder) use ($policy): void {
            $builder->policy = $policy;
        });
    }

    /**
     * Your own persistence; without statistics, getWorkflowOverview() is not available.
     */
    public function withRepositories(
        AeAccountRepository $accounts,
        MandateRepository $mandates,
        DeclarationRecordRepository $declarations,
        PaymentRecordRepository $payments,
        TransactionManager $transactions,
        ?WorkflowStatistics $statistics,
    ): self {
        return $this->with(static function (self $builder) use ($accounts, $mandates, $declarations, $payments, $transactions, $statistics): void {
            $builder->accounts = $accounts;
            $builder->mandates = $mandates;
            $builder->declarations = $declarations;
            $builder->payments = $payments;
            $builder->transactions = $transactions;
            $builder->statistics = $statistics;
        });
    }

    /**
     * Writes the integration events to the outbox (in the transaction of the repositories) and relays them to the sink.
     */
    public function withOutbox(Outbox $outbox, IntegrationEventSink $sink): self
    {
        return $this->with(static function (self $builder) use ($outbox, $sink): void {
            $builder->outbox = $outbox;
            $builder->sink = $sink;
        });
    }

    /**
     * Replaces the HTTP gateway (for instance with the Testing\SimulatedAeGateway in tests).
     *
     * @throws LogicException with the production environment: nothing but the HTTP gateway may talk for a real
     *                        auto-entrepreneur
     */
    public function withGateway(TierceDeclarationGateway $gateway): self
    {
        if ($this->configuration->environment() === Environment::Production) {
            throw new LogicException(self::GATEWAY_REFUSED_IN_PRODUCTION);
        }

        return $this->with(static function (self $builder) use ($gateway): void {
            $builder->gateway = $gateway;
        });
    }

    public function build(): UrssafTierceDeclarationAutoEntrepreneur
    {
        return new UrssafTierceDeclarationAutoEntrepreneur(
            $this->gateway(),
            $this->accounts,
            $this->mandates,
            $this->declarations,
            $this->payments,
            new UnitOfWork($this->transactions, $this->events, $this->failureReporter, $this->outbox),
            $this->transactions,
            $this->clock,
            $this->ids,
            $this->policy,
            $this->statistics,
            $this->outbox,
            $this->sink,
        );
    }

    /**
     * @param Closure(self): void $change applied to a copy of the builder
     */
    private function with(Closure $change): self
    {
        $builder = clone $this;
        $change($builder);

        return $builder;
    }

    private function gateway(): TierceDeclarationGateway
    {
        return $this->gateway ?? UrssafGatewayFactory::createWith(
            $this->transport,
            $this->credentials,
            $this->tokenStore,
            $this->configuration,
            $this->gatewayOptions(),
        );
    }

    private function gatewayOptions(): GatewayOptions
    {
        $options = GatewayOptions::defaults()->withClock($this->clock)->withIdGenerator($this->ids);
        if ($this->rateLimiter instanceof RateLimiter) {
            $options = $options->withRateLimiter($this->rateLimiter);
        }
        if ($this->rateLimit !== null) {
            $options = $options->withRateLimit($this->rateLimit['limit'], $this->rateLimit['window']);
        }

        return $this->logger instanceof LoggerInterface ? $options->withLogger($this->logger) : $options;
    }
}
