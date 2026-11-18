<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Repository;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrineMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Doctrine\Repository\DoctrinePaymentRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentRecordId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Contract\ContractSamples;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\BeforeNextFlush;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support\FileDatabase;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Domain\Declaration\DeclarationSamples;

/**
 * Two writers (two entity managers, two connections) on one SQLite file: the database, not the application, decides who
 * wins, and the loser gets the domain violation with nothing of its write stored. The loser is in the window between its
 * check and its flush ({@see BeforeNextFlush}) when the winner commits.
 */
final class ConcurrentWritersTest extends TestCase
{
    private FileDatabase $file;

    protected function setUp(): void
    {
        $this->file = new FileDatabase();
    }

    protected function tearDown(): void
    {
        $this->file->delete();
    }

    /**
     * @return list<mixed>
     */
    private function column(string $sql): array
    {
        return $this->file->entityManager()->getConnection()->fetchFirstColumn($sql);
    }

    public function testOneClaimWinsOutOfTwo(): void
    {
        $first = new DoctrineDeclarationRecordRepository($this->file->entityManager());
        $second = new DoctrineDeclarationRecordRepository($this->file->entityManager());
        $first->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        $results = [$first->claimForSubmission($id, $now), $second->claimForSubmission($id, $now)];

        static::assertSame([true, false], $results);
        static::assertSame([2], $this->column('SELECT version FROM urssaf_tdae_declaration_records'));
        static::assertSame([1], $this->column('SELECT submission_attempts FROM urssaf_tdae_declaration_records'));
    }

    public function testOnePaymentClaimWinsOutOfTwo(): void
    {
        $first = new DoctrinePaymentRecordRepository($this->file->entityManager());
        $second = new DoctrinePaymentRecordRepository($this->file->entityManager());
        $first->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        $id = PaymentRecordId::fromString('payment-1');
        $now = ContractSamples::at('2026-11-16T10:00:00Z');

        $results = [$first->claimForSubmission($id, $now), $second->claimForSubmission($id, $now)];

        static::assertSame([true, false], $results);
        static::assertSame([1], $this->column('SELECT submission_attempts FROM urssaf_tdae_payment_records'));
    }

    public function testTwoFirstDeclarationsOfAPeriodOneWinsAndTheLoserStoresNothing(): void
    {
        $loserManager = $this->file->entityManager();
        $winner = new DoctrineDeclarationRecordRepository($this->file->entityManager());
        $loser = new DoctrineDeclarationRecordRepository($loserManager);
        BeforeNextFlush::run($loserManager, static function () use ($winner): void {
            $winner->save(ContractSamples::declaration(1, 1, ContractSamples::monthly()));
        });

        try {
            $loser->save(ContractSamples::declaration(2, 1, ContractSamples::monthly()));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.duplicate_current', $exception->violationCode());
        }

        static::assertSame(['declaration-1'], $this->column('SELECT id FROM urssaf_tdae_declaration_records'));
        static::assertSame(['declaration-1'], $this->column('SELECT declaration_record_id FROM urssaf_tdae_current_declarations'));
    }

    public function testTwoCurrentPaymentsOfAReferenceOneWinsAndTheLoserStoresNothing(): void
    {
        $loserManager = $this->file->entityManager();
        $winner = new DoctrinePaymentRecordRepository($this->file->entityManager());
        $loser = new DoctrinePaymentRecordRepository($loserManager);
        BeforeNextFlush::run($loserManager, static function () use ($winner): void {
            $winner->save(ContractSamples::payment(1, 1, 'payment-ref-1', ContractSamples::monthly()));
        });

        try {
            $loser->save(ContractSamples::payment(2, 1, 'payment-ref-1', ContractSamples::monthly()));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('payment_record.duplicate_reference', $exception->violationCode());
        }

        static::assertSame(['payment-1'], $this->column('SELECT id FROM urssaf_tdae_payment_records'));
        static::assertSame(['payment-1'], $this->column('SELECT payment_record_id FROM urssaf_tdae_current_payments'));
    }

    public function testTwoActiveMandatesOfASiretOneWinsAndTheLoserStoresNothing(): void
    {
        $loserManager = $this->file->entityManager();
        $winner = new DoctrineMandateRepository($this->file->entityManager());
        $loser = new DoctrineMandateRepository($loserManager);
        BeforeNextFlush::run($loserManager, static function () use ($winner): void {
            $winner->save(ContractSamples::mandate(1, 1));
        });

        try {
            $loser->save(ContractSamples::mandate(2, 1));
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('mandate.duplicate_active', $exception->violationCode());
        }

        static::assertSame(['mandate-1'], $this->column('SELECT id FROM urssaf_tdae_mandates'));
        static::assertSame(['mandate-1'], $this->column('SELECT mandate_id FROM urssaf_tdae_active_mandates'));
    }

    public function testTwoAccountsOfASiretOneWinsAndTheLoserStoresNothing(): void
    {
        $loserManager = $this->file->entityManager();
        $winner = new DoctrineAeAccountRepository($this->file->entityManager());
        $loser = new DoctrineAeAccountRepository($loserManager);
        BeforeNextFlush::run($loserManager, static function () use ($winner): void {
            $winner->save(ContractSamples::account(1));
        });
        $rival = AeAccount::track(
            AccountId::fromString('account-2'),
            ContractSamples::tenant(),
            ContractSamples::siret(1),
            ContractSamples::status(),
            null,
            ContractSamples::at(),
        );

        try {
            $loser->save($rival);
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('ae_account.duplicate_siret', $exception->violationCode());
        }

        static::assertSame(['account-1'], $this->column('SELECT id FROM urssaf_tdae_accounts'));
    }

    public function testTwoSupersessionsOfOneDeclarationOneWinsAndTheOtherIsAConcurrentModification(): void
    {
        $setup = new DoctrineDeclarationRecordRepository($this->file->entityManager());
        $setup->save(ContractSamples::declarationIn(DeclarationState::Rejected, 1, 1, ContractSamples::monthly()));
        $id = DeclarationRecordId::fromString('declaration-1');
        $firstManager = $this->file->entityManager();
        $secondManager = $this->file->entityManager();
        $first = new DoctrineDeclarationRecordRepository($firstManager);
        $second = new DoctrineDeclarationRecordRepository($secondManager);
        $firstOld = $first->get($id);
        $secondOld = $second->get($id);
        $firstNew = $firstOld->supersede(DeclarationRecordId::fromString('declaration-2'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:00Z'));
        $secondNew = $secondOld->supersede(DeclarationRecordId::fromString('declaration-3'), DeclarationSamples::otherFingerprint(), ContractSamples::at('2026-11-16T09:00:01Z'));
        (new DoctrineTransactionManager($firstManager))->transactional(static function () use ($first, $firstOld, $firstNew): void {
            $first->save($firstOld);
            $first->save($firstNew);
        });

        try {
            (new DoctrineTransactionManager($secondManager))->transactional(static function () use ($second, $secondOld, $secondNew): void {
                $second->save($secondOld);
                $second->save($secondNew);
            });
            static::fail('Expected a violation.');
        } catch (InvariantViolation $exception) {
            static::assertSame('declaration_record.concurrent_modification', $exception->violationCode());
        }

        static::assertSame(['declaration-1', 'declaration-2'], $this->column('SELECT id FROM urssaf_tdae_declaration_records ORDER BY id'));
        static::assertSame(['declaration-2'], $this->column('SELECT declaration_record_id FROM urssaf_tdae_current_declarations'));
    }
}
