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

use LogicException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\CancelMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\DeclareTurnoverHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ForgetAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ImportMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\NotifyMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PayContributionsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStaleDeclarationsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ReconcileStalePaymentsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RecordMandateCancellationHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RegisterSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RequeueAfterInvalidCredentialsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolveDeclarationOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\ResolvePaymentOutcomeHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\RevokeSepaMandateHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\SyncAccountsHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command\TrackAccountHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IdGenerator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Outbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\TransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\WorkflowStatistics;
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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;

/**
 * The package without a framework: one getter per use case, each building its handler on the shared collaborators. Obtain
 * it from {@see UrssafTierceDeclarationAutoEntrepreneurBuilder}; wire the handlers yourself when you need something the
 * builder does not offer.
 *
 * One getter per use case, hence the method count and the parameter list of the constructor.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class UrssafTierceDeclarationAutoEntrepreneur
{
    private const STATISTICS_MISSING = 'No WorkflowStatistics: give one with the repositories (withRepositories()).';

    private const SINK_MISSING = 'No IntegrationEventSink: give the outbox and its sink (withOutbox()).';

    public function __construct(
        private TierceDeclarationGateway $gateway,
        private AeAccountRepository $accounts,
        private MandateRepository $mandates,
        private DeclarationRecordRepository $declarations,
        private PaymentRecordRepository $payments,
        private UnitOfWork $unitOfWork,
        private TransactionManager $transactions,
        private Clock $clock,
        private IdGenerator $ids,
        private WorkflowPolicy $policy,
        private ?WorkflowStatistics $statistics,
        private Outbox $outbox,
        private ?IntegrationEventSink $sink,
    ) {}

    public function gateway(): TierceDeclarationGateway
    {
        return $this->gateway;
    }

    public function cancelMandate(): CancelMandateHandler
    {
        return new CancelMandateHandler($this->accounts, $this->mandates, $this->gateway, $this->mandateRecorder());
    }

    public function declareTurnover(): DeclareTurnoverHandler
    {
        return new DeclareTurnoverHandler($this->declarations, $this->accounts, $this->declarationSubmitter(), $this->mandateGuard(), $this->ids, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function estimateContributions(): EstimateContributionsHandler
    {
        return new EstimateContributionsHandler($this->gateway, $this->mandateGuard());
    }

    public function findDeclarations(): FindDeclarationsHandler
    {
        return new FindDeclarationsHandler($this->declarations, $this->policy);
    }

    public function findPayments(): FindPaymentsHandler
    {
        return new FindPaymentsHandler($this->payments, $this->policy);
    }

    public function forgetAccount(): ForgetAccountHandler
    {
        return new ForgetAccountHandler($this->accountLocator(), $this->accounts, $this->mandates, $this->declarations, $this->payments, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function getAccount(): GetAccountHandler
    {
        return new GetAccountHandler($this->accountLocator(), $this->mandates);
    }

    public function getDeclaration(): GetDeclarationHandler
    {
        return new GetDeclarationHandler($this->declarationLocator());
    }

    public function getPayment(): GetPaymentHandler
    {
        return new GetPaymentHandler($this->paymentLocator());
    }

    /**
     * @throws LogicException when no statistics were given (withRepositories())
     */
    public function getWorkflowOverview(): GetWorkflowOverviewHandler
    {
        return new GetWorkflowOverviewHandler($this->statistics ?? throw new LogicException(self::STATISTICS_MISSING), $this->declarations);
    }

    public function importMandate(): ImportMandateHandler
    {
        return new ImportMandateHandler($this->accounts, $this->mandates, $this->mandateRecorder());
    }

    public function listSepaMandates(): ListSepaMandatesHandler
    {
        return new ListSepaMandatesHandler($this->gateway);
    }

    public function notifyMandate(): NotifyMandateHandler
    {
        return new NotifyMandateHandler($this->accounts, $this->mandates, $this->gateway, $this->mandateRecorder());
    }

    public function payContributions(): PayContributionsHandler
    {
        return new PayContributionsHandler($this->payments, $this->accounts, $this->paymentSubmitter(), $this->mandateGuard(), $this->ids, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function purgeOutbox(): PurgeOutboxHandler
    {
        return new PurgeOutboxHandler($this->outbox, $this->clock, $this->policy);
    }

    public function reconcileStaleDeclarations(): ReconcileStaleDeclarationsHandler
    {
        return new ReconcileStaleDeclarationsHandler($this->declarations, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function reconcileStalePayments(): ReconcileStalePaymentsHandler
    {
        return new ReconcileStalePaymentsHandler($this->payments, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function recordMandateCancellation(): RecordMandateCancellationHandler
    {
        return new RecordMandateCancellationHandler($this->mandates, $this->mandateRecorder());
    }

    public function registerSepaMandate(): RegisterSepaMandateHandler
    {
        return new RegisterSepaMandateHandler($this->gateway, $this->transactions, $this->outbox, $this->clock);
    }

    /**
     * @throws LogicException when no outbox sink was given (withOutbox())
     */
    public function relayOutbox(): RelayOutboxHandler
    {
        return new RelayOutboxHandler($this->outbox, $this->sink ?? throw new LogicException(self::SINK_MISSING), $this->clock, $this->policy);
    }

    public function requeueAfterInvalidCredentials(): RequeueAfterInvalidCredentialsHandler
    {
        return new RequeueAfterInvalidCredentialsHandler($this->declarations, $this->payments, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function resolveDeclarationOutcome(): ResolveDeclarationOutcomeHandler
    {
        return new ResolveDeclarationOutcomeHandler($this->declarationLocator(), $this->declarations, $this->clock, $this->unitOfWork);
    }

    public function resolvePaymentOutcome(): ResolvePaymentOutcomeHandler
    {
        return new ResolvePaymentOutcomeHandler($this->paymentLocator(), $this->payments, $this->clock, $this->unitOfWork);
    }

    public function revokeSepaMandate(): RevokeSepaMandateHandler
    {
        return new RevokeSepaMandateHandler($this->gateway, $this->transactions, $this->outbox, $this->clock, $this->policy);
    }

    public function syncAccount(): SyncAccountHandler
    {
        return new SyncAccountHandler($this->accountLocator(), $this->accountRefresher(), $this->accounts, $this->mandates);
    }

    public function syncAccounts(): SyncAccountsHandler
    {
        return new SyncAccountsHandler($this->accounts, $this->accountRefresher(), $this->policy);
    }

    public function trackAccount(): TrackAccountHandler
    {
        return new TrackAccountHandler($this->accounts, $this->mandates, $this->gateway, $this->ids, $this->clock, $this->unitOfWork);
    }

    private function accountLocator(): AccountLocator
    {
        return new AccountLocator($this->accounts);
    }

    private function accountRefresher(): AccountRefresher
    {
        return new AccountRefresher($this->gateway, $this->accounts, $this->clock, $this->unitOfWork);
    }

    private function mandateRecorder(): MandateRecorder
    {
        return new MandateRecorder($this->mandates, $this->accounts, $this->ids, $this->clock, $this->unitOfWork);
    }

    private function mandateGuard(): MandateGuard
    {
        return new MandateGuard($this->mandates, $this->policy);
    }

    private function declarationLocator(): DeclarationLocator
    {
        return new DeclarationLocator($this->declarations);
    }

    private function paymentLocator(): PaymentLocator
    {
        return new PaymentLocator($this->payments);
    }

    private function declarationSubmitter(): DeclarationSubmitter
    {
        return new DeclarationSubmitter($this->declarations, $this->gateway, $this->clock, $this->unitOfWork, $this->policy);
    }

    private function paymentSubmitter(): PaymentSubmitter
    {
        return new PaymentSubmitter($this->payments, $this->gateway, $this->clock, $this->unitOfWork, $this->policy);
    }
}
