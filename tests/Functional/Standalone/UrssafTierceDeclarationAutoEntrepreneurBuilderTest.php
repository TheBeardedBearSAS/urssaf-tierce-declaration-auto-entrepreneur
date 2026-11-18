<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Standalone;

use DateInterval;
use DateTimeZone;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarations;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStalePayments;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolveDeclarationOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RevokeSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\MandateRequired;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\RateLimitExceeded;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\EstimateContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindDeclarations;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindPayments;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetPayment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverview;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\ListSepaMandates;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Event\InMemoryEventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\NoopRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Standalone\UrssafTierceDeclarationAutoEntrepreneur;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Standalone\UrssafTierceDeclarationAutoEntrepreneurBuilder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FailingEventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingFailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\SequentialIdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\CapturingLogger;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\RejectingRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Fixtures;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The standalone wiring end to end, over the simulator or over a PSR-18 mock client. Fictitious values only.
 *
 * @mago-expect lint:too-many-methods One test per builder option.
 */
final class UrssafTierceDeclarationAutoEntrepreneurBuilderTest extends TestCase
{
    private const HANDLERS = 27;

    public function testEveryUseCaseIsAvailable(): void
    {
        $package = self::builder()
            ->withGateway(new SimulatedAeGateway())
            ->withOutbox(new InMemoryOutbox(), new RecordingIntegrationEventSink())
            ->build();
        $accessors = [];
        foreach ((new ReflectionClass(UrssafTierceDeclarationAutoEntrepreneur::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($type instanceof ReflectionNamedType && !$method->isConstructor() && $method->getName() !== 'gateway') {
                $accessors[$type->getName()] = $method;
            }
        }

        $handlers = [];
        foreach (['Command', 'Query'] as $kind) {
            $files = glob(__DIR__ . '/../../../src/Application/' . $kind . '/*Handler.php');
            static::assertIsArray($files);
            foreach ($files as $file) {
                $handlers[] = 'TheBeardedBear\\UrssafTierceDeclarationAutoEntrepreneur\\Application\\' . $kind . '\\' . basename($file, '.php');
            }
        }

        static::assertCount(self::HANDLERS, $handlers);
        static::assertCount(\count($handlers), $accessors);
        foreach ($handlers as $handler) {
            static::assertArrayHasKey($handler, $accessors, $handler . ' has no accessor.');
            static::assertSame($handler, get_debug_type($accessors[$handler]->invoke($package)));
        }
        static::assertInstanceOf(SimulatedAeGateway::class, $package->gateway());
    }

    /**
     * @mago-expect lint:halstead The end-to-end path is one scenario, read top to bottom.
     */
    public function testTheWholeWorkflowRunsWithTheSimulator(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $outbox = new InMemoryOutbox();
        $sink = new RecordingIntegrationEventSink();
        $package = self::builder()
            ->withGateway($gateway)
            ->withClock(new FixedClock(SimulatorSamples::NOW))
            ->withIdGenerator(new SequentialIdGenerator())
            ->withOutbox($outbox, $sink)
            ->build();
        $tenant = SimulatorSamples::tenant();
        $siret = SimulatorSamples::siret();

        $tracked = $package->trackAccount()(new TrackAccount($tenant, $siret, SimulatorSamples::birthDate()));
        $mandate = $package->notifyMandate()(new NotifyMandate($tenant, $siret, SimulatorSamples::birthDate()));
        $synced = $package->syncAccount()(new SyncAccount($tenant, AccountSelector::bySiret($siret)));
        $account = $package->getAccount()(new GetAccount($tenant, AccountSelector::bySiret($siret)));
        $estimate = $package->estimateContributions()(new EstimateContributions($tenant, $siret, SimulatorSamples::turnover()));
        $declared = $package->declareTurnover()(new DeclareTurnover($tenant, $siret, SimulatorSamples::declaration()));
        $paid = $package->payContributions()(new PayContributions($tenant, PaymentReference::fromString('pay-1'), $siret, SimulatorSamples::order()));
        $registered = $package->registerSepaMandate()(new RegisterSepaMandate($tenant, $siret, SimulatorSamples::newMandate()));
        $listed = $package->listSepaMandates()(new ListSepaMandates($tenant, $siret));
        $rum = $registered->rum();
        static::assertNotNull($rum);
        $package->revokeSepaMandate()(new RevokeSepaMandate($tenant, SepaRevocation::of($siret, $rum, null)));
        $afterRevocation = $package->listSepaMandates()(new ListSepaMandates($tenant, $siret));

        static::assertFalse($tracked->mandatePresent);
        static::assertTrue($tracked->hasBirthDate);
        static::assertSame('notified', $mandate->state);
        static::assertTrue($synced->mandatePresent);
        static::assertTrue($account->mandatePresent);
        static::assertSame(220, $estimate->amounts->total);
        static::assertSame(ItemOutcome::Declared, $declared->outcome);
        static::assertSame('declared', $declared->declaration->status->state);
        static::assertSame('00000001', $declared->declaration->status->result?->certificat);
        static::assertSame(ItemOutcome::Paid, $paid->outcome);
        static::assertSame('00000001', $paid->payment->status->result?->certificat);
        static::assertCount(1, $listed);
        static::assertSame([], $afterRevocation);

        $unknownDeclaration = SimulatorSamples::month(9);
        $gateway->loseNextAnswer(Operation::Declare);
        $unknown = $package->declareTurnover()(new DeclareTurnover($tenant, $siret, SimulatorSamples::declarationFor($unknownDeclaration)));
        $resolved = $package->resolveDeclarationOutcome()(
            ResolveDeclarationOutcome::asDeclared($tenant, DeclarationSelector::current($siret, $unknownDeclaration), '00000002'),
        );
        $gateway->loseNextAnswer(Operation::Pay);
        $unknownPayment = $package->payContributions()(new PayContributions($tenant, PaymentReference::fromString('pay-2'), $siret, SimulatorSamples::orderFor($unknownDeclaration)));
        $resolvedPayment = $package->resolvePaymentOutcome()(
            ResolvePaymentOutcome::asPaid($tenant, PaymentSelector::byReference(PaymentReference::fromString('pay-2')), '00000002'),
        );
        $staleDeclarations = $package->reconcileStaleDeclarations()(new ReconcileStaleDeclarations(null));
        $stalePayments = $package->reconcileStalePayments()(new ReconcileStalePayments(null));

        static::assertSame(ItemOutcome::OutcomeUnknown, $unknown->outcome);
        static::assertSame('declared', $resolved->status->state);
        static::assertSame(ItemOutcome::OutcomeUnknown, $unknownPayment->outcome);
        static::assertSame('paid', $resolvedPayment->status->state);
        static::assertSame(0, $staleDeclarations->total());
        static::assertSame(0, $stalePayments->total());

        $found = $package->getDeclaration()(new GetDeclaration($tenant, DeclarationSelector::current($siret, SimulatorSamples::month())));
        $listedDeclarations = $package->findDeclarations()(FindDeclarations::inState($tenant, DeclarationState::Declared, null));
        $foundPayment = $package->getPayment()(new GetPayment($tenant, PaymentSelector::byReference(PaymentReference::fromString('pay-1'))));
        $listedPayments = $package->findPayments()(FindPayments::inState($tenant, PaymentState::Paid, null));
        $overview = $package->getWorkflowOverview()(GetWorkflowOverview::forTenant($tenant));

        static::assertSame($declared->declaration->recordId, $found->recordId);
        static::assertCount(2, $listedDeclarations);
        static::assertSame($paid->payment->recordId, $foundPayment->recordId);
        static::assertCount(2, $listedPayments);
        static::assertCount(1, $overview);
        static::assertSame(1, $overview[0]->mandates['notified']);
        static::assertSame(2, $overview[0]->declarations['declared']);
        static::assertSame(2, $overview[0]->payments['paid']);
        static::assertSame(0, $overview[0]->needingAttention());
        static::assertSame(
            ['020', '030', '020', '040', '050', '060', '070', '075', '071', '075', '050', '060'],
            $gateway->calls(),
        );

        $pending = $outbox->pending(100);
        $report = $package->relayOutbox()(new RelayOutbox(null));

        static::assertNotSame([], $pending);
        static::assertSame([], $outbox->pending(100));
        static::assertSame(\count($pending), $report->dispatched);
        static::assertCount(\count($pending), $sink->deliveredIds());
        static::assertSame(0, $package->purgeOutbox()(new PurgeOutbox(null)));
    }

    public function testAnImportedMandateIsRecordedWithoutACall(): void
    {
        $gateway = new SimulatedAeGateway();
        $package = self::builder()->withGateway($gateway)->build();

        $mandate = $package->importMandate()(new ImportMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), null));

        static::assertSame('notified', $mandate->state);
        static::assertSame([], $gateway->calls());
    }

