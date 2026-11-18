<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Testing\SimulatorSamples;

/**
 * The commands that call the contribution methods: estimate (040), declare (050) and pay (060).
 *
 * @mago-expect lint:too-many-methods One test per behaviour of three commands.
 */
final class SubmissionCommandsTest extends TestCase
{
    private const RUM = 'T1234567891234567891234568912345678';
    private const UUID = '/^[0-9a-f-]{36}$/';

    /** The amounts of the wire fixtures (estimate, declare): one row per contribution of the views. */
    private const AMOUNTS = [
        'Total (€)' => '1678',
        'Sales (€)' => '128',
        'Furnished rental (€)' => '0',
        'Commercial services (€)' => '660',
        'Other services (€)' => '880',
        'CIPAV (€)' => '0',
        'Vocational training (€)' => '10',
        'ACRE regularisation (€)' => '-',
        'Deduction (€)' => '-',
        'Liberatory sales (€)' => '-',
        'Liberatory furnished rental (€)' => '-',
        'Liberatory commercial services (€)' => '-',
        'Liberatory other services (€)' => '-',
        'Liberatory CIPAV (€)' => '-',
        'CMA sales (€)' => '0',
        'CMA services (€)' => '0',
        'CCI sales (€)' => '0',
        'CCI services (€)' => '0',
    ];

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function declare(array $options = []): array
    {
        return ['siret' => Scenario::SIRET, '--payload' => Fields::payload('declaration.json'), ...$options];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function pay(array $options = [], string $reference = Scenario::PAYMENT): array
    {
        return ['siret' => Scenario::SIRET, 'payment-reference' => $reference, '--payload' => Fields::payload('payment.json'), ...$options];
    }

    public function testTheContributionsAreEstimated(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:estimate');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('turnover.json')], ['interactive' => false]));

        static::assertSame(['Period' => '2019-T1', ...self::AMOUNTS], Fields::of($command->getDisplay()));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_040));
        $request = Requests::lastBody($harness);
        static::assertStringContainsString('"caVente":1000', $request);
        static::assertStringContainsString('"caPrestationService":2000', $request);
    }

    public function testTheEstimateNeedsAMandateUnlessTheCheckIsSkipped(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:estimate');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('turnover.json')], ['interactive' => false]));
        static::assertStringContainsString('--skip-mandate-check', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_040));

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('turnover.json'), '--skip-mandate-check' => true], ['interactive' => false]));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_040));
    }

    public function testTheEstimateNeedsItsPayload(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:estimate');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET], ['interactive' => false]));

        static::assertStringContainsString('The --payload option is required.', $command->getDisplay());
    }

    public function testAnInvalidTurnoverIsRefusedByItsFactory(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:estimate');

        static::assertSame(2, $command->execute(['siret' => Scenario::SIRET, '--payload' => Fields::payload('turnover_invalid.json')], ['interactive' => false]));

        static::assertStringContainsString('sales: ', $command->getDisplay());
        static::assertStringNotContainsString('abc', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_040));
    }

    public function testADeclarationIsDeclared(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:declare');

        static::assertSame(0, $command->execute(self::declare(), ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertMatchesRegularExpression(self::UUID, $fields['Record id']);
        unset($fields['Record id']);
        static::assertSame([
            'SIRET' => Scenario::SIRET,
            'Period' => '2026-10',
            'State' => 'declared',
            'Unknown outcome reason' => '-',
            'Attempts' => '1',
            'Error codes' => '-',
            'Certificat' => '56335826',
            'idDeclaration' => 'W1dl9W2Oa4R6ab3UlQAqxF3',
            'Due date' => '2020-01-31',
            ...self::AMOUNTS,
            'Updated at' => '2026-11-15T10:00:00Z',
            'Outcome' => 'declared',
        ], $fields);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
        static::assertStringNotContainsString('<comment>', $command->getDisplay());
    }

    public function testDeclaringAgainReplaysTheDeclaredRecordWithoutResendingIt(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:declare');
        static::assertSame(0, $command->execute(self::declare(), ['interactive' => false]));

        static::assertSame(0, $command->execute(self::declare(), ['interactive' => false]));

        static::assertSame('replayed', Fields::of($command->getDisplay())['Outcome']);
        static::assertSame('declared', Fields::of($command->getDisplay())['State']);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testADeclarationNeedsAMandateUnlessTheCheckIsSkipped(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:declare');

        static::assertSame(2, $command->execute(self::declare(), ['interactive' => false]));
        static::assertStringContainsString('--skip-mandate-check', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_050));

        static::assertSame(0, $command->execute(self::declare(['--skip-mandate-check' => true]), ['interactive' => false]));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testADeclarationIsCheckedAgainstThePeriodicityUnlessTheCheckIsSkipped(): void
    {
        $harness = Harness::boot();
        Scenario::mandatedAccount($harness);
        $command = $harness->command('urssaf:tdae:declare');

        static::assertSame(2, $command->execute(self::declare(), ['interactive' => false]));
        static::assertStringContainsString('declaration_period.periodicity_mismatch', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_050));

        static::assertSame(0, $command->execute(self::declare(['--skip-periodicity-check' => true]), ['interactive' => false]));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testALostAnswerIsAnOutcomeUnknownThatIsNeverResent(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_050, 500, '{"message":"Internal Server Error"}');
        $command = $harness->command('urssaf:tdae:declare');

        static::assertSame(1, $command->execute(self::declare(), ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame('outcome_unknown', $fields['State']);
        static::assertSame('outcome_unknown', $fields['Outcome']);
        static::assertSame('may_have_reached_urssaf', $fields['Unknown outcome reason']);
        static::assertSame('1', $fields['Attempts']);
        static::assertSame('urssaf.outcome_unknown (technical)', $fields['Error codes']);
        static::assertStringContainsString('The answer was lost: never send it again.', $command->getDisplay());
        static::assertStringContainsString('urssaf:tdae:declarations:resolve', $command->getDisplay());

        static::assertSame(1, $command->execute(self::declare(), ['interactive' => false]));
        static::assertSame('outcome_unknown', Fields::of($command->getDisplay())['State']);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testInvalidCredentialsAreAFailureWithAHint(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 401, '{"error":"invalid_client"}');
        $command = $harness->command('urssaf:tdae:declare');

        static::assertSame(1, $command->execute(self::declare(), ['interactive' => false]));

        static::assertSame('invalid_credentials', Fields::of($command->getDisplay())['State']);
        static::assertStringContainsString('Fix the credentials, then run urssaf:tdae:requeue and declare again.', $command->getDisplay());
    }

    public function testARejectionShowsItsCodesAndNeverItsMessages(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_050, 400, 'error_param_invalide.json');
        $command = $harness->command('urssaf:tdae:declare');

        static::assertSame(1, $command->execute(self::declare(), ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame('rejected', $fields['State']);
        static::assertSame('PARAM_INVALIDE (invalid_parameter)', $fields['Error codes']);
        static::assertStringContainsString('URSSAF refused the declaration: see the error codes, fix the data and declare again.', $command->getDisplay());
        static::assertStringNotContainsString('Un des paramètres', $command->getDisplay());
        static::assertStringNotContainsString('ne doit pas dépasser', $command->getDisplay());
    }

    public function testAnInvalidDeclarationIsRefusedBeforeAnyCall(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:declare');

        static::assertSame(2, $command->execute(self::declare(['--payload' => Fields::payload('declaration_invalid.json')]), ['interactive' => false]));

        static::assertStringContainsString('Invalid input: ', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_050));
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_AUTHENTICATION));
    }

    public function testAPaymentIsOrdered(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(0, $command->execute(self::pay(), ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertMatchesRegularExpression(self::UUID, $fields['Record id']);
        unset($fields['Record id']);
        static::assertSame([
            'Reference' => Scenario::PAYMENT,
            'SIRET' => Scenario::SIRET,
            'Period' => '2026-10',
            'Amount (€)' => '220',
            'RUM' => '-',
            'State' => 'paid',
            'Unknown outcome reason' => '-',
            'Attempts' => '1',
            'Error codes' => '-',
            'Certificat' => '56335985',
            'idTelepaiement' => 'W1dl9W2Oa4R6ab3UlQAqxF2',
            'Due date' => '2020-01-31',
            'Updated at' => '2026-11-15T10:00:00Z',
            'Outcome' => 'paid',
        ], $fields);
        $request = Requests::lastBody($harness);
        static::assertStringNotContainsString('iban', $request);
        static::assertStringNotContainsString('rum', $request);
        static::assertStringNotContainsString('<comment>', $command->getDisplay());
    }

    public function testOrderingAgainTheSameReferenceReplaysThePaidRecord(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');
        static::assertSame(0, $command->execute(self::pay(), ['interactive' => false]));

        static::assertSame(0, $command->execute(self::pay(), ['interactive' => false]));

        static::assertSame('replayed', Fields::of($command->getDisplay())['Outcome']);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testTheRumIsPassedToUrssaf(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(0, $command->execute(self::pay(['--rum' => self::RUM]), ['interactive' => false]));

        static::assertSame(self::RUM, Fields::of($command->getDisplay())['RUM']);
        static::assertStringContainsString(self::RUM, Requests::lastBody($harness));
    }

    public function testTheIbanIsAskedInAHiddenPromptAndPassedToUrssaf(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');
        $command->setInputs([SimulatorSamples::IBAN]);

        static::assertSame(0, $command->execute(self::pay(['--with-iban' => true])));

        static::assertStringContainsString('IBAN: ', $command->getDisplay());
        static::assertStringNotContainsString(SimulatorSamples::IBAN, $command->getDisplay());
        static::assertStringContainsString(SimulatorSamples::IBAN, Requests::lastBody($harness));
    }

    public function testTheIbanPromptNeedsAnInteractiveRun(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(2, $command->execute(self::pay(['--with-iban' => true]), ['interactive' => false]));

        static::assertStringContainsString('IBAN is read from a hidden prompt: run the command interactively.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testAnEmptyIbanAnswerIsRefusedWithoutBeingRepeated(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');
        $command->setInputs(['']);

        static::assertSame(2, $command->execute(self::pay(['--with-iban' => true])));

        static::assertStringContainsString('IBAN was not given.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testAnInvalidRumIsRefusedBeforeTheIbanPrompt(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');
        $command->setInputs([SimulatorSamples::IBAN]);

        static::assertSame(2, $command->execute(self::pay(['--with-iban' => true, '--rum' => ' '])));

        static::assertStringNotContainsString('IBAN: ', $command->getDisplay());
        static::assertStringContainsString('rum: ', $command->getDisplay());
    }

    public function testThePayloadCannotHoldTheIbanNorTheRum(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(2, $command->execute(self::pay(['--payload' => Fields::payload('payment_with_iban.json')]), ['interactive' => false]));
        static::assertStringContainsString('The --payload file must not hold "iban": use --with-iban.', $command->getDisplay());
        static::assertStringNotContainsString(SimulatorSamples::IBAN, $command->getDisplay());

        static::assertSame(2, $command->execute(self::pay(['--payload' => Fields::payload('payment_with_rum.json')]), ['interactive' => false]));
        static::assertStringContainsString('The --payload file must not hold "rum": use --rum.', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testAnInvalidPaymentReferenceIsRefused(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(2, $command->execute(self::pay([], ''), ['interactive' => false]));

        static::assertStringContainsString('payment_reference', $command->getDisplay());
    }

    public function testAPaymentNeedsAMandateUnlessTheCheckIsSkipped(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(2, $command->execute(self::pay(), ['interactive' => false]));
        static::assertStringContainsString('--skip-mandate-check', $command->getDisplay());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_060));

        static::assertSame(0, $command->execute(self::pay(['--skip-mandate-check' => true]), ['interactive' => false]));
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testASecondPaymentForThePeriodNeedsTheExplicitOptIn(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $command = $harness->command('urssaf:tdae:pay');
        static::assertSame(0, $command->execute(self::pay(), ['interactive' => false]));

        static::assertSame(2, $command->execute(self::pay([], 'pay-2'), ['interactive' => false]));
        static::assertStringContainsString('payment.period_already_paid', $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_060));

        static::assertSame(0, $command->execute(self::pay(['--allow-additional-payment' => true], 'pay-2'), ['interactive' => false]));
        static::assertSame(2, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testALostPaymentAnswerIsAnOutcomeUnknownThatIsNeverResent(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_060, 500, '{"message":"Internal Server Error"}');
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(1, $command->execute(self::pay(), ['interactive' => false]));

        static::assertSame('outcome_unknown', Fields::of($command->getDisplay())['State']);
        static::assertSame('may_have_reached_urssaf', Fields::of($command->getDisplay())['Unknown outcome reason']);
        static::assertStringContainsString('The answer was lost: never order it again.', $command->getDisplay());
        static::assertStringContainsString('urssaf:tdae:payments:resolve', $command->getDisplay());

        static::assertSame(1, $command->execute(self::pay(), ['interactive' => false]));
        static::assertSame('outcome_unknown', Fields::of($command->getDisplay())['State']);
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testInvalidCredentialsOfAPaymentAreAFailureWithAHint(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $harness->urssaf->respond(HttpScript::ROUTE_AUTHENTICATION, 401, '{"error":"invalid_client"}');
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(1, $command->execute(self::pay(), ['interactive' => false]));

        static::assertSame('invalid_credentials', Fields::of($command->getDisplay())['State']);
        static::assertStringContainsString('Fix the credentials, then run urssaf:tdae:requeue and order the payment again.', $command->getDisplay());
    }

    public function testAPaymentRejectionShowsItsCodesAndNeverItsMessages(): void
    {
        $harness = Harness::boot();
        Scenario::importedMandate($harness);
        $harness->urssaf->respondWithFixture(HttpScript::ROUTE_060, 400, 'error_param_invalide.json');
        $command = $harness->command('urssaf:tdae:pay');

        static::assertSame(1, $command->execute(self::pay(), ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame('rejected', $fields['State']);
        static::assertSame('PARAM_INVALIDE (invalid_parameter)', $fields['Error codes']);
        static::assertSame('1', $fields['Attempts']);
        static::assertStringContainsString('URSSAF refused the payment: see the error codes, fix the data and order it again under a new reference.', $command->getDisplay());
        static::assertStringNotContainsString('Un des paramètres', $command->getDisplay());
    }
}
