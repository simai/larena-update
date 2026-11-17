<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Throwable;

/** Asks the update sources, in order, for the newest signed release; downloads and changes nothing. */
final class UpdateCheckCommand extends UpdateCommand
{
    protected $signature = 'larena:update:check
        {--json : Render a machine-readable result}';

    protected $description = 'Check the update sources for a newer signed Larena release.';

    public function handle(SiteUpdateClient $client): int
    {
        return $this->respond(function () use ($client): array {
            $installed = $client->installedVersion();
            $source = $client->source();
            try {
                $offer = $source->latest($installed, $client->channel());
            } catch (Throwable $exception) {
                return ['status' => 'failed', 'reason' => self::reason($exception), 'installed' => $installed, 'source_errors' => $source->errors()];
            }
            if ($offer === null) {
                return ['status' => 'up_to_date', 'installed' => $installed, 'channel' => $client->channel(), 'source_errors' => $source->errors()];
            }
            $release = $offer->release;

            return [
                'status' => 'available',
                'installed' => $installed,
                'version' => $release->version,
                'channel' => $release->channel,
                'source' => $offer->source,
                'minimum_version' => $release->minimumVersion,
                'php' => $release->php,
                'archive_bytes' => $release->archiveBytes,
                'backward_incompatible_migrations' => $release->backwardIncompatibleMigrations,
                'key_id' => $release->keyId,
                'notes' => $release->notes,
                'source_errors' => $source->errors(),
            ];
        }, static function (array $payload): array {
            if ($payload['status'] === 'up_to_date') {
                return ['Larena ' . $payload['installed'] . ' is the newest release on the ' . $payload['channel'] . ' channel.'];
            }
            $lines = [
                'A newer release is available: ' . $payload['version'] . ' (installed ' . $payload['installed'] . ').',
                'Source: ' . $payload['source'] . ', signed by key ' . $payload['key_id'] . '.',
            ];
            if ($payload['backward_incompatible_migrations'] === true) {
                $lines[] = 'Its database changes cannot be undone by switching code back: a rollback restores the database from the copy.';
            }
            foreach ((array) $payload['notes'] as $locale => $text) {
                $lines[] = '[' . $locale . '] ' . (is_string($text) ? $text : '');
            }
            $lines[] = 'Apply it with: php artisan larena:update:apply ' . $payload['version'] . ' --actor=<administrator subject_ref>';

            return $lines;
        }, ['up_to_date', 'available']);
    }
}
