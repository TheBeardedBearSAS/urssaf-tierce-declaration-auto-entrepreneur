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

use Doctrine\ORM\EntityManagerInterface;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountSelector;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ItemOutcome;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverview;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Environment;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Standalone\UrssafTierceDeclarationAutoEntrepreneur;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Standalone\UrssafTierceDeclarationAutoEntrepreneurBuilder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing\SimulatedAeGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake\SequentialIdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Sepa\SepaSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Fake\StaticCredentialsProvider;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The standalone package on the Doctrine persistence, end to end over the simulator (ADR 0016), then ADR 0010: the
 * bank details and the addresses of the SEPA mandates are never stored. Fictitious values only.
 */
final class DoctrineStandaloneTest extends TestCase
{
    private const TABLES = "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'urssaf\\_tdae\\_%' ESCAPE '\\'";

    public function testTheWholeWorkflowRunsOnDoctrineAndStoresNoBankDetails(): void
    {
        $entityManager = TestDatabase::entityManager();
        $sink = new RecordingIntegrationEventSink();
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $package = self::package($entityManager, $gateway, $sink);
        $tenant = SimulatorSamples::tenant();
        $siret = SimulatorSamples::siret();

        $tracked = $package->trackAccount()(new TrackAccount($tenant, $siret, SimulatorSamples::birthDate()));
        $package->notifyMandate()(new NotifyMandate($tenant, $siret, SimulatorSamples::birthDate()));
        $declared = $package->declareTurnover()(new DeclareTurnover($tenant, $siret, SimulatorSamples::declaration()));
        $paid = $package->payContributions()(new PayContributions($tenant, PaymentReference::fromString('pay-1'), $siret, SimulatorSamples::order()));
        $registered = $package->registerSepaMandate()(new RegisterSepaMandate($tenant, $siret, SimulatorSamples::newMandate()));
        $entityManager->clear();

        static::assertTrue($tracked->hasBirthDate);
        static::assertSame(ItemOutcome::Declared, $declared->outcome);
        static::assertSame(ItemOutcome::Paid, $paid->outcome);
        static::assertNotNull($registered->rum());
        $account = $package->getAccount()(new GetAccount($tenant, AccountSelector::bySiret($siret)));
        static::assertTrue($account->hasBirthDate);
        $overview = $package->getWorkflowOverview()(GetWorkflowOverview::forTenant($tenant))[0];
        static::assertSame(1, $overview->mandates['notified']);
        static::assertSame(1, $overview->declarations['declared']);
        static::assertSame(1, $overview->payments['paid']);

        $pending = $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_outbox WHERE dispatched_at IS NULL');
        $report = $package->relayOutbox()(new RelayOutbox(null));
        static::assertIsInt($pending);
        static::assertGreaterThan(0, $pending);
        static::assertSame($pending, $report->dispatched);
        static::assertCount($report->dispatched, $sink->deliveredIds());
        static::assertSame(0, $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM urssaf_tdae_outbox WHERE dispatched_at IS NULL'));
        static::assertSame(0, $package->purgeOutbox()(new PurgeOutbox(null)));

        $dump = self::dump($entityManager);
        foreach ([SepaSamples::IBAN, SepaSamples::BIC, SepaSamples::HOLDER, '13 boulevard vauban', 'LILLE', '59000', 'FRANCE'] as $secret) {
            static::assertStringNotContainsStringIgnoringCase($secret, $dump);
        }
        static::assertCount(8, $entityManager->getConnection()->fetchFirstColumn(self::TABLES));
        static::assertStringContainsString(SimulatorSamples::SIRET, $dump);
    }

    public function testTheRecordsSurviveASecondPackageOnTheSameEntityManager(): void
    {
        $entityManager = TestDatabase::entityManager();
        $gateway = SimulatorSamples::gateway();
        $gateway->knowAccount(SimulatorSamples::siret(), SimulatorSamples::birthDate());
        $tenant = SimulatorSamples::tenant();
        $siret = SimulatorSamples::siret();
        $first = self::package($entityManager, $gateway, new RecordingIntegrationEventSink());
        $first->trackAccount()(new TrackAccount($tenant, $siret, SimulatorSamples::birthDate()));
        $first->notifyMandate()(new NotifyMandate($tenant, $siret, SimulatorSamples::birthDate()));
        $declared = $first->declareTurnover()(new DeclareTurnover($tenant, $siret, SimulatorSamples::declaration()));
        $entityManager->clear();

        $overview = self::package($entityManager, $gateway, new RecordingIntegrationEventSink())->getWorkflowOverview()(GetWorkflowOverview::forTenant($tenant))[0];

        static::assertSame(ItemOutcome::Declared, $declared->outcome);
        static::assertSame(1, $overview->declarations['declared']);
        static::assertSame(1, $overview->mandates['notified']);
    }

    private static function package(EntityManagerInterface $entityManager, SimulatedAeGateway $gateway, RecordingIntegrationEventSink $sink): UrssafTierceDeclarationAutoEntrepreneur
    {
        $factory = new Psr17Factory();

        return UrssafTierceDeclarationAutoEntrepreneurBuilder::create(
            new HttpTransport(new Client(), $factory, $factory),
            new StaticCredentialsProvider(),
            ApiConfiguration::forEnvironment(Environment::Sandbox),
        )
            ->withGateway($gateway)
            ->withClock(new FixedClock(SimulatorSamples::NOW))
            ->withIdGenerator(new SequentialIdGenerator())
            ->withDoctrine($entityManager)
            ->withOutbox(new DoctrineOutbox($entityManager), $sink)
            ->build();
    }

    /**
     * Every column of every table, as one lower-case-insensitive string.
     */
    private static function dump(EntityManagerInterface $entityManager): string
    {
        $connection = $entityManager->getConnection();
        $dump = '';
        // @mago-expect analysis:mixed-assignment The driver returns untyped columns; the assertion below narrows the name.
        foreach ($connection->fetchFirstColumn(self::TABLES) as $table) {
            static::assertIsString($table);
            foreach ($connection->fetchAllAssociative('SELECT * FROM ' . $table) as $row) {
                $dump .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }

        return $dump;
    }
}