    public function testThePolicyIsUsed(): void
    {
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $gateway->mandate(SimulatorSamples::siret(), SimulatorSamples::tenant());
        $command = new DeclareTurnover(SimulatorSamples::tenant(), SimulatorSamples::siret(), SimulatorSamples::declaration());
        $strict = self::builder()->withGateway($gateway)->build();
        $lenient = self::builder()->withGateway($gateway)->withPolicy(new WorkflowPolicy(new DateTimeZone('UTC'), new DateInterval('PT10M'), 3, false))->build();

        $refused = SimulatorSamples::failure(static fn(): DeclarationSubmission => $strict->declareTurnover()($command));

        static::assertInstanceOf(MandateRequired::class, $refused);
        static::assertSame([], $gateway->calls());
        static::assertSame(ItemOutcome::Declared, $lenient->declareTurnover()($command)->outcome);
    }

    public function testThePolicyTimeZoneIsTheOneOfTheConfigurationUnlessAPolicyIsGiven(): void
    {
        $factory = new Psr17Factory();
        $builder = UrssafTierceDeclarationAutoEntrepreneurBuilder::create(
            new HttpTransport(new Client(), $factory, $factory),
            new StaticCredentialsProvider(),
            new ApiConfiguration(
                Environment::Sandbox,
                null,
                null,
                null,
                ApiConfiguration::DEFAULT_TOKEN_SAFETY_MARGIN_SECONDS,
                ApiConfiguration::DEFAULT_USER_AGENT,
                new DateTimeZone('America/Cayenne'),
            ),
        );

        $derived = self::policyOf($builder->build());
        $given = self::policyOf($builder->withPolicy(new WorkflowPolicy(new DateTimeZone('UTC')))->build());

        static::assertSame('America/Cayenne', $derived->businessTimeZone()->getName());
        static::assertSame('UTC', $given->businessTimeZone()->getName());
    }

