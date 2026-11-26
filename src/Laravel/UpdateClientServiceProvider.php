<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\IntegrityHistory;
use Larena\UpdateClient\Integrity\ReleaseSealer;
use Larena\UpdateClient\Laravel\Console\IntegrityCheckCommand;
use Larena\UpdateClient\Laravel\Console\IntegrityRestoreCommand;
use Larena\UpdateClient\Laravel\Console\UpdateApplyCommand;
use Larena\UpdateClient\Laravel\Console\UpdateCheckCommand;
use Larena\UpdateClient\Laravel\Console\UpdatePackagesCommand;
use Larena\UpdateClient\Laravel\Console\UpdateRollbackCommand;
use Larena\UpdateClient\Laravel\Console\UpdateStatusCommand;
use Larena\UpdateClient\Laravel\Integrity\AuditIntegrityHistory;
use Larena\UpdateClient\Laravel\Integrity\CheckCoreFiles;
use Larena\UpdateClient\Laravel\Integrity\IntegrityAccessOperationRegistrar;
use Larena\UpdateClient\Laravel\Integrity\IntegrityAdminController;
use Larena\UpdateClient\Laravel\Integrity\IntegrityScanJobHandler;
use Larena\UpdateClient\Laravel\Integrity\SiteIntegrity;
use Larena\UpdateClient\Source\CurlUpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpClient;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * The update client in a Larena site: configuration `update-client`, the `update_cycles` table, the commands
 * `larena:update:check|apply|packages|rollback|status` and the internal activation address
 * `POST /_larena/update/opcache-reset` (one-time token, see {@see OpcacheResetActivation}).
 *
 * The site's own file checks (owner decision 2026-11-20, {@see SiteIntegrity}): the main files on every request
 * (global middleware), the full check through the scheduler and the queue in portions, the alert bar, the pages
 * `/admin/integrity` with «Вернуть как было», `larena:integrity:check|restore`, and core's installer seal; the page's
 * rights `update.integrity.read|apply` are the update client's own ({@see IntegrityAccessOperationRegistrar}).
 */
