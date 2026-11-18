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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\RateLimiter\SlidingWindowRateLimiter;
use TheBeardedBear\UrssafTierceDeclarationAutoEntrepreneur\Infrastructure\Urssaf\ApiConfiguration;

/**
 * Configuration tree of the "urssaf_tierce_declaration_auto_entrepreneur" bundle configuration (ADR 0015 item 2).
 *
 * The default persistence driver is "doctrine" when doctrine/orm is installed, "in_memory" otherwise; the "doctrine"
 * driver fails at build time without DoctrineBundle (ADR 0015 item 4), checked by the bundle while the container is
 * prepared, and is wired by the Doctrine bridge (lot 6).
 */
final class BundleConfiguration
{
    public const DRIVER_IN_MEMORY = 'in_memory';
    public const DRIVER_DOCTRINE = 'doctrine';
    public const DRIVER_CUSTOM = 'custom';

    /**
     * The default persistence driver: "doctrine" when doctrine/orm is installed.
     */
    public static function defaultDriver(): string
    {
        return self::defaultDriverFor(interface_exists(EntityManagerInterface::class));
    }

    /**
     * Pure predicate of {@see defaultDriver()}, so that both arms are tested whatever is installed.
     *
     * @mago-expect lint:no-boolean-flag-parameter The flag is the fact "doctrine/orm is installed", not a mode of the function.
     */
    public static function defaultDriverFor(bool $ormInstalled): string
    {
        return $ormInstalled ? self::DRIVER_DOCTRINE : self::DRIVER_IN_MEMORY;
    }

