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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;

/**
 * The manual settlement of the outcome_unknown records (ADR 0003): no call, the operator checked on the auto-entrepreneur
 * space.
 *
 * @mago-expect lint:too-many-methods One test per behaviour of two commands and their selectors.
 */
final class ResolveCommandsTest extends TestCase
{
    public function testADeclarationIsSettledAsDeclaredByItsRecordId(): void
    {
        $harness = Harness::boot();
        $recordId = Scenario::outcomeUnknownDeclaration($harness)->declaration->recordId;
        $command = $harness->command('urssaf:tdae:declarations:resolve');

        static::assertSame(0, $command->execute(['--record-id' => $recordId, '--declared-as' => '99887766'], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame($recordId, $fields['Record id']);
        static::assertSame('declared', $fields['State']);
        static::assertSame('99887766', $fields['Certificat']);
        static::assertStringNotContainsString('pending again', $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testADeclarationIsSettledAsNotReceivedByItsSiretAndPeriod(): void
    {
        $harness = Harness::boot();
        $recordId = Scenario::outcomeUnknownDeclaration($harness)->declaration->recordId;
        $command = $harness->command('urssaf:tdae:declarations:resolve');

        static::assertSame(0, $command->execute(['siret' => Scenario::SIRET, '--period' => '2026-10', '--not-received' => true], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame($recordId, $fields['Record id']);
        static::assertSame('pending', $fields['State']);
        static::assertStringContainsString('The declaration is pending again: declare the turnover again with urssaf:tdae:declare.', $command->getDisplay());
    }

    public function testADeclarationThatIsNotUnknownCannotBeSettled(): void
    {
        $harness = Harness::boot();
        $recordId = Scenario::declaration($harness)->declaration->recordId;
        $command = $harness->command('urssaf:tdae:declarations:resolve');

        static::assertSame(1, $command->execute(['--record-id' => $recordId, '--not-received' => true], ['interactive' => false]));
    }

    public function testAnUnknownDeclarationIsAnInvalidUsage(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:declarations:resolve');

        static::assertSame(2, $command->execute(['--record-id' => 'unknown', '--not-received' => true], ['interactive' => false]));
    }

    public function testADeclarationNeedsExactlyOneOfTheTwoOutcomes(): void
    {
        $harness = Harness::boot();
        $recordId = Scenario::outcomeUnknownDeclaration($harness)->declaration->recordId;
        $command = $harness->command('urssaf:tdae:declarations:resolve');

        static::assertSame(2, $command->execute(['--record-id' => $recordId], ['interactive' => false]));
        static::assertStringContainsString('Give one of --declared-as or --not-received.', $command->getDisplay());

        static::assertSame(2, $command->execute(['--record-id' => $recordId, '--declared-as' => '1', '--not-received' => true], ['interactive' => false]));
        static::assertStringContainsString('Give one of --declared-as or --not-received.', $command->getDisplay());
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('invalidDeclarationSelectors')]
    public function testADeclarationNeedsAValidSelector(array $arguments, string $message): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:declarations:resolve');

        static::assertSame(2, $command->execute([...$arguments, '--not-received' => true], ['interactive' => false]));

        static::assertStringContainsString($message, $command->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidDeclarationSelectors(): iterable
    {
        yield 'nothing' => [[], 'Give the SIRET argument with --period, or --record-id.'];
        yield 'a SIRET alone' => [['siret' => Scenario::SIRET], 'Give the SIRET argument with --period, or --record-id.'];
        yield 'a period alone' => [['--period' => '2026-10'], 'Give the SIRET argument with --period, or --record-id.'];
        yield 'a record id with a period' => [['--record-id' => 'x', '--period' => '2026-10'], 'Give the SIRET argument with --period, or --record-id.'];
        yield 'a record id with a SIRET' => [['--record-id' => 'x', 'siret' => Scenario::SIRET], 'Give the SIRET argument with --period, or --record-id.'];
        yield 'everything' => [['--record-id' => 'x', 'siret' => Scenario::SIRET, '--period' => '2026-10'], 'Give the SIRET argument with --period, or --record-id.'];
        yield 'an invalid period' => [['siret' => Scenario::SIRET, '--period' => '10/2026'], 'The --period option must be YYYY-MM'];
        yield 'an invalid SIRET' => [['siret' => '123', '--period' => '2026-10'], 'siret.invalid_format'];
    }

    public function testAPaymentIsSettledAsPaidByItsReference(): void
    {
        $harness = Harness::boot();
        $recordId = Scenario::outcomeUnknownPayment($harness)->payment->recordId;
        $command = $harness->command('urssaf:tdae:payments:resolve');

        static::assertSame(0, $command->execute(['payment-reference' => Scenario::PAYMENT, '--paid-as' => '99887766'], ['interactive' => false]));

        $fields = Fields::of($command->getDisplay());
        static::assertSame($recordId, $fields['Record id']);
        static::assertSame('paid', $fields['State']);
        static::assertSame('99887766', $fields['Certificat']);
        static::assertStringNotContainsString('pending again', $command->getDisplay());
        static::assertSame(1, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testAPaymentIsSettledAsNotReceivedByItsRecordId(): void
    {
        $harness = Harness::boot();
        $recordId = Scenario::outcomeUnknownPayment($harness)->payment->recordId;
        $command = $harness->command('urssaf:tdae:payments:resolve');

        static::assertSame(0, $command->execute(['--record-id' => $recordId, '--not-received' => true], ['interactive' => false]));

        static::assertSame('pending', Fields::of($command->getDisplay())['State']);
        static::assertStringContainsString('The payment is pending again: only you decide to order it again with urssaf:tdae:pay.', $command->getDisplay());
    }

    public function testAPaymentNeedsExactlyOneOfTheTwoOutcomes(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:payments:resolve');

        static::assertSame(2, $command->execute(['payment-reference' => Scenario::PAYMENT], ['interactive' => false]));
        static::assertStringContainsString('Give one of --paid-as or --not-received.', $command->getDisplay());

        static::assertSame(2, $command->execute(['payment-reference' => Scenario::PAYMENT, '--paid-as' => '1', '--not-received' => true], ['interactive' => false]));
        static::assertStringContainsString('Give one of --paid-as or --not-received.', $command->getDisplay());
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('invalidPaymentSelectors')]
    public function testAPaymentNeedsExactlyOneSelector(array $arguments): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:payments:resolve');

        static::assertSame(2, $command->execute([...$arguments, '--not-received' => true], ['interactive' => false]));

        static::assertStringContainsString('Give one of the payment reference argument or --record-id.', $command->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidPaymentSelectors(): iterable
    {
        yield 'nothing' => [[]];
        yield 'both' => [['payment-reference' => Scenario::PAYMENT, '--record-id' => 'x']];
    }

    public function testAnUnknownPaymentIsAnInvalidUsage(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:payments:resolve');

        static::assertSame(2, $command->execute(['payment-reference' => 'unknown', '--not-received' => true], ['interactive' => false]));
    }
}
