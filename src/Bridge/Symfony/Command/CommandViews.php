<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\Command;

use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountErasure;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\AccountView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\ContributionAmountsView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationResultView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\DeclarationView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\EstimateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\MandateView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentResultView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\PaymentView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Dto\SubmissionErrorView;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaMandate;

/**
 * The rows of the views printed by the commands: identifiers, states, codes, amounts and instants only. Never a birth
 * date, an IBAN (except masked), a BIC, a holder, an address, nor the message or the description of an URSSAF error
 * (ADR 0010, ADR 0015 item 7).
 *
 * @mago-expect lint:too-many-methods One mapper per view printed by the commands: a flat catalogue.
 */
final class CommandViews
{
    private const MASK = '***';

    private const AMOUNT_LABELS = [
        'Total (€)',
        'Sales (€)',
        'Furnished rental (€)',
        'Commercial services (€)',
        'Other services (€)',
        'CIPAV (€)',
        'Vocational training (€)',
        'ACRE regularisation (€)',
        'Deduction (€)',
        'Liberatory sales (€)',
        'Liberatory furnished rental (€)',
        'Liberatory commercial services (€)',
        'Liberatory other services (€)',
        'Liberatory CIPAV (€)',
        'CMA sales (€)',
        'CMA services (€)',
        'CCI sales (€)',
        'CCI services (€)',
    ];

    /**
     * @return list<array{string, string}>
     */
    public static function mandate(MandateView $mandate): array
    {
        return [
            ['Mandate id', $mandate->mandateId],
            ['Account id', CommandReports::orDash($mandate->accountId)],
            ['Origin', $mandate->origin],
            ['State', $mandate->state],
            ['Cancellation origin', CommandReports::orDash($mandate->cancellationOrigin)],
            ['Notified at', $mandate->notifiedAt],
            ['Cancelled at', CommandReports::orDash($mandate->cancelledAt)],
        ];
    }

    /**
     * The account: whether a birth date is stored, never the date.
     *
     * @return list<array{string, string}>
     */
    public static function account(AccountView $account): array
    {
        return [
            ['Account id', $account->accountId],
            ['Tenant', $account->tenantId],
            ['SIRET', $account->siret],
            ['Birth date stored', CommandReports::yesNo($account->hasBirthDate)],
            ['Mandate present', CommandReports::yesNo($account->mandatePresent)],
            ['Periodicity', $account->periodicity],
            ['Affiliation date', CommandReports::orDash($account->affiliationDate)],
            ['ACRE exemption', CommandReports::yesNo($account->acreExemption)],
            ['Mandate state', CommandReports::orDash($account->mandate instanceof MandateView ? $account->mandate->state : null)],
            ['Last synced at', $account->lastSyncedAt],
        ];
    }

