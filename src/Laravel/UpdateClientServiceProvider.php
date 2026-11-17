<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\ServiceProvider;
use Larena\UpdateClient\Laravel\Console\UpdateApplyCommand;
use Larena\UpdateClient\Laravel\Console\UpdateCheckCommand;
use Larena\UpdateClient\Laravel\Console\UpdateRollbackCommand;
use Larena\UpdateClient\Laravel\Console\UpdateStatusCommand;
use Larena\UpdateClient\Source\CurlUpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpClient;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * The update client in a Larena site: configuration `update-client`, the `update_cycles` table, the commands
 * `larena:update:check|apply|rollback|status` and the internal activation address
 * `POST /_larena/update/opcache-reset` (one-time token, see {@see OpcacheResetActivation}).
 */
final class UpdateClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/update-client.php', 'update-client');

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
                (array) $config->get('update-client', []),
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
            );
        });
    }

    public function boot(): void
    {
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
                UpdateRollbackCommand::class,
                UpdateStatusCommand::class,
            ]);
        }
    }
}
