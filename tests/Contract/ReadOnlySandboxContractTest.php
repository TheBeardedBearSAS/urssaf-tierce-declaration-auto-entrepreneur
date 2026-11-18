<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Operation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\RejectionKind;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\CalendarDate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ApiPaths;

/**
 * Live, read-only checks against the URSSAF sandbox (020, 040, 075). Opt-in, never part of coverage or mutation gates.
 *
 * Besides asserting, each test writes a non-failing note (standard error and var/contract/observations.log) with what the
 * sandbox answered for an open point of docs/api-reference.md section 5. Notes hold structure and catalogue codes only.
 */
#[Group('contract')]
final class ReadOnlySandboxContractTest extends TestCase
{
    private SandboxSession $session;

    protected function setUp(): void
    {
        $this->session = SandboxSession::readOnly(self::class);
    }

    public function testObtainsAnAccessToken(): void
    {
        $token = $this->session->sandbox->tokenProvider->accessToken(
            $this->session->sandbox->credentials,
            Operation::FindAccount,
        );

        static::assertNotSame('', $token);
    }

    public function testAnUnknownAccountIsRejectedWithACataloguedCode(): void
    {
        try {
            $this->session->sandbox->gateway->findAccount(
                $this->session->sandbox->tenantId,
                Siret::fromString(SandboxData::UNKNOWN_SIRET),
            );
        } catch (UrssafRequestRejected $rejected) {
            static::assertSame(Operation::FindAccount, $rejected->operation());
            static::assertGreaterThan(0, $rejected->errors()->count(), 'A rejection carries at least one error.');
            $this->session->assertCatalogued($rejected->errors());
            $this->session->note('error-codes', \sprintf('020 unknown account: HTTP %d, kind %s, codes [%s]', $rejected->httpStatus(), $rejected->kind()->value, implode(',', $rejected->errors()->codes())));

            return;
        }

        $this->session->note('error-codes', '020 unknown account: ACCEPTED (an account answered for the all-zero SIRET)');
        static::addToAssertionCount(1);
    }

    /**
     * Open point: the `Compte` fields that only the PDF documents (`date_etat`, `exoAccrePresent`).
     */
    public function testFindsTheAccountAndRecordsItsShape(): void
    {
        $siret = $this->session->requireSiret();

        $status = $this->session->sandbox->gateway->findAccount($this->session->sandbox->tenantId, $siret);

        $exchange = $this->session->sandbox->http->last('GET', ApiPaths::findAccount());
        static::assertInstanceOf(Exchange::class, $exchange);
        static::assertSame(200, $exchange->status);
        static::assertSame('bool', $exchange->responseShape['mandatPresent'] ?? null, 'mandatPresent is a JSON boolean.');
        static::assertSame('string', $exchange->responseShape['periodicite'] ?? null, 'periodicite is a JSON string.');

        $hasAffiliationDate = $exchange->hasResponseKey('date_etat');
        $hasAcreFlag = $exchange->hasResponseKey('exoAccrePresent');
        static::assertSame($hasAffiliationDate, $status->affiliationDate() instanceof CalendarDate, 'date_etat is mapped when (and only when) it is sent.');
        static::assertSame($hasAcreFlag, $status->acreExemption() !== null, 'exoAccrePresent is mapped when (and only when) it is sent.');

        $this->session->note(
            'compte-pdf-only-fields',
            \sprintf(
                'date_etat=%s exoAccrePresent=%s keys=[%s] mandatPresent=%s periodicity=%s',
                $hasAffiliationDate ? 'present' : 'absent',
                $hasAcreFlag ? 'present' : 'absent',
                implode(',', array_keys($exchange->responseShape)),
                $status->mandatePresent() ? 'true' : 'false',
                $status->periodicity()->value,
            ),
        );
    }

    /**
     * Open points: prd/sb response shape (wrapped or direct), and the catalogued rejection when there is no mandate.
     */
    public function testEstimatesTheContributions(): void
    {
        $siret = $this->session->requireSiret();

        try {
            $estimate = $this->session->sandbox->gateway->estimateContributions(
                $this->session->sandbox->tenantId,
                $siret,
                SandboxData::turnover(),
            );
        } catch (UrssafRequestRejected $rejected) {
            // Documented business rejection (e.g. MDT_INCONNU without a mandate, MDT_HORS_PERIODE): the codes must be known.
            $this->session->noteRejection('estimate-040', $rejected);

            return;
        }

        $exchange = $this->session->sandbox->http->last('POST', ApiPaths::estimateContributions());
        static::assertInstanceOf(Exchange::class, $exchange);
        static::assertSame(200, $exchange->status);
        static::assertFalse($estimate->amounts()->total()->isNegative());
        $this->session->note(
            'response-shape',
            \sprintf('040 %s (wrapper key %s)', $exchange->shapeUnder(Exchange::WRAPPER_ESTIMATE_OR_DECLARATION), Exchange::WRAPPER_ESTIMATE_OR_DECLARATION),
        );
    }

    /**
     * Open point: what 075 answers when the account has no SEPA mandate (an empty list, or a 404).
     */
    public function testListsTheSepaMandates(): void
    {
        $siret = $this->session->requireSiret();

        try {
            $mandates = $this->session->sandbox->gateway->listSepaMandates($this->session->sandbox->tenantId, $siret);
        } catch (UrssafRequestRejected $rejected) {
            $this->session->assertCatalogued($rejected->errors());
            static::assertSame(Operation::ListSepaMandates, $rejected->operation());
            $this->session->note(
                'sepa-075',
                \sprintf('rejected: HTTP %d, kind %s, codes [%s]', $rejected->httpStatus(), $rejected->kind()->value, implode(',', $rejected->errors()->codes())),
            );
            static::assertTrue($rejected->is(RejectionKind::NotFound) || $rejected->httpStatus() !== 404, '075 maps a 404 to not_found.');

            return;
        }

        $exchange = $this->session->sandbox->http->last('GET', ApiPaths::listSepaMandates());
        static::assertInstanceOf(Exchange::class, $exchange);
        static::assertSame(200, $exchange->status);

        $masked = 0;
        foreach ($mandates as $mandate) {
            $iban = $mandate->iban();
            if ($iban !== null && preg_match('/[^A-Za-z0-9]|X{4}/', $iban) === 1) {
                ++$masked;
            }
        }

        $this->session->note(
            'sepa-075',
            \sprintf('200 with %d mandate(s), IBAN anonymised on %d of them (heuristic: a masking character)', \count($mandates), $masked),
        );
    }
}
