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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\ApiErrors;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountReference;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Contribution\TurnoverDeclaration;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentOrder;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\DeclarationPeriod;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Period\Periodicity;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Iban;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\Rum;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Sepa\SepaRevocation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\Wire\ApiPaths;

/**
 * Live checks that WRITE sandbox state and e-mail the auto-entrepreneur (030, 031, 050, 060, 070, 071). Skipped unless
 * URSSAF_SANDBOX_WRITE=1. Each test leaves the sandbox as it found it: a mandate or a SEPA mandate it created is
 * cancelled / revoked in a `finally`, one that already existed is never touched.
 *
 * The sandbox is the judge: a catalogued rejection is an accepted outcome (the test then notes it); an unknown error
 * code, an unreadable answer or a failed authentication fails the test.
 */
#[Group('contract')]
final class WriteSandboxContractTest extends TestCase
{
    private SandboxSession $session;

    protected function setUp(): void
    {
        $this->session = SandboxSession::writing(self::class);
    }

    public function testNotifiesThenCancelsTheMandate(): void
    {
        $account = $this->session->requireAccount();
        $gateway = $this->session->sandbox->gateway;
        $tenant = $this->session->sandbox->tenantId;

        try {
            $gateway->notifyMandate($tenant, $account);
        } catch (UrssafRequestRejected $rejected) {
            // Typically SIRET_DEJA_MANDATE: the mandate exists and is not ours to cancel.
            $this->session->noteRejection('mandate-030', $rejected);

            return;
        }

        $this->session->note('mandate-030', 'accepted');

        try {
            $gateway->cancelMandate($tenant, $account);
        } catch (UrssafRequestRejected $rejected) {
            $this->session->noteRejection('mandate-031', $rejected);
            static::fail('The mandate notified by this test could not be cancelled: cancel it by hand in the sandbox.');
        }

        $this->session->note('mandate-031', 'accepted');
        static::addToAssertionCount(1);
    }

    /**
     * Open points: response shape (wrapped or direct) and periods sent as integers.
     */
    public function testDeclaresTheTurnover(): void
    {
        $account = $this->session->requireAccount();

        $lease = SandboxMandateLease::acquire($this->session, $account);

        try {
            $gateway = $this->session->sandbox->gateway;
            $tenant = $this->session->sandbox->tenantId;
            $period = SandboxData::lastPeriod($gateway->findAccount($tenant, $account->siret())->periodicity());

            try {
                $result = $gateway->declareTurnover($tenant, $account->siret(), TurnoverDeclaration::of($period, SandboxData::turnover()));
            } catch (UrssafRequestRejected $rejected) {
                $this->session->noteRejection('declare-050', $rejected);
                $this->assertPeriodSent('POST', ApiPaths::declareTurnover(), $rejected->errors());

                return;
            }

            $exchange = $this->assertPeriodSent('POST', ApiPaths::declareTurnover(), ApiErrors::none());
            static::assertSame(200, $exchange->status);
            static::assertNotSame('', $result->certificate()->value());
            $this->session->note(
                'response-shape',
                \sprintf('050 %s (wrapper key %s), periode sent as annee=%s %s=%s', $exchange->shapeUnder(Exchange::WRAPPER_ESTIMATE_OR_DECLARATION), Exchange::WRAPPER_ESTIMATE_OR_DECLARATION, $exchange->requestPeriodType('annee'), $this->numberField($period), $exchange->requestPeriodType($this->numberField($period))),
            );
        } finally {
            $lease?->release();
        }
    }

