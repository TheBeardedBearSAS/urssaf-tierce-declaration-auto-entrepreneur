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

/**
 * Declares the services of the bundle from its processed configuration. Services are private (the default of the
 * container configurator), except the use case handlers (the entry points of the host application) and the simulated
 * gateway (tests).
 *
 * {@see register()} registers the driven side (infrastructure, gateway, persistence, locks, outbox, application), then the
 * driving adapters through their own registrars: the console commands, the diagnostics, the Messenger handlers (when
 * "messenger.enabled"), the schedule (when "scheduler.enabled") and the violation list converter (when symfony/validator
 * is installed).
 *
 * @phpstan-type Config array{
 *     environment: string,
 *     base_uri: ?string,
 *     user_agent: string,
 *     headers: array{request_id: ?string, application_id: array{name: ?string, value: ?string}},
 *     business_timezone: string,
 *     token_safety_margin: int,
 *     log_siret: bool,
 *     retry_safe_writes: list<string>,
 *     http_client: ?string,
 *     http_timeout: float,
 *     credentials_provider: ?string,
 *     credentials: array<string, array{client_id: string, client_secret: string}>,
 *     tenants: list<string>,
 *     token_store: array{cache_pool: string, service: ?string},
 *     gateway: array{simulated: bool},
 *     rate_limiter: array{enabled: bool, limit: int, window: string, service: ?string},
 *     lock: array{enabled: bool|string, factory: string, ttl: float},
 *     outbox: array{enabled: bool, service: ?string, sink: string, bus: string, retention: string},
 *     scheduler: array{enabled: bool, name: string, frequencies: array<string, ?string>},
 *     workflow: array{stale_submission_after: string, max_submission_attempts: int, require_local_mandate: bool, default_page_size: int, max_page_size: int, erasure: array{allow_unknown_outcomes: bool, allow_active_mandate: bool}},
 *     persistence: array{driver: string, account_repository: ?string, mandate_repository: ?string, declaration_record_repository: ?string, payment_record_repository: ?string, transaction_manager: ?string, workflow_statistics: ?string, entity_manager: string},
 *     messenger: array{enabled: bool, bus: string}
 * }
 */
final readonly class ServiceRegistrar
{
    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        (new InfrastructureRegistrar($this->services))->register($config);
        (new GatewayRegistrar($this->services))->register($config);
        (new PersistenceRegistrar($this->services))->register($config['persistence']);
        (new LockRegistrar($this->services))->register($config['lock']);
        (new OutboxRegistrar($this->services))->register($config);
        (new ApplicationRegistrar($this->services))->register($config);
        (new CommandRegistrar())->register($this->services, $config);
        (new DiagnosticRegistrar())->register($this->services, $config);
        if ($config['messenger']['enabled']) {
            (new MessengerRegistrar())->register($this->services, $config);
        }
        if ($config['scheduler']['enabled']) {
            (new SchedulerRegistrar())->register($this->services, $config);
        }
        (new ValidationRegistrar($this->services))->register();
    }
}
