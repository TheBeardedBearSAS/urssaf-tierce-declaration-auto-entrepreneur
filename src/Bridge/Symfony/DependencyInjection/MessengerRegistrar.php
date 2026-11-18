<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur.
 *
 * Copyright (c) TheBeardedBear and the urssaf-tierce-declaration-auto-entrepreneur contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler\SyncAccountMessageHandler;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Bridge\Symfony\MessageHandler\SyncAccountsMessageHandler;

/**
 * The Messenger handlers of the account sync messages (020 only: nothing else is ever dispatched, ADR 0003).
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class MessengerRegistrar
{
    private const MESSAGE_HANDLERS = [
        SyncAccountsMessageHandler::class,
        SyncAccountMessageHandler::class,
    ];

    /**
     * @param Config $config
     */
    public function register(ServicesConfigurator $services, array $config): void
    {
        // Nothing of the configuration is needed: the handlers only depend on the messenger component being enabled.
        unset($config);
        foreach (self::MESSAGE_HANDLERS as $handler) {
            $services->set($handler)->autowire()->tag('messenger.message_handler');
        }
    }
}