    /**
     * Open points: response shape, periods as integers, and the `iban` / `rum` rule: each combination is tried and what
     * the sandbox answered is noted (neither, IBAN only, RUM only when the account has a SEPA mandate).
     */
    public function testPaysTheContributions(): void
    {
        $account = $this->session->requireAccount();

        $lease = SandboxMandateLease::acquire($this->session, $account);

        try {
            $gateway = $this->session->sandbox->gateway;
            $tenant = $this->session->sandbox->tenantId;
            $period = SandboxData::lastPeriod($gateway->findAccount($tenant, $account->siret())->periodicity());

            $variants = ['neither' => [null, null], 'iban-only' => [SandboxData::exampleIban(), null]];
            try {
                foreach ($gateway->listSepaMandates($tenant, $account->siret()) as $mandate) {
                    $rum = $mandate->rum();
                    if (!$rum instanceof Rum) {
                        continue;
                    }

                    $variants['rum-only'] = [null, $rum];

                    break;
                }
            } catch (UrssafRequestRejected $rejected) {
                $this->session->noteRejection('sepa-075', $rejected);
            }

            foreach ($variants as $name => [$iban, $rum]) {
                $this->pay($account, $period, $name, $iban, $rum);
            }
        } finally {
            $lease?->release();
        }
    }

    public function testRegistersThenRevokesASepaMandate(): void
    {
        $account = $this->session->requireAccount();
        $siret = $account->siret();
        $gateway = $this->session->sandbox->gateway;
        $tenant = $this->session->sandbox->tenantId;

        try {
            $mandate = $gateway->registerSepaMandate($tenant, $siret, SandboxData::newSepaMandate());
        } catch (UrssafRequestRejected $rejected) {
            // Fictitious bank data may be refused (IBAN, bank unknown) or the account may already hold a mandate.
            $this->session->noteRejection('sepa-070', $rejected);

            return;
        }

        $this->session->note('sepa-070', 'accepted');
        $rum = $mandate->rum();
        static::assertInstanceOf(Rum::class, $rum, 'An accepted registration returns the RUM.');

        try {
            $gateway->revokeSepaMandate($tenant, SepaRevocation::of($siret, $rum, SandboxData::revocationDate()));
        } catch (UrssafRequestRejected $rejected) {
            $this->session->noteRejection('sepa-071', $rejected);
            static::fail('The SEPA mandate registered by this test could not be revoked: revoke it by hand in the sandbox.');
        }

        $exchange = $this->session->sandbox->http->last('DELETE', SandboxHttpClient::REVOKE_ROUTE);
        static::assertInstanceOf(Exchange::class, $exchange);
        static::assertSame(204, $exchange->status);
        $this->session->note('sepa-071', 'accepted (204)');
    }

    private function pay(AccountReference $account, DeclarationPeriod $period, string $variant, ?Iban $iban, ?Rum $rum): void
    {
        $order = PaymentOrder::of($period, SandboxData::paymentAmount(), $iban, $rum);

        try {
            $result = $this->session->sandbox->gateway->payContributions($this->session->sandbox->tenantId, $account->siret(), $order);
        } catch (UrssafRequestRejected $rejected) {
            $this->session->noteRejection('pay-060-' . $variant, $rejected);
            $this->assertPeriodSent('POST', ApiPaths::payContributions(), $rejected->errors());

            return;
        }

        $exchange = $this->assertPeriodSent('POST', ApiPaths::payContributions(), ApiErrors::none());
        static::assertSame(200, $exchange->status);
        static::assertNotSame('', $result->certificate()->value());
        $this->session->note(
            'response-shape',
            \sprintf('060[%s] %s (wrapper key %s), periode sent as annee=%s %s=%s', $variant, $exchange->shapeUnder(Exchange::WRAPPER_PAYMENT), Exchange::WRAPPER_PAYMENT, $exchange->requestPeriodType('annee'), $this->numberField($period), $exchange->requestPeriodType($this->numberField($period))),
        );
    }

    private function assertPeriodSent(string $method, string $path, ApiErrors $errors): Exchange
    {
        $exchange = $this->session->sandbox->http->last($method, $path);
        static::assertInstanceOf(Exchange::class, $exchange);
        $this->session->assertPeriodNotRejected($errors, $exchange);

        return $exchange;
    }

    private function numberField(DeclarationPeriod $period): string
    {
        return $period->periodicity() === Periodicity::Monthly ? 'mois' : 'trimestre';
    }
}