    public function testTheHttpGatewayIsBuiltFromTheTransportAndItsOptions(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], Stack::TOKEN_RESPONSE));
        $http->addResponse(new Response(200, [], Fixtures::load('account_response')));
        $logger = new CapturingLogger();
        $package = self::builderOver($http)
            ->withClock(new FixedClock(SimulatorSamples::NOW))
            ->withIdGenerator(new SequentialIdGenerator())
            ->withLogger($logger)
            ->build();

        $status = $package->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertInstanceOf(UrssafHttpGateway::class, $package->gateway());
        static::assertFalse($status->mandatePresent());
        $requests = $http->getRequests();
        static::assertCount(2, $requests);
        static::assertSame('GET', $requests[1]->getMethod());
        static::assertSame('/tiersdecl/v1/ae/comptes', $requests[1]->getUri()->getPath());
        static::assertSame('siret=' . SimulatorSamples::SIRET, $requests[1]->getUri()->getQuery());
        static::assertNotSame([], $logger->records());
    }

    public function testTheRateLimiterIsUsed(): void
    {
        $limited = new Client();
        $package = self::builderOver($limited)->withRateLimiter(new RejectingRateLimiter())->build();

        $failure = SimulatorSamples::failure(static fn(): AccountStatus => $package->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret()));

        static::assertInstanceOf(RateLimitExceeded::class, $failure);
        static::assertSame([], $limited->getRequests());
    }

    public function testTheRateLimitSizesTheDefaultWindow(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], Stack::TOKEN_RESPONSE));
        $http->addResponse(new Response(200, [], Fixtures::load('account_response')));
        $package = self::builderOver($http)->withClock(new FixedClock(SimulatorSamples::NOW))->withRateLimit(1, 60)->build();
        $package->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        $failure = SimulatorSamples::failure(static fn(): AccountStatus => $package->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret()));

        static::assertInstanceOf(RateLimitExceeded::class, $failure);
        static::assertCount(2, $http->getRequests());
    }

    public function testAnExplicitRateLimiterWinsOverTheRateLimit(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], Stack::TOKEN_RESPONSE));
        $http->addResponse(new Response(200, [], Fixtures::load('account_response')));
        $http->addResponse(new Response(200, [], Fixtures::load('account_response')));
        $package = self::builderOver($http)->withRateLimit(1, 60)->withRateLimiter(new NoopRateLimiter())->build();

        $package->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());
        $package->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertCount(3, $http->getRequests());
    }

    public function testTheTokenStoreIsUsed(): void
    {
        $tokens = new InMemoryTokenStore();
        $first = new Client();
        $first->addResponse(new Response(200, [], Stack::TOKEN_RESPONSE));
        $first->addResponse(new Response(200, [], Fixtures::load('account_response')));
        $second = new Client();
        $second->addResponse(new Response(200, [], Fixtures::load('account_response')));
        $clock = new FixedClock(SimulatorSamples::NOW);

        self::builderOver($first)->withClock($clock)->withTokenStore($tokens)->build()->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());
        self::builderOver($second)->withClock($clock)->withTokenStore($tokens)->build()->gateway()->findAccount(SimulatorSamples::tenant(), SimulatorSamples::siret());

        static::assertCount(2, $first->getRequests());
        static::assertCount(1, $second->getRequests());
    }

    public function testTheEventPublisherReceivesTheDomainEvents(): void
    {
        $publisher = new InMemoryEventPublisher();
        $package = self::builder()->withGateway(new SimulatedAeGateway())->withEventPublisher($publisher)->build();

        $package->importMandate()(new ImportMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), null));

        static::assertNotSame([], $publisher->published());
        static::assertContainsOnlyInstancesOf(DomainEvent::class, $publisher->published());
    }

    public function testThePublicationFailuresAreGivenToTheFailureReporter(): void
    {
        $reporter = new RecordingFailureReporter();
        $accounts = new InMemoryMandateRepository();
        $package = self::builder()
            ->withGateway(new SimulatedAeGateway())
            ->withRepositories(new InMemoryAeAccountRepository(), $accounts, new InMemoryDeclarationRecordRepository(), new InMemoryPaymentRecordRepository(), new ImmediateTransactionManager(), null)
            ->withEventPublisher(new FailingEventPublisher(new RuntimeException('broken bus')))
            ->withFailureReporter($reporter)
            ->build();

        $mandate = $package->importMandate()(new ImportMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), null));

        static::assertSame('notified', $mandate->state);
        static::assertCount(1, $reporter->reports());
        static::assertSame('broken bus', $reporter->reports()[0][0]->getMessage());
        static::assertNotNull($accounts->findActive(SimulatorSamples::tenant(), SimulatorSamples::siret()));
    }

    public function testTheOutboxIsWrittenAndRelayed(): void
    {
        $outbox = new InMemoryOutbox();
        $sink = new RecordingIntegrationEventSink();
        $package = self::builder()->withGateway(new SimulatedAeGateway())->withOutbox($outbox, $sink)->build();
        $package->importMandate()(new ImportMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), null));
        $pending = $outbox->pending(10);

        $report = $package->relayOutbox()(new RelayOutbox(null));

        static::assertNotSame([], $pending);
        static::assertSame([], $outbox->pending(10));
        static::assertSame(\count($pending), $report->dispatched);
    }

    public function testCustomRepositoriesAreUsed(): void
    {
        $mandates = new InMemoryMandateRepository();
        $package = self::builder()
            ->withGateway(new SimulatedAeGateway())
            ->withRepositories(
                new InMemoryAeAccountRepository(),
                $mandates,
                new InMemoryDeclarationRecordRepository(),
                new InMemoryPaymentRecordRepository(),
                new ImmediateTransactionManager(),
                null,
            )
            ->build();

        $package->importMandate()(new ImportMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), null));

        static::assertNotNull($mandates->findActive(SimulatorSamples::tenant(), SimulatorSamples::siret()));
    }

    public function testTheDoctrinePersistenceIsWiredOnTheEntityManager(): void
    {
        $entityManager = TestDatabase::entityManager();
        $package = self::builder()->withGateway(new SimulatedAeGateway())->withDoctrine($entityManager)->build();

        $package->importMandate()(new ImportMandate(SimulatorSamples::tenant(), SimulatorSamples::siret(), null));

        $connection = $entityManager->getConnection();
        static::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_mandates'));
        static::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_active_mandates'));
        $overview = $package->getWorkflowOverview()(GetWorkflowOverview::forTenant(SimulatorSamples::tenant()));
        static::assertSame(1, $overview[0]->mandates['notified']);
    }

    public function testTheOverviewNeedsStatistics(): void
    {
        $package = self::builder()
            ->withRepositories(
                new InMemoryAeAccountRepository(),
                new InMemoryMandateRepository(),
                new InMemoryDeclarationRecordRepository(),
                new InMemoryPaymentRecordRepository(),
                new ImmediateTransactionManager(),
                null,
            )
            ->build();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No WorkflowStatistics');

        $package->getWorkflowOverview();
    }

    public function testTheRelayNeedsASink(): void
    {
        $package = self::builder()->build();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No IntegrationEventSink');

        $package->relayOutbox();
    }

    public function testASimulatorIsRefusedInProduction(): void
    {
        $factory = new Psr17Factory();
        $builder = UrssafTierceDeclarationAutoEntrepreneurBuilder::create(
            new HttpTransport(new Client(), $factory, $factory),
            new StaticCredentialsProvider(),
            ApiConfiguration::forEnvironment(Environment::Production),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refused with the production environment');

        $builder->withGateway(new SimulatedAeGateway());
    }

    public function testTheHttpGatewayIsBuiltInProduction(): void
    {
        $factory = new Psr17Factory();
        $package = UrssafTierceDeclarationAutoEntrepreneurBuilder::create(
            new HttpTransport(new Client(), $factory, $factory),
            new StaticCredentialsProvider(),
            ApiConfiguration::forEnvironment(Environment::Production),
        )->build();

        static::assertInstanceOf(UrssafHttpGateway::class, $package->gateway());
    }

    public function testEveryWithMethodReturnsANewBuilderAndLeavesTheOriginalUnchanged(): void
    {
        $base = self::builder();
        $derived = [
            $base->withTokenStore(new InMemoryTokenStore()),
            $base->withClock(new FixedClock(SimulatorSamples::NOW)),
            $base->withIdGenerator(new SequentialIdGenerator()),
            $base->withEventPublisher(new InMemoryEventPublisher()),
            $base->withFailureReporter(new RecordingFailureReporter()),
            $base->withLogger(new CapturingLogger()),
            $base->withRateLimiter(new NoopRateLimiter()),
            $base->withRateLimit(1, 60),
            $base->withPolicy(new WorkflowPolicy()),
            $base->withRepositories(
                new InMemoryAeAccountRepository(),
                new InMemoryMandateRepository(),
                new InMemoryDeclarationRecordRepository(),
                new InMemoryPaymentRecordRepository(),
                new ImmediateTransactionManager(),
                null,
            ),
            $base->withOutbox(new InMemoryOutbox(), new RecordingIntegrationEventSink()),
            $base->withGateway(new SimulatedAeGateway()),
        ];

        foreach ($derived as $builder) {
            static::assertNotSame($base, $builder);
        }
        static::assertInstanceOf(UrssafHttpGateway::class, $base->build()->gateway());
        static::assertInstanceOf(SimulatedAeGateway::class, $derived[11]->build()->gateway());
        static::assertNotNull(self::property($base->build(), 'statistics'));
        static::assertNull(self::property($derived[9]->build(), 'statistics'));
        static::assertNull(self::property($base->build(), 'sink'));
        static::assertNotNull(self::property($derived[10]->build(), 'sink'));
    }

    public function testTheGatewayAccessorReturnsTheWiredGateway(): void
    {
        $gateway = new SimulatedAeGateway();

        $package = self::builder()->withGateway($gateway)->build();

        static::assertSame($gateway, $package->gateway());
    }

    private static function policyOf(UrssafTierceDeclarationAutoEntrepreneur $package): WorkflowPolicy
    {
        return self::asPolicy(self::property($package, 'policy'));
    }

    private static function asPolicy(mixed $policy): WorkflowPolicy
    {
        static::assertInstanceOf(WorkflowPolicy::class, $policy);

        return $policy;
    }

    private static function property(UrssafTierceDeclarationAutoEntrepreneur $package, string $name): mixed
    {
        return (new ReflectionClass($package))->getProperty($name)->getValue($package);
    }

    private static function builder(): UrssafTierceDeclarationAutoEntrepreneurBuilder
    {
        return self::builderOver(new Client());
    }

    private static function builderOver(Client $http): UrssafTierceDeclarationAutoEntrepreneurBuilder
    {
        $factory = new Psr17Factory();

        return UrssafTierceDeclarationAutoEntrepreneurBuilder::create(
            new HttpTransport($http, $factory, $factory),
            new StaticCredentialsProvider(),
            ApiConfiguration::forEnvironment(Environment::Sandbox),
        );
    }
}
