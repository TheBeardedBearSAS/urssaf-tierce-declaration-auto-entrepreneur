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
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\HttpScript;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Symfony\Support\Scenario;

/**
 * The reconciliation of the stale submissions (ADR 0003): nothing is ever resent, the records move to outcome_unknown.
 *
 * @mago-expect lint:too-many-methods One test per behaviour of two commands.
 */
final class ReconcileCommandsTest extends TestCase
{
    public function testAStaleDeclarationMovesToOutcomeUnknownAndAlertsTheOperator(): void
    {
        $harness = Harness::boot();
        $id = Scenario::staleDeclaration($harness);
        $command = $harness->command('urssaf:tdae:declarations:reconcile');

        static::assertSame(1, $command->execute([], ['interactive' => false]));

        static::assertSame([['Record', 'Outcome', 'Codes'], [$id->value(), 'manual_review', 'stale_submission']], Output::rows($command->getDisplay()));
        static::assertStringContainsString('1 record(s) processed (manual_review: 1).', $command->getDisplay());
        static::assertSame(DeclarationState::OutcomeUnknown, $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class)->get($id)->status()->state());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_050));
    }

    public function testWithoutAStaleDeclarationNothingHappensAndTheExitIsZero(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:declarations:reconcile');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertStringContainsString('0 record(s) processed.', $command->getDisplay());
    }

    public function testADryRunListsTheStaleDeclarationsAndChangesNothing(): void
    {
        $harness = Harness::boot();
        $id = Scenario::staleDeclaration($harness);
        $command = $harness->command('urssaf:tdae:declarations:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));

        static::assertSame([['Tenant', 'Record'], ['tenant-1', $id->value()]], Output::rows($command->getDisplay()));
        static::assertStringContainsString('Dry run: 1 record(s) would be processed, URSSAF was not called.', $command->getDisplay());
        static::assertSame(DeclarationState::Submitting, $harness->privateService(DeclarationRecordRepository::class, DeclarationRecordRepository::class)->get($id)->status()->state());
    }

    public function testADeclarationThatIsNotStaleYetIsNotListed(): void
    {
        $harness = Harness::boot();
        $id = Scenario::staleDeclaration($harness);
        $harness->clock->modify('-6 minutes');
        $command = $harness->command('urssaf:tdae:declarations:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));
        static::assertSame([['Tenant', 'Record']], Output::rows($command->getDisplay()));

        $harness->clock->modify('+6 minutes');
        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));
        static::assertSame([['Tenant', 'Record'], ['tenant-1', $id->value()]], Output::rows($command->getDisplay()));
    }

    public function testTheLimitBoundsTheDeclarationsProcessed(): void
    {
        $harness = Harness::boot();
        Scenario::staleDeclaration($harness, 'tenant-1');
        Scenario::staleDeclaration($harness, 'tenant-2');
        $command = $harness->command('urssaf:tdae:declarations:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true, '--limit' => '1'], ['interactive' => false]));
        static::assertCount(2, Output::rows($command->getDisplay()));

        static::assertSame(1, $command->execute(['--limit' => '1'], ['interactive' => false]));
        static::assertStringContainsString('1 record(s) processed (manual_review: 1).', $command->getDisplay());
    }

    #[DataProvider('commands')]
    public function testAnInvalidLimitIsRefused(string $name): void
    {
        $harness = Harness::boot();
        $command = $harness->command($name);

        static::assertSame(2, $command->execute(['--limit' => '0'], ['interactive' => false]));

        static::assertStringContainsString('The --limit option must be a positive integer.', $command->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commands(): iterable
    {
        yield 'declarations' => ['urssaf:tdae:declarations:reconcile'];
        yield 'payments' => ['urssaf:tdae:payments:reconcile'];
    }

    public function testAStalePaymentMovesToOutcomeUnknownAndAlertsTheOperator(): void
    {
        $harness = Harness::boot();
        $id = Scenario::stalePayment($harness);
        $command = $harness->command('urssaf:tdae:payments:reconcile');

        static::assertSame(1, $command->execute([], ['interactive' => false]));

        static::assertSame([['Record', 'Outcome', 'Codes'], [$id->value(), 'manual_review', 'stale_submission']], Output::rows($command->getDisplay()));
        static::assertSame(PaymentState::OutcomeUnknown, $harness->privateService(PaymentRecordRepository::class, PaymentRecordRepository::class)->get($id)->status()->state());
        static::assertSame(0, $harness->urssaf->calls(HttpScript::ROUTE_060));
    }

    public function testWithoutAStalePaymentNothingHappensAndTheExitIsZero(): void
    {
        $harness = Harness::boot();
        $command = $harness->command('urssaf:tdae:payments:reconcile');

        static::assertSame(0, $command->execute([], ['interactive' => false]));

        static::assertStringContainsString('0 record(s) processed.', $command->getDisplay());
    }

    public function testADryRunListsTheStalePaymentsAndChangesNothing(): void
    {
        $harness = Harness::boot();
        $id = Scenario::stalePayment($harness);
        $command = $harness->command('urssaf:tdae:payments:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));

        static::assertSame([['Tenant', 'Record'], ['tenant-1', $id->value()]], Output::rows($command->getDisplay()));
        static::assertSame(PaymentState::Submitting, $harness->privateService(PaymentRecordRepository::class, PaymentRecordRepository::class)->get($id)->status()->state());
    }

    public function testAPaymentThatIsNotStaleYetIsNotListed(): void
    {
        $harness = Harness::boot();
        $id = Scenario::stalePayment($harness);
        $harness->clock->modify('-6 minutes');
        $command = $harness->command('urssaf:tdae:payments:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));
        static::assertSame([['Tenant', 'Record']], Output::rows($command->getDisplay()));

        $harness->clock->modify('+6 minutes');
        static::assertSame(0, $command->execute(['--dry-run' => true], ['interactive' => false]));
        static::assertSame([['Tenant', 'Record'], ['tenant-1', $id->value()]], Output::rows($command->getDisplay()));
    }

    public function testTheLimitBoundsThePaymentsProcessed(): void
    {
        $harness = Harness::boot();
        Scenario::stalePayment($harness, 'tenant-1', 'pay-1');
        Scenario::stalePayment($harness, 'tenant-2', 'pay-2');
        $command = $harness->command('urssaf:tdae:payments:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true, '--limit' => '1'], ['interactive' => false]));
        static::assertCount(2, Output::rows($command->getDisplay()));

        static::assertSame(1, $command->execute(['--limit' => '1'], ['interactive' => false]));
        static::assertStringContainsString('1 record(s) processed (manual_review: 1).', $command->getDisplay());
    }
}