final class UpdateClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/update-client.php', 'update-client');
        $this->registerIntegrity();

        $this->app->singletonIf(UpdateHttpClient::class, static fn (): UpdateHttpClient => new CurlUpdateHttpClient());
        $this->app->singletonIf(ArtisanRunner::class, static function (Application $app): ArtisanRunner {
            $process = (array) $app->make(ConfigRepository::class)->get('update-client.process', []);
            $php = $process['php_binary'] ?? null;

            return new ProcessArtisanRunner(is_string($php) ? $php : null, is_numeric($process['timeout'] ?? null) ? (int) $process['timeout'] : 900);
        });
        $this->app->bind(ActivationTokenStore::class, static function (Application $app): ActivationTokenStore {
            $ttl = $app->make(ConfigRepository::class)->get('update-client.activation.token_ttl', 120);

            return new ActivationTokenStore($app->storagePath('app/larena/update'), is_numeric($ttl) ? (int) $ttl : 120);
        });
        $this->app->bind(SiteUpdateClient::class, static function (Application $app): SiteUpdateClient {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SiteUpdateClient(
                SiteUpdateClient::withReleaseKeys((array) $config->get('update-client', []), $config->get(SiteUpdateClient::RELEASE_KEYS_CONFIG)),
                $app->basePath(),
                (string) $config->get('app.url', 'http://localhost'),
                static function () use ($app): PDO {
                    /** @var DatabaseManager $database */
                    $database = $app->make(DatabaseManager::class);

                    return $database->connection()->getPdo();
                },
                $app->make(LoggerInterface::class),
                $app->make(UpdateHttpClient::class),
                $app->make(ArtisanRunner::class),
                static function () use ($app): PDO {
                    /** @var DatabaseManager $database */
                    $database = $app->make(DatabaseManager::class);

                    return $database->reconnect()->getPdo();
                },
                static fn (): ?\Larena\Licensing\Site\SiteLicensing => class_exists(\Larena\Licensing\Site\SiteLicensing::class) && $app->bound(\Larena\Licensing\Site\SiteLicensing::class)
                    ? $app->make(\Larena\Licensing\Site\SiteLicensing::class) : null,
            );
        });
    }

    private function registerIntegrity(): void
    {
        $this->app->singleton(SiteIntegrity::class, static function (Application $app): SiteIntegrity {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $directory = $config->get('update-client.integrity.state_directory');
            $layout = new IntegrityLayout(
                $app->basePath(),
                is_string($directory) && $directory !== '' ? $directory : $app->storagePath('app/larena/integrity'),
                IntegrityLayout::storageRoots($app->storagePath()),
                IntegrityLayout::sourceInstallMarked($config->get('update-client.integrity.source_install')),
            );

            return new SiteIntegrity($layout, self::trustedKeys($config), self::revokedKeys($config), $app->make(IntegrityHistory::class), self::product($config));
        });
        $this->app->singletonIf(IntegrityHistory::class, static fn (Application $app): IntegrityHistory => new AuditIntegrityHistory(
            $app->make(LoggerInterface::class),
            static fn (): ?object => class_exists(\Larena\Audit\Runtime\AuditEventPipeline::class) && $app->bound(\Larena\Audit\Runtime\AuditEventPipeline::class)
                ? $app->make(\Larena\Audit\Runtime\AuditEventPipeline::class) : null,
        ));
        $this->app->bind(IntegrityAdminController::class, static fn (Application $app): IntegrityAdminController => new IntegrityAdminController(
            $app->make(SiteIntegrity::class),
            $app->make('view'),
            $app->make('translator'),
            self::allows($app),
        ));
        // Core's installer seal: the update client checks the signature, the list, the archive and every file.
        if (interface_exists(\Larena\Core\Contracts\InstalledReleaseSeal::class)) {
            $this->app->bind(\Larena\Core\Contracts\InstalledReleaseSeal::class, static fn (Application $app): \Larena\Core\Contracts\InstalledReleaseSeal
                => new \Larena\UpdateClient\Laravel\Integrity\SiteInstalledReleaseSeal(new ReleaseSealer(
                    self::trustedKeys($app->make(ConfigRepository::class)), self::revokedKeys($app->make(ConfigRepository::class)),
                    product: self::product($app->make(ConfigRepository::class)))));
        }
        $this->app->bind(\Larena\UpdateClient\Laravel\Admin\SiteUpdates::class, static fn (Application $app): \Larena\UpdateClient\Laravel\Admin\SiteUpdates
            => new \Larena\UpdateClient\Laravel\Admin\SiteUpdates(
                static fn (): SiteUpdateClient => $app->make(SiteUpdateClient::class),
                $app->storagePath('app/larena/update/last-check.json'),
                self::installDispatcher($app),
            ));
        $this->app->bind(\Larena\UpdateClient\Laravel\Admin\UpdatesAdminController::class, static fn (Application $app): \Larena\UpdateClient\Laravel\Admin\UpdatesAdminController
            => new \Larena\UpdateClient\Laravel\Admin\UpdatesAdminController(
                $app->make(\Larena\UpdateClient\Laravel\Admin\SiteUpdates::class), $app->make(\Illuminate\Contracts\View\Factory::class),
                $app->make('translator'), self::allows($app)));
        if (class_exists(\Larena\Queue\Runtime\JobTypeRegistry::class)) {
            $this->app->afterResolving(\Larena\Queue\Runtime\JobTypeRegistry::class, static function (\Larena\Queue\Runtime\JobTypeRegistry $registry, Application $app): void {
                self::registerScanJob($registry, $app);
                self::registerInstallJob($registry, $app);
            });
        }
        if (class_exists(\Larena\Scheduler\Runtime\ScheduledOperationRegistry::class)) {
            $this->app->afterResolving(\Larena\Scheduler\Runtime\ScheduledOperationRegistry::class, static function (\Larena\Scheduler\Runtime\ScheduledOperationRegistry $registry, Application $app): void {
                if (!$registry->has(IntegrityScanJobHandler::OPERATION)) {
                    $registry->register($app->make(\Larena\UpdateClient\Laravel\Integrity\IntegrityScheduledScan::class));
                }
            });
        }
        // The alert bar of the admin, where the admin has one (owner decision 2026-11-05).
        if (interface_exists(\Larena\Admin\Contracts\AdminAlertSource::class) && class_exists(\Larena\Admin\Alerts\AdminAlerts::class)) {
            $this->callAfterResolving(\Larena\Admin\Alerts\AdminAlerts::class, static function (\Larena\Admin\Alerts\AdminAlerts $alerts, Application $app): void {
                $alerts->register('larena/update:integrity', new \Larena\UpdateClient\Laravel\Integrity\IntegrityAdminAlertSource(
                    $app->make(SiteIntegrity::class), $app->make('translator'), self::allows($app)));
            });
        }
    }

    /**
     * «Установить» of the admin's «Обновления»: one attempt, a long timeout (a copy and migrations), run by a worker or the
     * dispatcher, never by the web request whose code it switches.
     */
    private static function registerInstallJob(\Larena\Queue\Runtime\JobTypeRegistry $registry, Application $app): void
    {
        if ($registry->has(\Larena\UpdateClient\Laravel\Admin\SiteUpdates::JOB_TYPE)) {
            return;
        }
        $registry->register(self::installJobDescriptor(), new \Larena\UpdateClient\Laravel\Admin\ReleaseInstallJobHandler(
            static fn (): ReleaseInstall => new ReleaseInstall($app->make(SiteUpdateClient::class)),
            static function () use ($app): void {
                $app->make(\Larena\UpdateClient\Laravel\Admin\SiteUpdates::class)->finished();
            },
        ));
    }

    /**
     * The install job: one attempt (a failed install rolls back and is not retried by itself), an hour at most, its
     * lease as long as its timeout.
     */
    public static function installJobDescriptor(): \Larena\Queue\Runtime\ImmutableJobDescriptor
    {
        return new \Larena\Queue\Runtime\ImmutableJobDescriptor(
            \Larena\UpdateClient\Laravel\Admin\SiteUpdates::JOB_TYPE, 'update.release.install', 'update.release.install.handler',
            3600, 1, 60, 3600, \Larena\Queue\Enums\QueuePriority::Maintenance, 'consumer_owned_required', 'larena.update.release.install.payload',
            [\Larena\Queue\Enums\RuntimeProfile::LaravelWorker, \Larena\Queue\Enums\RuntimeProfile::LarenaDispatcher, \Larena\Queue\Enums\RuntimeProfile::ArtisanTick],
        );
    }

    private static function registerScanJob(\Larena\Queue\Runtime\JobTypeRegistry $registry, Application $app): void
    {
        if ($registry->has(IntegrityScanJobHandler::JOB_TYPE)) {
            return;
        }
        $config = $app->make(ConfigRepository::class);
        $registry->register(new \Larena\Queue\Runtime\ImmutableJobDescriptor(
            IntegrityScanJobHandler::JOB_TYPE, IntegrityScanJobHandler::OPERATION, 'update.integrity.scan.handler',
            120, 3, 30, 60, \Larena\Queue\Enums\QueuePriority::Maintenance, 'consumer_owned_required', 'larena.update.integrity.scan.payload',
            [\Larena\Queue\Enums\RuntimeProfile::LaravelWorker, \Larena\Queue\Enums\RuntimeProfile::LarenaDispatcher, \Larena\Queue\Enums\RuntimeProfile::ArtisanTick],
            2000,
        ), new IntegrityScanJobHandler(
            $app->make(SiteIntegrity::class),
            max(100, (int) $config->get('update-client.integrity.portion_files', 2000)),
            max(0.5, (float) $config->get('update-client.integrity.portion_seconds', 5)),
        ));
    }

    /**
     * Hands «Установить» to the Larena queue, where it is installed with the install job; null otherwise.
     *
     * @return (\Closure(string, string, string): string)|null
     */
    private static function installDispatcher(Application $app): ?\Closure
    {
        if (!class_exists(\Larena\Queue\Runtime\DurableQueueDispatcher::class) || !$app->bound(\Larena\Queue\Runtime\DurableQueueDispatcher::class)
            || !$app->bound(\Larena\Queue\Runtime\JobTypeRegistry::class)
            || !$app->make(\Larena\Queue\Runtime\JobTypeRegistry::class)->has(\Larena\UpdateClient\Laravel\Admin\SiteUpdates::JOB_TYPE)) {
            return null;
        }

        return static fn (string $version, string $actor, string $key): string => $app->make(\Larena\Queue\Runtime\DurableQueueDispatcher::class)
            ->dispatch(new \Larena\Queue\Data\DispatchRequest(\Larena\UpdateClient\Laravel\Admin\SiteUpdates::JOB_TYPE,
                ['version' => $version, 'actor' => $actor, 'idempotency_key' => $key], $key, 'update:' . bin2hex(random_bytes(8))))->jobId;
    }

    /** @return array<string,string> */
    private static function trustedKeys(ConfigRepository $config): array
    {
        try {
            $keys = SiteUpdateClient::withReleaseKeys((array) $config->get('update-client', []), $config->get(SiteUpdateClient::RELEASE_KEYS_CONFIG))['trusted_keys'];
        } catch (\Throwable) {
            return [];
        }

        return array_filter(is_array($keys) ? $keys : [], static fn (mixed $key, mixed $id): bool => is_string($id) && is_string($key), ARRAY_FILTER_USE_BOTH);
    }

    /** The configured product (`LARENA_UPDATE_PRODUCT`); a malformed one checks against `larena`, which no other product's release names. */
    private static function product(ConfigRepository $config): string
    {
        try {
            return SiteUpdateClient::productOf((array) $config->get('update-client', []));
        } catch (\Throwable) {
            return 'larena';
        }
    }

    /** @return list<string> */
    private static function revokedKeys(ConfigRepository $config): array
    {
        $revoked = $config->get('update-client.revoked_key_ids', []);

        return array_values(array_filter(is_array($revoked) ? $revoked : [], 'is_string'));
    }

    /** @return \Closure(Request, string): bool whether the request's person may do an access operation */
    private static function allows(Application $app): \Closure
    {
        return static function (Request $request, string $operation) use ($app): bool {
            if (!class_exists(\Larena\Access\Runtime\AccessOperationAuthorizer::class) || !$app->bound(\Larena\Access\Runtime\AccessOperationAuthorizer::class)) {
                return false;
            }
            try {
                return $app->make(\Larena\Access\Runtime\AccessOperationAuthorizer::class)->authorize($request, $operation)->isAllowed();
            } catch (\Throwable) {
                return false;
            }
        };
    }

    /** «Обновления» of the admin: its read model, and its routes where the admin is installed. */
    private function bootUpdates(ConfigRepository $config): void
    {
        if ((bool) $config->get('update-client.updates.admin.enabled', true) && class_exists(\Larena\Admin\Alerts\AdminAlerts::class)) {
            $this->app->make('router')
                ->middleware((array) $config->get('update-client.updates.admin.middleware', ['web']))
                ->prefix((string) $config->get('update-client.updates.admin.prefix', 'admin/updates'))
                ->group(__DIR__ . '/../../routes/updates.php');
        }
    }

    private function bootIntegrity(ConfigRepository $config): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'larena-update-client');
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'larena-update-client');
        // «История» shows the integrity family in words.
        $sources = $config->get('larena-audit.history_sources', []);
        if (!is_array($sources) || !in_array(IntegrityHistory::PREFIX, array_column($sources, 'prefix'), true)) {
            $config->push('larena-audit.history_sources', ['prefix' => IntegrityHistory::PREFIX,
                'label' => 'larena-update-client::integrity.history.label', 'words' => 'larena-update-client::integrity.history.events']);
        }
        if ((bool) $config->get('update-client.integrity.admin.enabled', true) && class_exists(\Larena\Admin\Alerts\AdminAlerts::class)) {
            $this->app->make('router')
                ->middleware((array) $config->get('update-client.integrity.admin.middleware', ['web']))
                ->prefix((string) $config->get('update-client.integrity.admin.prefix', 'admin/integrity'))
                ->group(__DIR__ . '/../../routes/integrity.php');
        }
        if ((bool) $config->get('update-client.integrity.every_request', true)) {
            $kernel = $this->app->bound(\Illuminate\Contracts\Http\Kernel::class) ? $this->app->make(\Illuminate\Contracts\Http\Kernel::class) : null;
            if (is_object($kernel) && method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(CheckCoreFiles::class);
            }
        }
        $schedule = $config->get('update-client.integrity.schedule');
        if (is_string($schedule) && $schedule !== '' && class_exists(\Illuminate\Console\Scheduling\Schedule::class)) {
            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, static function (\Illuminate\Console\Scheduling\Schedule $scheduled) use ($schedule, $config): void {
                $scheduled->command('larena:integrity:check --queue --seconds=' . max(1, (int) $config->get('update-client.integrity.scheduled_seconds', 50)))
                    ->cron($schedule)
                    ->withoutOverlapping();
            });
        }
    }

    public function boot(): void
    {
        // The integrity check's own rights, where larena/access is in the site (lead decision 2026-11-20).
        if (class_exists(\Larena\Access\Runtime\AccessOperationRegistry::class) && $this->app->bound(\Larena\Access\Runtime\AccessOperationRegistry::class)) {
            (new IntegrityAccessOperationRegistrar())->register($this->app->make(\Larena\Access\Runtime\AccessOperationRegistry::class));
        }
        $this->bootIntegrity($this->app->make(ConfigRepository::class));
        $this->bootUpdates($this->app->make(ConfigRepository::class));
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/update-client.php');
        $this->publishes([__DIR__ . '/../../config/update-client.php' => $this->app->configPath('update-client.php')], 'larena-update-client-config');

        // The activation call arrives while the site is in maintenance; its own token guards it.
        $path = $this->app->make(ConfigRepository::class)->get('update-client.activation.path', '_larena/update/opcache-reset');
        if (is_string($path) && class_exists(PreventRequestsDuringMaintenance::class)) {
            PreventRequestsDuringMaintenance::except(ltrim($path, '/'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                UpdateCheckCommand::class,
                UpdateApplyCommand::class,
                UpdatePackagesCommand::class,
                UpdateRollbackCommand::class,
                UpdateStatusCommand::class,
                IntegrityCheckCommand::class,
                IntegrityRestoreCommand::class,
            ]);
        }
    }
}
