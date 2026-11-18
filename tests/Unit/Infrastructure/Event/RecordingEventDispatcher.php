<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Tests\Unit\Infrastructure\Event;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * PSR-14 dispatcher fake recording every dispatched object, in order, and optionally failing after recording it.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $dispatched = [];

    public function __construct(private readonly ?Throwable $failure) {}

    #[Override]
    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $event;
    }

    /**
     * @return list<object>
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
