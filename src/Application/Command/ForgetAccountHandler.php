<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountErasure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Exception\AccountErasureRefused;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\AccountLocator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Event\AccountForgotten;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;

/**
 * GDPR erasure of an auto-entrepreneur (ADR 0017): in one transaction, refuses what would break a workflow guarantee
 * (nothing is deleted then), deletes the declaration records, the payment records, the mandates and, last, the account,
 * checks that nothing of the SIRET is left, and records the "AccountForgotten" event (identifiers only, ADR 0010).
 *
 * The account may be missing: a SIRET without an account but with mandates or records is erased too, and the event
 * carries no account id. Nothing is sent to URSSAF: no gateway is injected. The two opt-ins of the command default to the
 * {@see WorkflowPolicy}.
 *
 * @mago-expect lint:cyclomatic-complexity One erasure with its refusals, its race re-check and the two shapes of its event.
 */
final readonly class ForgetAccountHandler
{
    public function __construct(
        private AccountLocator $locator,
        private AeAccountRepository $accounts,
        private MandateRepository $mandates,
        private DeclarationRecordRepository $declarations,
        private PaymentRecordRepository $payments,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @throws RecordNotFound        "ae_account.not_found": nothing is known for the selector (or an account of another tenant)
     * @throws AccountErasureRefused nothing was erased
     * @throws InvariantViolation    "ae_account.concurrent_modification" (rolled back), or a repository code
     */
    public function __invoke(ForgetAccount $command): AccountErasure
    {
        return $this->unitOfWork->run(fn(RecordedEvents $events): AccountErasure => $this->erase($command, $events));
    }

    private function erase(ForgetAccount $command, RecordedEvents $events): AccountErasure
    {
        $tenant = $command->tenantId;
        $account = $this->locator->find($tenant, $command->account);
        $siret = $this->locator->siretOf($tenant, $command->account);
        $mandates = $this->mandates->findBySiret($tenant, $siret);
        $declarationCounts = $this->declarations->countBySiret($tenant, $siret);
        $paymentCounts = $this->payments->countBySiret($tenant, $siret);

        if (!$account instanceof AeAccount && $mandates === [] && array_sum($declarationCounts) + array_sum($paymentCounts) === 0) {
            throw RecordNotFound::withIdentifier('ae_account', '***');
        }
        $this->assertErasable($account?->id(), $mandates, $declarationCounts, $paymentCounts, $command);

        $allowUnknownOutcomes = $command->allowUnknownOutcomes ?? $this->policy->allowErasureWithUnknownOutcomes();
        $declarationIds = $this->declarations->deleteBySiret($tenant, $siret, self::erasableDeclarationStates($allowUnknownOutcomes));
        $paymentIds = $this->payments->deleteBySiret($tenant, $siret, self::erasablePaymentStates($allowUnknownOutcomes));
        $mandateIds = array_map(static fn(Mandate $mandate): MandateId => $mandate->id(), $mandates);
        foreach ($mandateIds as $mandateId) {
            $this->mandates->delete($mandateId);
        }
        if ($account instanceof AeAccount) {
            $this->accounts->delete($account->id());
        }
        $this->assertNothingLeft($tenant, $siret, $account);

        $erasure = new AccountErasure(
            $account?->id()->value(),
            array_map(static fn(MandateId $id): string => $id->value(), $mandateIds),
            array_map(static fn(DeclarationRecordId $id): string => $id->value(), $declarationIds),
            array_map(static fn(PaymentRecordId $id): string => $id->value(), $paymentIds),
        );
        $now = Instant::fromDateTime($this->clock->now());
        if ($account instanceof AeAccount) {
            $account->forget($now, $mandateIds, $declarationIds, $paymentIds);
            $events->record(...$account->releaseEvents());

            return $erasure;
        }
        $events->record(new AccountForgotten(null, $tenant, $mandateIds, $declarationIds, $paymentIds, $now));

        return $erasure;
    }

    /**
     * Every state but submitting, and but outcome_unknown unless allowed.
     *
     * @return non-empty-list<DeclarationState>
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private static function erasableDeclarationStates(bool $allowUnknownOutcomes): array
    {
        $states = [];
        foreach (DeclarationState::cases() as $state) {
            if ($state === DeclarationState::Submitting || (!$allowUnknownOutcomes && $state === DeclarationState::OutcomeUnknown)) {
                continue;
            }
            $states[] = $state;
        }

        // @mago-expect analysis:invalid-return-statement The enum always has erasable states: mago does not infer a non-empty list.
        return $states;
    }

    /**
     * Every state but submitting, and but outcome_unknown unless allowed.
     *
     * @return non-empty-list<PaymentState>
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    private static function erasablePaymentStates(bool $allowUnknownOutcomes): array
    {
        $states = [];
        foreach (PaymentState::cases() as $state) {
            if ($state === PaymentState::Submitting || (!$allowUnknownOutcomes && $state === PaymentState::OutcomeUnknown)) {
                continue;
            }
            $states[] = $state;
        }

        // @mago-expect analysis:invalid-return-statement The enum always has erasable states: mago does not infer a non-empty list.
        return $states;
    }

    /**
     * Checks in a deterministic order: active mandate, submission in flight, unknown outcome.
     *
     * @param list<Mandate>      $mandates
     * @param array<string, int> $declarationCounts declarations of the SIRET per state
     * @param array<string, int> $paymentCounts     payments of the SIRET per state
     *
     * @throws AccountErasureRefused
     */
    private function assertErasable(?AccountId $accountId, array $mandates, array $declarationCounts, array $paymentCounts, ForgetAccount $command): void
    {
        $active = \count(array_filter($mandates, static fn(Mandate $mandate): bool => $mandate->isActive()));
        if (!($command->allowActiveMandate ?? $this->policy->allowErasureWithActiveMandate()) && $active > 0) {
            throw AccountErasureRefused::activeMandate($accountId, $active);
        }

        $submitting = $declarationCounts[DeclarationState::Submitting->value] + $paymentCounts[PaymentState::Submitting->value];
        if ($submitting > 0) {
            throw AccountErasureRefused::submissionInFlight($accountId, $submitting);
        }

        $unknown = $declarationCounts[DeclarationState::OutcomeUnknown->value] + $paymentCounts[PaymentState::OutcomeUnknown->value];
        if (!($command->allowUnknownOutcomes ?? $this->policy->allowErasureWithUnknownOutcomes()) && $unknown > 0) {
            throw AccountErasureRefused::unknownOutcomes($accountId, $unknown);
        }
    }

    /**
     * @throws InvariantViolation "ae_account.concurrent_modification"
     */
    private function assertNothingLeft(TenantId $tenant, Siret $siret, ?AeAccount $account): void
    {
        $left = array_sum($this->declarations->countBySiret($tenant, $siret)) > 0
            || array_sum($this->payments->countBySiret($tenant, $siret)) > 0
            || $this->mandates->findBySiret($tenant, $siret) !== []
            || (!$account instanceof AeAccount && $this->accounts->findBySiret($tenant, $siret) instanceof AeAccount);
        if (!$left) {
            return;
        }

        throw InvariantViolation::because(
            AeAccountRepository::CONCURRENT_MODIFICATION,
            'The auto-entrepreneur was changed by another process while it was being erased: nothing was erased, run the erasure again.',
            $account instanceof AeAccount ? ['id' => $account->id()->value()] : [],
        );
    }
}
