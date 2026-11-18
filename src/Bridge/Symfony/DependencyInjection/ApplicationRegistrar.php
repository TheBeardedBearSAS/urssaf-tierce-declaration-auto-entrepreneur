<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection;

use DateInterval;
use DateTimeZone;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\CancelMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnoverHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ForgetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarationsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStalePaymentsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RecordMandateCancellationHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RequeueAfterInvalidCredentialsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolveDeclarationOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RevokeSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\EventPublisher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\FailureReporter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\EstimateContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindDeclarationsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\FindPaymentsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetDeclarationHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetPaymentHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\GetWorkflowOverviewHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Query\ListSepaMandatesHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountRefresher;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\DeclarationSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateGuard;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\MandateRecorder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\PaymentSubmitter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;

/**
 * Application layer: workflow policy, services and use case handlers (the only public services of the bundle, with the
 * simulated gateway). The outbox handlers are registered by {@see OutboxRegistrar}.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class ApplicationRegistrar
{
    /** Use case handlers exposed to the host application (the statistics and outbox ones are conditional). */
    public const HANDLERS = [
        TrackAccountHandler::class,
        SyncAccountHandler::class,
        SyncAccountsHandler::class,
        NotifyMandateHandler::class,
        CancelMandateHandler::class,
        ImportMandateHandler::class,
        RecordMandateCancellationHandler::class,
        ForgetAccountHandler::class,
        DeclareTurnoverHandler::class,
        ResolveDeclarationOutcomeHandler::class,
        ReconcileStaleDeclarationsHandler::class,
        PayContributionsHandler::class,
        ResolvePaymentOutcomeHandler::class,
        ReconcileStalePaymentsHandler::class,
        RequeueAfterInvalidCredentialsHandler::class,
        RegisterSepaMandateHandler::class,
        RevokeSepaMandateHandler::class,
        ListSepaMandatesHandler::class,
        EstimateContributionsHandler::class,
        GetAccountHandler::class,
        GetDeclarationHandler::class,
        FindDeclarationsHandler::class,
        GetPaymentHandler::class,
        FindPaymentsHandler::class,
    ];

    /** Services shared by the handlers (private, autowired). */
    public const SERVICES = [
        AccountLocator::class,
        AccountRefresher::class,
        MandateGuard::class,
        MandateRecorder::class,
        DeclarationLocator::class,
        DeclarationSubmitter::class,
        PaymentLocator::class,
        PaymentSubmitter::class,
    ];

    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        $workflow = $config['workflow'];
        // Named arguments: several parameters of the policy are booleans or integers of the same type.
        $this->services->set(WorkflowPolicy::class)
            ->arg('$businessTimeZone', new Definition(DateTimeZone::class, [$config['business_timezone']]))
            ->arg('$staleSubmissionAfter', new Definition(DateInterval::class, [$workflow['stale_submission_after']]))
            ->arg('$maxSubmissionAttempts', $workflow['max_submission_attempts'])
            ->arg('$requireLocalMandate', $workflow['require_local_mandate'])
            ->arg('$defaultPageSize', $workflow['default_page_size'])
            ->arg('$maxPageSize', $workflow['max_page_size'])
            ->arg('$allowErasureWithUnknownOutcomes', $workflow['erasure']['allow_unknown_outcomes'])
            ->arg('$allowErasureWithActiveMandate', $workflow['erasure']['allow_active_mandate'])
            ->arg('$outboxRetention', new Definition(DateInterval::class, [$config['outbox']['retention']]));
        // Explicit arguments: a missing alias must fail the build, not fall back on the defaults of the constructor.
        $this->services->set(UnitOfWork::class)->args([
            new Reference(TransactionManager::class),
            new Reference(EventPublisher::class),
            new Reference(FailureReporter::class),
            new Reference(Outbox::class),
        ]);
        foreach (self::SERVICES as $service) {
            $this->services->set($service)->autowire();
        }
        foreach (self::HANDLERS as $handler) {
            $this->services->set($handler)->autowire()->public();
        }
        if (PersistenceRegistrar::hasStatistics($config['persistence']['driver'], $config['persistence']['workflow_statistics'])) {
            $this->services->set(GetWorkflowOverviewHandler::class)->autowire()->public();
        }
    }
}
