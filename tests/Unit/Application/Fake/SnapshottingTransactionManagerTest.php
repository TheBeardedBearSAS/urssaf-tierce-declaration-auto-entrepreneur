<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Application\Fake;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Contract\Event\SepaMandateRegistered;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AccountId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\AeAccount;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Account\Siret;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Declaration\DeclarationState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\Mandate;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Mandate\MandateId;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Payment\PaymentState;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Domain\Shared\Instant;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryAeAccountRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryDeclarationRecordRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryMandateRepository;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Persistence\InMemory\InMemoryPaymentRecordRepository;

/**
 * @mago-expect lint:too-many-methods One test per branch of the use case under test.
 */
final class SnapshottingTransactionManagerTest extends TestCase
{
    private Journal $journal;
    private InMemoryAeAccountRepository $accounts;
    private InMemoryMandateRepository $mandates;
    private InMemoryDeclarationRecordRepository $declarations;
    private InMemoryPaymentRecordRepository $payments;
    private InMemoryOutbox $outbox;
    private SnapshottingTransactionManager $transactions;

    protected function setUp(): void
    {
        $this->journal = new Journal();
        $this->accounts = new InMemoryAeAccountRepository();
        $this->mandates = new InMemoryMandateRepository();
        $this->declarations = new InMemoryDeclarationRecordRepository();
        $this->payments = new InMemoryPaymentRecordRepository();
        $this->outbox = new InMemoryOutbox();
        $this->transactions = new SnapshottingTransactionManager(
            $this->journal,
            $this->accounts,
            $this->mandates,
            $this->declarations,
            $this->payments,
            $this->outbox,
        );
    }

    public function testACommittedTransactionKeepsItsWrites(): void
    {
        $result = $this->transactions->transactional(function (): string {
            $this->writeEverything(1);

            return 'done';
        });

        static::assertSame('done', $result);
        static::assertSame(['begin', 'commit'], $this->journal->entries());
        static::assertSame(1, $this->transactions->committed());
        static::assertSame(self::expected(1, 1, 1, 1, 1), $this->counts());
    }

    public function testARollbackPutsEveryStoreBack(): void
    {
        $this->writeEverything(1);
        $before = $this->counts();

        try {
            $this->transactions->transactional(function (): never {
                $this->writeEverything(2);
                $this->mandates->delete(MandateId::fromString('mandate-1'));
                $this->accounts->delete(AccountId::fromString('account-1'));

                throw new RuntimeException('failure');
            });
        } catch (RuntimeException $failure) {
            static::assertSame('failure', $failure->getMessage());
        }

        static::assertSame($before, $this->counts());
        static::assertSame(['begin', 'rollback'], $this->journal->entries());
        static::assertSame(0, $this->transactions->committed());
    }

    public function testARolledBackAccountCanBeSavedAgainWithoutAConflict(): void
    {
        $this->writeEverything(1);
        $account = $this->accounts->get(AccountId::fromString('account-1'));

        try {
            $this->transactions->transactional(function () use ($account): never {
                $account->rememberBirthDate(AccountWorld::birthDate('1980-01-01'), Instant::fromIso8601(AccountWorld::NOW));
                $this->accounts->save($account);

                throw new RuntimeException('failure');
            });
        } catch (RuntimeException $failure) {
            static::assertSame('failure', $failure->getMessage());
        }
        $again = $this->accounts->get(AccountId::fromString('account-1'));
        $again->rememberBirthDate(AccountWorld::birthDate('1981-01-01'), Instant::fromIso8601(AccountWorld::NOW));
        $this->accounts->save($again);

        static::assertSame('1981-01-01', $this->accounts->get(AccountId::fromString('account-1'))->birthDate()?->toString());
    }

    public function testAnotherWriterSurvivesTheRollback(): void
    {
        try {
            $this->transactions->transactional(function (): never {
                $this->writeEverything(1);
                $this->transactions->outside(function (): void {
                    $this->writeEverything(2);
                });

                throw new RuntimeException('failure');
            });
        } catch (RuntimeException $failure) {
            static::assertSame('failure', $failure->getMessage());
        }

        static::assertSame(self::expected(1, 1, 1, 1, 1), $this->counts());
        static::assertNull($this->accounts->findBySiret(AccountWorld::tenant(), self::siret(1)));
        static::assertInstanceOf(AeAccount::class, $this->accounts->findBySiret(AccountWorld::tenant(), self::siret(2)));
    }

    public function testAnotherWriterAndTheTransactionAreBothCommitted(): void
    {
        $this->writeEverything(1);
        $this->transactions->transactional(function (): void {
            $this->writeEverything(2);
            $this->transactions->outside(function (): void {
                $this->writeEverything(3);
                $this->mandates->delete(MandateId::fromString('mandate-1'));
            });
        });

        static::assertSame(self::expected(3, 2, 3, 3, 3), $this->counts());
    }

    public function testAWriteOutsideATransactionJustRuns(): void
    {
        $this->transactions->outside(function (): void {
            $this->writeEverything(1);
        });

        static::assertSame(self::expected(1, 1, 1, 1, 1), $this->counts());
        static::assertSame([], $this->journal->entries());
    }

    private static function siret(int $n): Siret
    {
        return Siret::fromString('7328293200007' . $n);
    }

    /**
     * Writes an account, its mandate, a declaration, a payment and an outbox entry numbered $n.
     */
    private function writeEverything(int $n): void
    {
        $account = AeAccount::track(
            AccountId::fromString('account-' . $n),
            AccountWorld::tenant(),
            self::siret($n),
            AccountWorld::status(),
            null,
            Instant::fromIso8601(AccountWorld::NOW),
        );
        $this->accounts->save($account);
        $this->mandates->save(Mandate::notify(MandateId::fromString('mandate-' . $n), AccountWorld::tenant(), self::siret($n), $account->id(), Instant::fromIso8601(AccountWorld::NOW)));
        $this->declarations->save(AccountWorld::declarationRecord('declaration-' . $n, DeclarationState::Pending, $n));
        $this->payments->save(AccountWorld::paymentRecord('payment-' . $n, PaymentState::Pending, $n));
        $this->outbox->append(new SepaMandateRegistered('event-' . $n, AccountWorld::NOW, 'tenant-1', null, null, 'RUM-1'));
    }

    /**
     * @return array{accounts: int, mandates: int, declarations: int, payments: int, outbox: int}
     */
    private static function expected(int $accounts, int $mandates, int $declarations, int $payments, int $outbox): array
    {
        return ['accounts' => $accounts, 'mandates' => $mandates, 'declarations' => $declarations, 'payments' => $payments, 'outbox' => $outbox];
    }

    /**
     * What every store holds, for the numbers 1 to 3 (declarations and payments are of the default SIRET).
     *
     * @return array{accounts: int, mandates: int, declarations: int, payments: int, outbox: int}
     */
    private function counts(): array
    {
        $tenant = AccountWorld::tenant();
        $mandates = 0;
        foreach ([1, 2, 3] as $n) {
            $mandates += \count($this->mandates->findBySiret($tenant, self::siret($n)));
        }

        return self::expected(
            \count($this->accounts->all()),
            $mandates,
            array_sum($this->declarations->countBySiret($tenant, AccountWorld::siret())),
            array_sum($this->payments->countBySiret($tenant, AccountWorld::siret())),
            \count($this->outbox->pending(100)),
        );
    }
}