    public static function define(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root
            ->validate()
                ->ifTrue(ConfigurationRules::hasBothCredentialSources(...))
                ->thenInvalid('"credentials" and "credentials_provider" are mutually exclusive.')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::hasNoCredentialSource(...))
                ->thenInvalid('Configure either "credentials" or "credentials_provider".')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::lockEnabledWithoutComponent(...))
                ->thenInvalid('"lock.enabled: true" requires symfony/lock (composer require symfony/lock).')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::simulatedGatewayInProduction(...))
                ->thenInvalid('"gateway.simulated" is meant for tests and cannot be used with "environment: production".')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::outboxWithoutStorage(...))
                ->thenInvalid('The "custom" persistence driver requires "outbox.service" when the outbox is enabled.')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::schedulerEnabledWithoutComponent(...))
                ->thenInvalid('"scheduler.enabled" requires symfony/scheduler (composer require symfony/scheduler).')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::staleSubmissionNotAboveHttpTimeout(...))
                ->thenInvalid('"workflow.stale_submission_after" must be longer than twice "http_timeout" (the API call and the token call): a submission still in flight would be reconciled (ADR 0003).')
            ->end()
            ->children()
                ->scalarNode('environment')
                    ->isRequired()
                    ->info('"sandbox" or "production"; usually "%env(URSSAF_TDAE_ENV)%" (resolved at runtime).')
                    ->validate()
                        ->ifTrue(ConfigurationRules::isUnknownEnvironment(...))
                        ->thenInvalid('Unknown environment %s: use "sandbox" or "production".')
                    ->end()
                ->end()
                ->scalarNode('base_uri')->defaultNull()->info('Overrides the base URI of the selected environment (sandbox host, proxy).')->end()
                ->scalarNode('user_agent')->defaultValue(ApiConfiguration::DEFAULT_USER_AGENT)->cannotBeEmpty()->info('User-Agent header of every call (visible ASCII).')->end()
                ->append(self::headers())
                ->scalarNode('business_timezone')
                    ->defaultValue(ApiConfiguration::DEFAULT_BUSINESS_TIME_ZONE)
                    ->info('Time zone of "today" for the period checks and the workflow (French local time).')
                    ->validate()
                        ->ifTrue(ConfigurationRules::isUnknownTimeZone(...))
                        ->thenInvalid('Unknown time zone %s.')
                    ->end()
                ->end()
                ->integerNode('token_safety_margin')->min(0)->defaultValue(ApiConfiguration::DEFAULT_TOKEN_SAFETY_MARGIN_SECONDS)->info('Seconds before expiry from which an access token is renewed.')->end()
                ->booleanNode('log_siret')->defaultFalse()->info('Adds the SIRET to the call log lines (ADR 0010): off by default, the SIRET identifies an auto-entrepreneur.')->end()
                ->arrayNode('retry_safe_writes')
                    ->info('Writes of unspecified idempotency the sandbox showed idempotent, retried like a read after a transport error or a 5xx: any of "030", "031", "050", "070", "071" (quote them in YAML, ADR 0003). "060" is never resent. Enable "050" only once the sandbox proof exists: every resend e-mails the auto-entrepreneur.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(ConfigurationRules::isNeverResentOperation(...))
                            ->thenInvalid('"060" is never resent (ADR 0003): it cannot be declared safe to retry.')
                        ->end()
                        ->validate()
                            ->ifTrue(ConfigurationRules::isNotRetrySafeWriteCandidate(...))
                            ->thenInvalid('Unknown operation %s: only "030", "031", "050", "070" and "071" can be declared safe to retry (quote them in YAML).')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('http_client')->defaultNull()->info('Service id of a PSR-18 client (default: a dedicated Symfony HttpClient).')->end()
                ->floatNode('http_timeout')->min(0.1)->defaultValue(15.0)->info('Timeout in seconds of the default HTTP client; also the value checked against "workflow.stale_submission_after" (state the total timeout of your own client here when you give "http_client").')->end()
                ->scalarNode('credentials_provider')->defaultNull()->info('Service id of a CredentialsProvider.')->end()
                ->arrayNode('credentials')
                    ->info('Credentials by tenant id; use "%env(...)%" placeholders.')
                    ->useAttributeAsKey('tenant_id')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('client_id')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('client_secret')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('tenants')
                    ->info('Tenants processed by commands that cannot discover them (default: the keys of "credentials").')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('token_store')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')->defaultValue('cache.app')->cannotBeEmpty()->info('PSR-6 pool of the access tokens (a dedicated pool shared by every process is recommended).')->end()
                        ->scalarNode('service')->defaultNull()->info('Service id of a TokenStore (takes precedence over the cache pool).')->end()
                    ->end()
                ->end()
                ->arrayNode('gateway')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('simulated')->defaultFalse()->info('Tests only: replaces the URSSAF API with the in-memory Testing\\SimulatedAeGateway (public service, to script the scenarios).')->end()
                    ->end()
                ->end()
                ->append(self::rateLimiter())
                ->arrayNode('lock')
                    ->info('Non-blocking Symfony Lock per command / handler ("urssaf_tdae.<operation>.<scope>"): overlapping runs are skipped (command) or retried later (Messenger).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->info('"auto": enabled when symfony/lock is installed and the factory service exists.')->end()
                        ->scalarNode('factory')->defaultValue('lock.factory')->cannotBeEmpty()->info('Service id of a Symfony\\Component\\Lock\\LockFactory.')->end()
                        ->floatNode('ttl')->min(1)->defaultValue(3600.0)->info('Seconds after which a lock of a crashed process expires.')->end()
                    ->end()
                ->end()
                ->append(self::outbox())
                ->append(self::scheduler())
                ->append(self::workflow())
                ->append(self::persistence())
                ->arrayNode('messenger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->info('Registers the Messenger handlers of the account sync messages (020, a read).')->end()
                        ->scalarNode('bus')->defaultValue('messenger.default_bus')->cannotBeEmpty()->info('Bus of "urssaf:tdae:account:sync --async".')->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function headers(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('headers');
        $node
            ->info('Optional headers sent on every call.')
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(ConfigurationRules::duplicateHeaderNames(...))
                ->thenInvalid('"headers.request_id" and "headers.application_id.name" must be different headers.')
            ->end()
            ->children()
                ->scalarNode('request_id')->defaultNull()->info('Name of the optional request-id header (its value is a UUID per attempt, ADR 0002); null: not sent.')->end()
                ->arrayNode('application_id')
                    ->info('Optional fixed header sent on every call (name and value); both null: not sent.')
                    // A concrete array node cannot default to null: its children default to null instead.
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(ConfigurationRules::incompleteApplicationIdHeader(...))
                        ->thenInvalid('"headers.application_id" needs both "name" and "value", or neither.')
                    ->end()
                    ->children()
                        ->scalarNode('name')->defaultNull()->end()
                        ->scalarNode('value')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private static function rateLimiter(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('rate_limiter');
        $node
            ->info('Local sliding-window limiter per tenant and process (ADR 0013 item 3): off in the bundle, the host decides; 300 calls per minute is the published URSSAF quota.')
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(ConfigurationRules::rateLimiterServiceWhileDisabled(...))
                ->thenInvalid('"rate_limiter.service" requires "rate_limiter.enabled: true".')
            ->end()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->info('Throttles the calls of a tenant in the current process.')->end()
                ->integerNode('limit')->min(1)->defaultValue(SlidingWindowRateLimiter::DEFAULT_LIMIT)->info('Calls per tenant within the window (above 300, "urssaf:tdae:check" warns).')->end()
                ->scalarNode('window')
                    ->defaultValue(\sprintf('PT%dS', SlidingWindowRateLimiter::DEFAULT_WINDOW_SECONDS))
                    ->info('Window length, ISO 8601 duration (at least one second).')
                    ->validate()
                        ->ifTrue(ConfigurationRules::isInvalidPositiveSecondsInterval(...))
                        ->thenInvalid('Invalid ISO 8601 duration %s (at least one second).')
                    ->end()
                ->end()
                ->scalarNode('service')->defaultNull()->info('Service id of a host RateLimiter (replaces the built-in sliding window; requires "enabled").')->end()
            ->end();

        return $node;
    }

    private static function outbox(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('outbox');
        $node
            ->info('Integration events (Contract\\Event) written in the transaction of each state change, then relayed to the host (docs/integration-events.md).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('service')->defaultNull()->info('Service id of your Outbox (required with the "custom" persistence driver; default: the in-memory outbox).')->end()
                ->scalarNode('sink')->defaultValue(OutboxRegistrar::SINK_MESSENGER)->cannotBeEmpty()->info('"messenger" (bus below), "event_dispatcher", or the service id of an IntegrationEventSink.')->end()
                ->scalarNode('bus')->defaultValue('messenger.default_bus')->cannotBeEmpty()->info('Bus of the "messenger" sink.')->end()
                ->scalarNode('retention')->defaultValue(WorkflowPolicy::DEFAULT_OUTBOX_RETENTION)->info('How long a dispatched entry is kept (urssaf:tdae:outbox:purge).')->validate()->ifTrue(ConfigurationRules::isInvalidInterval(...))->thenInvalid('Invalid ISO 8601 duration %s.')->end()->end()
            ->end();

        return $node;
    }

    private static function scheduler(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('scheduler');
        $node
            ->info('Symfony Scheduler schedule running the safety crons of the package (RunCommandMessage): an alternative to the crontab. It never runs a write.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('name')->defaultValue('urssaf_tdae')->cannotBeEmpty()->info('Schedule name: consume it with "messenger:consume scheduler_<name>".')->end()
                ->arrayNode('frequencies')
                    ->addDefaultsIfNotSet()
                    ->append(self::frequency('sync_accounts', 'P1D', 'urssaf:tdae:account:sync (020 is the only polling: cheap, not free)'))
                    ->append(self::frequency('reconcile_declarations', 'PT15M', 'urssaf:tdae:declarations:reconcile'))
                    ->append(self::frequency('reconcile_payments', 'PT15M', 'urssaf:tdae:payments:reconcile'))
                    ->append(self::frequency('relay_outbox', 'PT1M', 'urssaf:tdae:outbox:relay (only when the outbox is enabled)'))
                    ->append(self::frequency('purge_outbox', 'P1D', 'urssaf:tdae:outbox:purge (only when the outbox is enabled)'))
                ->end()
            ->end();

        return $node;
    }

    private static function frequency(string $task, string $default, string $commands): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($task);
        $node
            ->defaultValue($default)
            ->info(\sprintf('ISO 8601 duration between two runs of %s; null to leave the task out.', $commands))
            ->validate()->ifTrue(ConfigurationRules::isInvalidOptionalInterval(...))->thenInvalid('Invalid ISO 8601 duration %s.')->end();

        return $node;
    }

    private static function workflow(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('workflow');
        $node
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(ConfigurationRules::defaultPageAboveMax(...))
                ->thenInvalid('"default_page_size" must not be greater than "max_page_size".')
            ->end()
            ->children()
                ->scalarNode('stale_submission_after')
                    ->defaultValue(WorkflowPolicy::DEFAULT_STALE_SUBMISSION_AFTER)
                    ->info('Age after which a submission left "submitting" is stale (moved to manual review, never resent). Must be longer than twice "http_timeout" (the API call and the token call).')
                    ->validate()->ifTrue(ConfigurationRules::isInvalidInterval(...))->thenInvalid('Invalid ISO 8601 duration %s.')->end()
                ->end()
                ->integerNode('max_submission_attempts')->min(1)->defaultValue(WorkflowPolicy::DEFAULT_MAX_SUBMISSION_ATTEMPTS)->info('Attempts after which a technical failure is no longer requeued.')->end()
                ->booleanNode('require_local_mandate')->defaultTrue()->info('Refuses an estimate, a declaration or a payment locally when no active mandate is tracked (ADR 0011).')->end()
                ->integerNode('default_page_size')->min(1)->defaultValue(WorkflowPolicy::DEFAULT_PAGE_SIZE)->info('Records loaded by a batch use case without limit.')->end()
                ->integerNode('max_page_size')->min(1)->defaultValue(WorkflowPolicy::MAX_PAGE_SIZE)->info('Upper bound of a batch.')->end()
                ->arrayNode('erasure')
                    ->addDefaultsIfNotSet()
                    ->info('Defaults of the account erasure (ForgetAccount, urssaf:tdae:account:forget, ADR 0017); a submission in flight is always refused.')
                    ->children()
                        ->booleanNode('allow_unknown_outcomes')->defaultFalse()->info('Erases the declarations and payments whose outcome is unknown instead of refusing.')->end()
                        ->booleanNode('allow_active_mandate')->defaultFalse()->info('Erases an auto-entrepreneur whose mandate is still active (it stays active at URSSAF) instead of refusing.')->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private static function persistence(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('persistence');
        $node
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(ConfigurationRules::customDriverWithoutRepositories(...))
                ->thenInvalid('The "custom" driver requires "account_repository", "mandate_repository", "declaration_record_repository" and "payment_record_repository".')
            ->end()
            ->children()
                ->enumNode('driver')
                    ->values([self::DRIVER_IN_MEMORY, self::DRIVER_DOCTRINE, self::DRIVER_CUSTOM])
                    ->defaultValue(self::defaultDriver())
                    ->info('"doctrine" when doctrine/orm is installed, else "in_memory" (tests and prototypes: every record is lost at the end of the process).')
                ->end()
                ->scalarNode('account_repository')->defaultNull()->info('Service id of your AeAccountRepository (driver "custom").')->end()
                ->scalarNode('mandate_repository')->defaultNull()->info('Service id of your MandateRepository (driver "custom").')->end()
                ->scalarNode('declaration_record_repository')->defaultNull()->info('Service id of your DeclarationRecordRepository (driver "custom").')->end()
                ->scalarNode('payment_record_repository')->defaultNull()->info('Service id of your PaymentRecordRepository (driver "custom").')->end()
                ->scalarNode('transaction_manager')->defaultNull()->info('Service id of your TransactionManager shared with the repositories (driver "custom"; default: ImmediateTransactionManager).')->end()
                ->scalarNode('workflow_statistics')->defaultNull()->info('Service id of your WorkflowStatistics (driver "custom", optional: without it, GetWorkflowOverviewHandler is not available).')->end()
                ->scalarNode('entity_manager')->defaultValue('default')->cannotBeEmpty()
                    ->info('Name of the Doctrine entity manager (driver "doctrine", wired by the Doctrine bridge). Use a DEDICATED entity manager (own connection) for this package: its repositories flush the whole unit of work of the entity manager they use, which would also write pending changes of the host entities sharing it.')
                ->end()
            ->end();

        return $node;
    }
}
