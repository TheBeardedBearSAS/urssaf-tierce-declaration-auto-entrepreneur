<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Functional\Doctrine\Support;

use Closure;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * Runs an action once, right before the first statement whose SQL contains a needle is executed: the window between
 * two statements of a multi-step operation (for instance the SELECT and the DELETE of an erasure), in which a
 * concurrent writer can change a row. It is a PSR-3 logger plugged into the logging middleware of DBAL, which logs a
 * statement before it runs (plug it through {@see self::middleware()}).
 *
 * The fired flag is set before the action runs: the statements of the action go through the same connection, hence
 * through this logger again.
 */
final class BeforeNextStatement implements LoggerInterface
{
    use LoggerTrait;

    private bool $fired = false;

    /** @var Closure(): void */
    private Closure $action;

    /**
     * @param non-empty-string $needle SQL fragment of the statement before which the action runs
     */
    public function __construct(private readonly string $needle)
    {
        $this->action = static function (): void {};
    }

    /**
     * Arms the logger: the action runs once, before the first statement that contains the needle (the logger is built
     * first because the entity manager needs its middleware, and the action usually needs that entity manager).
     *
     * @param Closure(): void $action
     * @param-immediately-invoked-callable $action
     */
    public function run(Closure $action): void
    {
        $this->action = $action;
    }

    public function middleware(): Middleware
    {
        return new Middleware($this);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        // @mago-expect analysis:mixed-assignment The PSR-3 context is untyped; it is narrowed right below.
        $sql = $context['sql'] ?? null;
        if ($this->fired || !\is_string($sql) || !str_contains($sql, $this->needle)) {
            return;
        }
        $this->fired = true;
        ($this->action)();
    }
}
