<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Testing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Override;
use SensitiveParameter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Clock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\OperationRetryClass;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiError;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\TierceDeclarationGateway;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountStatus;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Certificate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ChamberFees;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\ContributionAmounts;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\DeclarationResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Estimate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\LiberatoryContributions;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\Turnover;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Money\Euros;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentResult;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriods;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\MonthlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\QuarterlyPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\NewSepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Exception\ApiErrorCode;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\UtcSystemClock;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ErrorClassifier;
use Throwable;

/**
 * An in-memory URSSAF "API Tierce Declaration auto-entrepreneur" platform for the tests of a host application: it keeps
 * the auto-entrepreneurs, their mandates, their closed periods and their SEPA mandates, and answers the nine operations
 * like the real API would, without any network. It never contacts URSSAF.
 *
 * Scoping: accounts are global (one URSSAF registry, keyed by SIRET); a mandate is exclusive (one holder tenant per
 * SIRET); the certificate of a period is stable and the declaration identifier changes on every 050 (last wins,
 * api-reference section 1.2).
 *
 * Default behaviour (every step can be scripted, see failNext(), refuseNext(), loseNextAnswer()). For 020 to 060 an
 * unknown SIRET is CPTE_INCONNU (HTTP 400, published). Rows marked "convention" answer a case the specification does not
 * publish.
 * - 020: the account status, `mandatePresent` telling whether the calling tenant holds the mandate;
 * - 030: a birth date that differs is PARAM_INVALIDE (convention: the code is not published); a mandate held by another
 *   tenant is SIRET_DEJA_MANDATE; otherwise the tenant holds the mandate (idempotent for the holder);
 * - 031: a birth date that differs is PARAM_INVALIDE (convention); a mandate not held by the tenant is PARAM_INVALIDE
 *   "Aucun mandat a supprimer" (the PDF example); otherwise the mandate is released;
 * - 040: a tenant without the mandate gets MDT_INCONNU; otherwise the estimate of the default amounts for the period
 *   before today (Europe/Paris), see setAmounts() and setEstimatePeriod();
 * - 050: MDT_INCONNU without the mandate; PERIODICITE_INVALIDE when the periodicity of the period differs from the
 *   account; APPEL_INCONNU for a closed period (see closePeriod()); otherwise the amounts, the due date (last day of the
 *   month after the period), a certificate stable per period and a new idDeclaration;
 * - 060: MDT_INCONNU without the mandate; otherwise the certificate of the period and a new idTelepaiement. There is no
 *   duplicate detection (the specification publishes none);
 * - 070: unknown SIRET is HTTP 404 (convention); the same IBAN already active is ERREUR_MANDAT_ALREADYEXIST (HTTP 409);
 *   otherwise a SEPA mandate with a new RUM, no creditor;
 * - 071: unknown SIRET or RUM is HTTP 404 (convention); otherwise the mandate is removed;
 * - 075: unknown SIRET is HTTP 404 (convention); otherwise the active SEPA mandates, none being an empty list.
 *
 * Rejections are classified by the real ErrorClassifier (ADR 0005). Never use it outside tests: nothing is sent to
 * URSSAF, and the HTTP gateway is the only one the standalone builder accepts in production.
 *
 * Implements the nine operations plus the scripting and inspection API, hence the method count and complexity.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
final class SimulatedAeGateway implements TierceDeclarationGateway
{
    public const DEFAULT_SALES_EUROS = 220;

    private const CERTIFICATE_FORMAT = '%08d';

    private const DECLARATION_ID_FORMAT = 'SIMDECL%016d';

    private const PAYMENT_ID_FORMAT = 'SIMPAY%017d';

    private const RUM_FORMAT = 'SIMRUM%08d';

    private const BUSINESS_TIME_ZONE = 'Europe/Paris';

    private readonly SimulatedScript $script;

    private readonly SimulatedSequence $certificates;

    private readonly SimulatedSequence $declarationIds;

    private readonly SimulatedSequence $paymentIds;

    private readonly SimulatedSequence $rums;

    private ContributionAmounts $amounts;

    private ?DeclarationPeriod $estimatePeriod = null;

    /** @var array<string, SimulatedAccount> by SIRET */
    private array $accounts = [];

    /** @var list<Operation> */
    private array $calls = [];

    public function __construct(private readonly Clock $clock = new UtcSystemClock())
    {
        $this->script = new SimulatedScript();
        $this->certificates = new SimulatedSequence(self::CERTIFICATE_FORMAT);
        $this->declarationIds = new SimulatedSequence(self::DECLARATION_ID_FORMAT);
        $this->paymentIds = new SimulatedSequence(self::PAYMENT_ID_FORMAT);
        $this->rums = new SimulatedSequence(self::RUM_FORMAT);
        $this->amounts = ContributionAmounts::of(
            Euros::of(self::DEFAULT_SALES_EUROS),
            Euros::of(self::DEFAULT_SALES_EUROS),
            null,
            null,
            null,
            null,
            null,
            LiberatoryContributions::none(),
            ChamberFees::none(),
            null,
            null,
        );
    }

    /**
     * The next call of the operation throws the failure before anything is processed (for example
     * `UrssafUnavailable::withStatus(...)`, `UrssafAuthenticationFailed::withStatus(...)`, or an `OutcomeUnknown` for
     * "sent but not processed"); failures queued for the same operation are thrown in order.
     */
    public function failNext(Operation $operation, Throwable $failure): void
    {
        $this->script->fail($operation, $failure);
    }

    /**
     * The next call of the operation is refused (HTTP 400) with this error, classified like the real API (ADR 0005);
     * refusals queued for the same operation are answered in order. Use the codes of {@see ApiErrorCode}.
     */
    public function refuseNext(Operation $operation, string $code, string $message, ?string $description): void
    {
        $this->script->refuse($operation, ApiErrors::of(ApiError::of($code, $message, $description)));
    }

    /**
     * The next call of the write operation is processed (state changed, identifier consumed) but its answer is lost: an
     * OutcomeUnknown (HTTP 504) is thrown, the case operators must reconcile (ADR 0003). "Sent but not processed" is
     * scripted with failNext().
     *
     * @throws LogicException when the operation is safe to retry (it has no unknown outcome)
     */
    public function loseNextAnswer(Operation $operation): void
    {
        if ($operation->retryClass() === OperationRetryClass::Safe) {
            throw new LogicException(\sprintf(
                'Only the writes 030, 031, 050, 060, 070 and 071 have an unknown outcome; method %s does not.',
                $operation->value,
            ));
        }

        $this->script->loseAnswer($operation);
    }

    /**
     * An existing auto-entrepreneur account (no mandate, no closed period, no SEPA mandate), without affiliation date nor
     * ACRE exemption. No call is recorded.
     *
     * @throws LogicException when the SIRET is already known
     */
    public function knowAccount(
        Siret $siret,
        #[SensitiveParameter]
        CalendarDate $birthDate,
        Periodicity $periodicity = Periodicity::Monthly,
    ): void {
        $this->knowAccountWith($siret, $birthDate, $periodicity, null, null);
    }

    /**
     * An existing auto-entrepreneur account with the optional facts that method 020 returns. No call is recorded.
     *
     * @throws LogicException when the SIRET is already known
     */
    public function knowAccountWith(
        Siret $siret,
        #[SensitiveParameter]
        CalendarDate $birthDate,
        Periodicity $periodicity,
        ?CalendarDate $affiliationDate,
        ?bool $acreExemption,
    ): void {
        if (\array_key_exists($siret->toString(), $this->accounts)) {
            throw new LogicException('The account is already known to the simulator.');
        }

        $this->accounts[$siret->toString()] = new SimulatedAccount($siret, $birthDate, $periodicity, $affiliationDate, $acreExemption);
    }

    /**
     * The tenant holds the mandate of the SIRET, obtained outside the package (no call recorded); it replaces any holder.
     *
     * @throws LogicException when the SIRET is not known
     */
    public function mandate(Siret $siret, TenantId $tenantId): void
    {
        $this->account($siret)->holdBy($tenantId);
    }

    /**
     * The mandate disappears at URSSAF (for example cancelled by the auto-entrepreneur): method 020 reports it as absent.
     *
     * @throws LogicException when the SIRET is not known
     */
    public function withdrawMandate(Siret $siret): void
    {
        $this->account($siret)->holdBy(null);
    }

    /**
     * The periodicity returned by method 020 from now on.
     *
     * @throws LogicException when the SIRET is not known
     */
    public function setPeriodicity(Siret $siret, Periodicity $periodicity): void
    {
        $this->account($siret)->setPeriodicity($periodicity);
    }

    /**
     * The period is closed: method 050 answers APPEL_INCONNU for it.
     *
     * @throws LogicException when the SIRET is not known
     */
    public function closePeriod(Siret $siret, DeclarationPeriod $period): void
    {
        $this->account($siret)->closePeriod($period);
    }

    /**
     * Amounts of the next estimates and declarations (methods 040 and 050), until changed.
     */
    public function setAmounts(ContributionAmounts $amounts): void
    {
        $this->amounts = $amounts;
    }

    /**
     * Period of the next estimates (method 040), until changed; by default the month or quarter before today.
     */
    public function setEstimatePeriod(DeclarationPeriod $period): void
    {
        $this->estimatePeriod = $period;
    }

    /**
     * @return list<string> the operation numbers of every call received, in order (failed, refused and lost ones included)
     */
    public function calls(): array
    {
        return array_map(static fn(Operation $operation): string => $operation->value, $this->calls);
    }

    public function isMandated(Siret $siret, TenantId $tenantId): bool
    {
        return ($this->accounts[$siret->toString()] ?? null)?->isMandatedBy($tenantId) ?? false;
    }

    #[Override]
    public function findAccount(TenantId $tenantId, Siret $siret): AccountStatus
    {
        return $this->process(
            Operation::FindAccount,
            fn(): AccountStatus => $this->knownAccount(Operation::FindAccount, $siret)->status($tenantId),
        );
    }

    #[Override]
    public function notifyMandate(TenantId $tenantId, #[SensitiveParameter] AccountReference $account): void
    {
        $this->process(Operation::NotifyMandate, function () use ($tenantId, $account): null {
            $known = $this->matchingAccount(Operation::NotifyMandate, $account);
            if ($known->isHeldByAnotherThan($tenantId)) {
                throw self::refusal(Operation::NotifyMandate, self::mandateHeldElsewhere());
            }
            $known->holdBy($tenantId);

            return null;
        });
    }

    #[Override]
    public function cancelMandate(TenantId $tenantId, #[SensitiveParameter] AccountReference $account): void
    {
        $this->process(Operation::CancelMandate, function () use ($tenantId, $account): null {
            $known = $this->matchingAccount(Operation::CancelMandate, $account);
            if (!$known->isMandatedBy($tenantId)) {
                throw self::refusal(Operation::CancelMandate, self::noMandateToDelete());
            }
            $known->holdBy(null);

            return null;
        });
    }

    #[Override]
    public function estimateContributions(TenantId $tenantId, Siret $siret, Turnover $turnover): Estimate
    {
        return $this->process(Operation::Estimate, function () use ($tenantId, $siret): Estimate {
            $known = $this->mandatedAccount(Operation::Estimate, $tenantId, $siret);

            return Estimate::of($this->amounts, $this->estimatePeriod ?? $this->periodBeforeToday($known->periodicity()));
        });
    }

    #[Override]
    public function declareTurnover(TenantId $tenantId, Siret $siret, TurnoverDeclaration $declaration): DeclarationResult
    {
        return $this->process(Operation::Declare, function () use ($tenantId, $siret, $declaration): DeclarationResult {
            $known = $this->mandatedAccount(Operation::Declare, $tenantId, $siret);
            $period = $declaration->period();
            if ($period->periodicity() !== $known->periodicity()) {
                throw self::refusal(Operation::Declare, self::periodicityInvalid());
            }
            if (!$known->isOpen($period)) {
                throw self::refusal(Operation::Declare, self::periodNotOpen());
            }

            return DeclarationResult::of(
                Certificate::fromString($known->certificateFor($period, $this->certificates)),
                $this->declarationIds->next(),
                self::dueDate($period),
                $this->amounts,
            );
        });
    }

    #[Override]
    public function payContributions(TenantId $tenantId, Siret $siret, #[SensitiveParameter] PaymentOrder $order): PaymentResult
    {
        return $this->process(Operation::Pay, function () use ($tenantId, $siret, $order): PaymentResult {
            $known = $this->mandatedAccount(Operation::Pay, $tenantId, $siret);
            $period = $order->period();

            return PaymentResult::of(
                Certificate::fromString($known->certificateFor($period, $this->certificates)),
                $this->paymentIds->next(),
                self::dueDate($period),
                $period,
            );
        });
    }

    #[Override]
    public function registerSepaMandate(TenantId $tenantId, Siret $siret, #[SensitiveParameter] NewSepaMandate $mandate): SepaMandate
    {
        return $this->process(Operation::RegisterSepaMandate, function () use ($siret, $mandate): SepaMandate {
            $known = $this->sepaAccount(Operation::RegisterSepaMandate, $siret);
            $iban = $mandate->iban()->value();
            if ($known->hasSepaMandateWithIban($iban)) {
                throw self::conflict(Operation::RegisterSepaMandate, self::sepaMandateExists());
            }
            $rum = Rum::fromString($this->rums->next());
            $created = new SepaMandate($rum, null, $iban, $mandate->holder()->value(), $known->siret(), null);
            $known->addSepaMandate($rum, $created);

            return $created;
        });
    }

    #[Override]
    public function revokeSepaMandate(TenantId $tenantId, SepaRevocation $revocation): void
    {
        $this->process(Operation::RevokeSepaMandate, function () use ($revocation): null {
            $known = $this->sepaAccount(Operation::RevokeSepaMandate, $revocation->siret());
            if (!$known->removeSepaMandate($revocation->rum())) {
                throw self::notFound(Operation::RevokeSepaMandate);
            }

            return null;
        });
    }

    #[Override]
    public function listSepaMandates(TenantId $tenantId, Siret $siret): array
    {
        return $this->process(
            Operation::ListSepaMandates,
            fn(): array => $this->sepaAccount(Operation::ListSepaMandates, $siret)->sepaMandates(),
        );
    }

    /**
     * The common path of the operations: record the call, throw a scripted failure, then apply a scripted refusal or the
     * built-in rules; a lost answer throws OutcomeUnknown whatever the outcome (the state change of an accepted call is
     * kept, a refusal is swallowed).
     *
     * @template T
     *
     * @param-immediately-invoked-callable $rules
     * @param Closure(): T $rules
     *
     * @throws Throwable
     *
     * @return T
     */
    private function process(Operation $operation, Closure $rules): mixed
    {
        $this->calls[] = $operation;

        $failure = $this->script->nextFailure($operation);
        if ($failure instanceof Throwable) {
            throw $failure;
        }

        $lost = $this->script->takeLostAnswer($operation);
        $refusal = $this->script->nextRefusal($operation);
        if (!$lost) {
            return $this->apply($operation, $refusal, $rules);
        }

        try {
            $this->apply($operation, $refusal, $rules);
            // @mago-expect lint:no-empty-catch-clause The answer is lost: a refusal never reaches the caller, only OutcomeUnknown does.
        } catch (UrssafRequestRejected) {
        }

        throw OutcomeUnknown::fromUnavailable(UrssafUnavailable::withStatus($operation, 504, null, ApiErrors::none()));
    }

    /**
     * A scripted refusal replaces the built-in rules.
     *
     * @template T
     *
     * @param-immediately-invoked-callable $rules
     * @param Closure(): T $rules
     *
     * @throws UrssafRequestRejected
     *
     * @return T
     */
    private function apply(Operation $operation, ?ApiErrors $refusal, Closure $rules): mixed
    {
        if ($refusal instanceof ApiErrors) {
            throw self::refusal($operation, $refusal);
        }

        return $rules();
    }

    private function account(Siret $siret): SimulatedAccount
    {
        return $this->accounts[$siret->toString()] ?? throw new LogicException('The account is not known to the simulator.');
    }

    /**
     * Operations 020 to 060: an unknown SIRET is CPTE_INCONNU.
     */
    private function knownAccount(Operation $operation, Siret $siret): SimulatedAccount
    {
        return $this->accounts[$siret->toString()] ?? throw self::refusal($operation, self::accountUnknown());
    }

    /**
     * Operations 070, 071 and 075: an unknown SIRET is a 404.
     */
    private function sepaAccount(Operation $operation, Siret $siret): SimulatedAccount
    {
        return $this->accounts[$siret->toString()] ?? throw self::notFound($operation);
    }

    /**
     * Methods 030 and 031: the account with this birth date.
     */
    private function matchingAccount(Operation $operation, AccountReference $reference): SimulatedAccount
    {
        $known = $this->knownAccount($operation, $reference->siret());
        if (!$known->hasBirthDate($reference->birthDate())) {
            throw self::refusal($operation, self::invalidParameter());
        }

        return $known;
    }

    /**
     * Methods 040, 050 and 060: the account, held by the calling tenant.
     */
    private function mandatedAccount(Operation $operation, TenantId $tenantId, Siret $siret): SimulatedAccount
    {
        $known = $this->knownAccount($operation, $siret);
        if (!$known->isMandatedBy($tenantId)) {
            throw self::refusal($operation, self::mandateUnknown());
        }

        return $known;
    }

    private function periodBeforeToday(Periodicity $periodicity): DeclarationPeriod
    {
        $today = CalendarDate::fromDateTime($this->clock->now(), new DateTimeZone(self::BUSINESS_TIME_ZONE));
        $year = $today->year();
        if ($periodicity === Periodicity::Monthly) {
            return $today->month() === 1
                ? MonthlyPeriod::of($year - 1, 12)
                : MonthlyPeriod::of($year, $today->month() - 1);
        }
        $quarter = intdiv($today->month() - 1, 3) + 1;

        return $quarter === 1 ? QuarterlyPeriod::of($year - 1, 4) : QuarterlyPeriod::of($year, $quarter - 1);
    }

    /**
     * The last day of the month after the end of the period.
     */
    private static function dueDate(DeclarationPeriod $period): CalendarDate
    {
        $number = DeclarationPeriods::numberOf($period);
        $endMonth = $period->periodicity() === Periodicity::Monthly ? $number : 3 * $number;
        $first = new DateTimeImmutable(\sprintf('%04d-%02d-01', $period->year(), $endMonth), new DateTimeZone('UTC'));

        return CalendarDate::fromString($first->modify('last day of next month')->format('Y-m-d'));
    }

    private static function refusal(Operation $operation, ApiErrors $errors): UrssafRequestRejected
    {
        return UrssafRequestRejected::withErrors($operation, 400, $errors, ErrorClassifier::classify($operation, 400, $errors));
    }

    private static function conflict(Operation $operation, ApiErrors $errors): UrssafRequestRejected
    {
        return UrssafRequestRejected::withErrors($operation, 409, $errors, ErrorClassifier::classify($operation, 409, $errors));
    }

    private static function notFound(Operation $operation): UrssafRequestRejected
    {
        $errors = ApiErrors::none();

        return UrssafRequestRejected::withErrors($operation, 404, $errors, ErrorClassifier::classify($operation, 404, $errors));
    }

    private static function accountUnknown(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::AccountUnknown->value,
            'Compte inconnu',
            'Aucun compte Auto-Entrepreneur actif trouvé avec ces paramètres',
        ));
    }

    private static function invalidParameter(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::InvalidParameter->value,
            'Un des paramètres de votre demande est invalide',
            'La date de naissance ne correspond pas au SIRET',
        ));
    }

    private static function noMandateToDelete(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::InvalidParameter->value,
            'Un des paramètres de votre demande est invalide',
            'Aucun mandat à supprimer correspondant à vos données d\'entrée',
        ));
    }

    private static function mandateHeldElsewhere(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::SiretAlreadyMandated->value,
            'Le SIRET est déjà mandaté',
            'Il existe déjà un autre établissement mandataire pour ce SIRET',
        ));
    }

    private static function mandateUnknown(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::MandateUnknown->value,
            'Mandat inconnu',
            'Aucun mandat actif pour ce SIRET et ce partenaire',
        ));
    }

    private static function periodicityInvalid(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::PeriodicityInvalid->value,
            'Périodicité invalide',
            'La périodicité de la période ne correspond pas à celle du compte',
        ));
    }

    private static function periodNotOpen(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::PeriodNotOpen->value,
            'Appel inconnu',
            'La période n\'est pas ouverte à la déclaration',
        ));
    }

    private static function sepaMandateExists(): ApiErrors
    {
        return ApiErrors::of(ApiError::of(
            ApiErrorCode::SepaMandateAlreadyExists->value,
            'Mandat SEPA existant',
            'Un mandat SEPA est déjà actif pour ce SIRET et cet IBAN',
        ));
    }
}
