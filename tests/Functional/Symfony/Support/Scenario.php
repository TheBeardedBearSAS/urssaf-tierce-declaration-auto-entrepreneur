<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support;

use PHPUnit\Framework\Assert;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnoverHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\MandateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentSubmission;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationFingerprint;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecord;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Urssaf\Support\Stack;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * Drives an application through the handlers to a given state, with the fictitious auto-entrepreneur, the turnover
 * declaration and the payment order of the simulator samples (the scripted URSSAF platform answers them). The scripted
 * routes touched by a scenario are put back to their default answer afterwards.
 *
 * @mago-expect lint:too-many-methods One method per scenario.
 */
final class Scenario
{
    public const TENANT = 'tenant-1';
    public const SIRET = SimulatorSamples::SIRET;
    public const PAYMENT = 'pay-1';

    private function __construct() {}

    /**
     * Account tracked with its birth date (one read, method 020).
     */
    public static function trackedAccount(Harness $harness, string $tenant = self::TENANT, string $siret = self::SIRET): AccountView
    {
        return $harness->get(TrackAccountHandler::class)(
            new TrackAccount(TenantId::fromString($tenant), Siret::fromString($siret), SimulatorSamples::birthDate()),
        );
    }

    /**
     * Mandate recorded without any call (an import): the local mandate prerequisite of the declarations and payments.
     */
    public static function importedMandate(Harness $harness, string $tenant = self::TENANT, string $siret = self::SIRET): MandateView
    {
        return $harness->get(ImportMandateHandler::class)(
            new ImportMandate(TenantId::fromString($tenant), Siret::fromString($siret), SimulatorSamples::birthDate()),
        );
    }

    /**
     * Account tracked (method 020) whose mandate was recorded without any call: the starting point of the commands that
     * act on a mandated auto-entrepreneur.
     */
    public static function mandatedAccount(Harness $harness, string $tenant = self::TENANT, string $siret = self::SIRET): AccountView
    {
        $account = self::trackedAccount($harness, $tenant, $siret);
        self::importedMandate($harness, $tenant, $siret);

        return $account;
    }

    /**
     * An outbox entry waiting for the relay: the application must be booted with "outbox.enabled".
     */
    public static function pendingOutboxEntry(Harness $harness): OutboxEntry
    {
        self::importedMandate($harness);
        $pending = $harness->privateService(Outbox::class, Outbox::class)->pending(1);
        Assert::assertCount(1, $pending);

        return $pending[0];
    }

    /**
     * Mandate notified to URSSAF (method 030, route 030).
     */
    public static function notifiedMandate(Harness $harness, string $tenant = self::TENANT, string $siret = self::SIRET): MandateView
    {
        return $harness->get(NotifyMandateHandler::class)(
            new NotifyMandate(TenantId::fromString($tenant), Siret::fromString($siret), SimulatorSamples::birthDate()),
        );
    }

    /**
     * Declaration in state "declared" (method 050 answered the success fixture).
     */
    public static function declaration(Harness $harness): DeclarationSubmission
    {
        self::importedMandate($harness);

        return self::declare($harness);
    }

    /**
     * Declaration in state "outcome_unknown": the platform answered a 500 to method 050 (never resent, ADR 0003).
     */
    public static function outcomeUnknownDeclaration(Harness $harness): DeclarationSubmission
    {
        self::importedMandate($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_050, 500, '{"message":"Internal Server Error"}');
        $submission = self::declare($harness);
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_050, 200, 'declare_response.json');

        return $submission;
    }

    /**
     * Declaration in state "invalid_credentials": no access token is cached yet and the token route answers a 401.
     */
    public static function invalidCredentialsDeclaration(Harness $harness): DeclarationSubmission
    {
        self::importedMandate($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 401, '{"error":"invalid_client"}');
        $submission = self::declare($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 200, Stack::TOKEN_RESPONSE);

        return $submission;
    }

    /**
     * Declaration record in state "submitting" claimed twenty minutes ago (the stale threshold is fifteen): nothing was
     * sent. The clock is twenty minutes later afterwards.
     */
    public static function staleDeclaration(Harness $harness, string $tenant = self::TENANT): DeclarationRecordId
    {
        $records = $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class);
        $declaration = SimulatorSamples::declaration();
        $now = Instant::fromDateTime($harness->clock->now());
        $id = DeclarationRecordId::fromString($harness->privateService(IdGenerator::class, IdGenerator::class)->generate());
        $records->save(DeclarationRecord::record(
            $id,
            TenantId::fromString($tenant),
            SimulatorSamples::siret(),
            $declaration->period(),
            DeclarationFingerprint::of(TenantId::fromString($tenant), SimulatorSamples::siret(), $declaration->period(), $declaration->turnover()),
            null,
            $now,
        ));
        $records->claimForSubmission($id, $now);
        $harness->clock->modify('+20 minutes');

        return $id;
    }

    /**
     * Payment in state "paid" (method 060 answered the success fixture).
     */
    public static function payment(Harness $harness, string $reference = self::PAYMENT): PaymentSubmission
    {
        self::importedMandate($harness);

        return self::pay($harness, $reference);
    }

    /**
     * Payment in state "outcome_unknown": the platform answered a 500 to method 060 (never resent, ADR 0003).
     */
    public static function outcomeUnknownPayment(Harness $harness, string $reference = self::PAYMENT): PaymentSubmission
    {
        self::importedMandate($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_060, 500, '{"message":"Internal Server Error"}');
        $submission = self::pay($harness, $reference);
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_060, 200, 'pay_response.json');

        return $submission;
    }

    /**
     * Payment record in state "submitting" claimed twenty minutes ago: nothing was sent. The clock is twenty minutes
     * later afterwards.
     */
    public static function stalePayment(Harness $harness, string $tenant = self::TENANT, string $reference = self::PAYMENT): PaymentRecordId
    {
        $records = $harness->privateService(PaymentRecordRepository::class, PaymentRecordRepository::class);
        $now = Instant::fromDateTime($harness->clock->now());
        $id = PaymentRecordId::fromString($harness->privateService(IdGenerator::class, IdGenerator::class)->generate());
        $records->save(PaymentRecord::record(
            $id,
            TenantId::fromString($tenant),
            PaymentReference::fromString($reference),
            SimulatorSamples::siret(),
            SimulatorSamples::order(),
            null,
            $now,
        ));
        $records->claimForSubmission($id, $now);
        $harness->clock->modify('+20 minutes');

        return $id;
    }

    private static function declare(Harness $harness): DeclarationSubmission
    {
        return $harness->get(DeclareTurnoverHandler::class)(
            new DeclareTurnover(TenantId::fromString(self::TENANT), SimulatorSamples::siret(), SimulatorSamples::declaration()),
        );
    }

    private static function pay(Harness $harness, string $reference): PaymentSubmission
    {
        return $harness->get(PayContributionsHandler::class)(
            new PayContributions(
                TenantId::fromString(self::TENANT),
                PaymentReference::fromString($reference),
                SimulatorSamples::siret(),
                SimulatorSamples::order(),
            ),
        );
    }
}