    /**
     * The state of a declaration record: error codes and kinds only, never the messages nor the descriptions of URSSAF.
     *
     * @return list<array{string, string}>
     */
    public static function declaration(DeclarationView $declaration): array
    {
        $status = $declaration->status;
        $result = $status->result;

        return [
            ['Record id', $declaration->recordId],
            ['SIRET', $declaration->siret],
            ['Period', self::period($declaration->periodicity, $declaration->periodYear, $declaration->periodNumber)],
            ['State', $status->state],
            ['Unknown outcome reason', CommandReports::orDash($status->outcomeUnknownReason)],
            ['Attempts', (string) $status->attempts],
            ['Error codes', self::errorCodes($status->errors)],
            ['Certificat', CommandReports::orDash($result instanceof DeclarationResultView ? $result->certificat : null)],
            ['idDeclaration', CommandReports::orDash($result instanceof DeclarationResultView ? $result->idDeclaration : null)],
            ['Due date', CommandReports::orDash($result instanceof DeclarationResultView ? $result->dueDate : null)],
            ...self::amounts($result?->amounts),
            ['Updated at', $status->updatedAt],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    public static function payment(PaymentView $payment): array
    {
        $status = $payment->status;
        $result = $status->result;

        return [
            ['Record id', $payment->recordId],
            ['Reference', $payment->reference],
            ['SIRET', $payment->siret],
            ['Period', self::period($payment->periodicity, $payment->periodYear, $payment->periodNumber)],
            ['Amount (€)', (string) $payment->amount],
            ['RUM', CommandReports::orDash($payment->rum)],
            ['State', $status->state],
            ['Unknown outcome reason', CommandReports::orDash($status->outcomeUnknownReason)],
            ['Attempts', (string) $status->attempts],
            ['Error codes', self::errorCodes($status->errors)],
            ['Certificat', CommandReports::orDash($result instanceof PaymentResultView ? $result->certificat : null)],
            ['idTelepaiement', CommandReports::orDash($result instanceof PaymentResultView ? $result->idTelepaiement : null)],
            ['Due date', CommandReports::orDash($result instanceof PaymentResultView ? $result->dueDate : null)],
            ['Updated at', $status->updatedAt],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    public static function estimate(EstimateView $estimate): array
    {
        return [
            ['Period', self::period($estimate->periodicity, $estimate->periodYear, $estimate->periodNumber)],
            ...self::amounts($estimate->amounts),
        ];
    }

    /**
     * What a local erasure removed: identifiers' counts only.
     *
     * @return list<array{string, string}>
     */
    public static function erasure(AccountErasure $erasure): array
    {
        return [
            ['Account id', CommandReports::orDash($erasure->accountId)],
            ['Mandates erased', (string) \count($erasure->mandateIds)],
            ['Declarations erased', (string) \count($erasure->declarationRecordIds)],
            ['Payments erased', (string) \count($erasure->paymentRecordIds)],
        ];
    }

    /**
     * A SEPA mandate: its RUM and its masked IBAN. Never the BIC, the holder, the address nor the bank.
     *
     * @return array{string, string}
     */
    public static function sepaMandate(SepaMandate $mandate): array
    {
        return [CommandReports::orDash($mandate->rum()?->value()), self::maskedIban($mandate->iban())];
    }

    /**
     * The first and last characters of the IBAN, "***" when it is missing or cannot be read as one.
     */
    public static function maskedIban(?string $iban): string
    {
        if ($iban === null) {
            return self::MASK;
        }
        try {
            return Iban::fromString($iban)->masked();
        } catch (InvalidValue) {
            return self::MASK;
        }
    }

    /**
     * "YYYY-MM" for a monthly period, "YYYY-Tn" for a quarterly one (the --period syntax).
     */
    public static function period(string $periodicity, int $year, int $number): string
    {
        return $periodicity === Periodicity::Monthly->value ? \sprintf('%04d-%02d', $year, $number) : \sprintf('%04d-T%d', $year, $number);
    }

    /**
     * @param list<SubmissionErrorView> $errors
     */
    private static function errorCodes(array $errors): string
    {
        return CommandReports::orDash($errors === [] ? null : implode(', ', array_map(static fn(SubmissionErrorView $error): string => \sprintf('%s (%s)', $error->code, $error->kind), $errors)));
    }

    /**
     * One row per amount; a dash for each of them when there is no amount (no result yet).
     *
     * @return list<array{string, string}>
     */
    private static function amounts(?ContributionAmountsView $amounts): array
    {
        $values = $amounts instanceof ContributionAmountsView ? self::amountValues($amounts) : [];
        $rows = [];
        foreach (self::AMOUNT_LABELS as $index => $label) {
            $rows[] = [$label, self::euros($values[$index] ?? null)];
        }

        return $rows;
    }

    /**
     * @return list<int|null> in the order of {@see self::AMOUNT_LABELS}
     */
    private static function amountValues(ContributionAmountsView $amounts): array
    {
        return [
            $amounts->total,
            $amounts->sales,
            $amounts->furnishedRental,
            $amounts->commercialServices,
            $amounts->otherServices,
            $amounts->cipav,
            $amounts->vocationalTraining,
            $amounts->acreRegularisation,
            $amounts->deduction,
            $amounts->liberatory->sales,
            $amounts->liberatory->furnishedRental,
            $amounts->liberatory->commercialServices,
            $amounts->liberatory->otherServices,
            $amounts->liberatory->cipav,
            $amounts->chamberFees->cmaSales,
            $amounts->chamberFees->cmaServices,
            $amounts->chamberFees->cciSales,
            $amounts->chamberFees->cciServices,
        ];
    }

    private static function euros(?int $amount): string
    {
        return CommandReports::orDash($amount === null ? null : (string) $amount);
    }
}
