<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Throwable;

/** What the site runs now, which releases are on disk, and the last update cycles. Read-only. */
final class UpdateStatusCommand extends UpdateCommand
{
    protected $signature = 'larena:update:status
        {--limit=5 : How many recent cycles to show (1-50)}
        {--json : Render a machine-readable result}';

    protected $description = 'Show the installed Larena release, the releases on disk and the recent update cycles.';

    public function handle(SiteUpdateClient $client): int
    {
        return $this->respond(function () use ($client): array {
            $site = $client->siteRoot();
            $releases = array_values(array_filter(
                (array) scandir($site . '/releases'),
                static fn (mixed $name): bool => is_string($name) && $name !== '' && $name[0] !== '.' && is_dir($site . '/releases/' . $name) && !is_link($site . '/releases/' . $name),
            ));
            $limit = max(1, min(50, (int) $this->option('limit')));
            try {
                $store = $client->store();
                $cycles = array_map(static fn (array $row): array => self::cycle($row['snapshot'], $row['updated_at']), $store->latest($limit));
                $unfinished = $store->findUnfinished();
                $storeReason = null;
            } catch (Throwable $exception) {
                $cycles = [];
                $unfinished = null;
                $storeReason = self::reason($exception);
            }

            try {
                $installed = $client->installedVersion();
                $versionReason = null;
            } catch (Throwable $exception) {
                // A release without a known version (installed by an installer that wrote no `.larena-release`) is
                // still shown; updates are refused until it has one.
                $installed = null;
                $versionReason = self::reason($exception);
            }

            return array_filter([
                'status' => 'ok',
                'installed' => $installed,
                'version_reason' => $versionReason,
                'current_release' => $client->installedRelease(),
                'channel' => $client->channel(),
                'releases' => $releases,
                'maintenance' => is_file($site . '/storage/framework/down'),
                'sources' => $client->sources(),
                'trusted_key_ids' => $client->trustedKeyIds(),
                'unfinished_cycle' => $unfinished === null ? null : $unfinished->cycleId,
                'store_reason' => $storeReason,
                'cycles' => $cycles,
            ], static fn (mixed $value): bool => $value !== null);
        }, static function (array $payload): array {
            $lines = [
                'Installed release: ' . ($payload['installed'] ?? 'unknown version, ' . ($payload['version_reason'] ?? '') . ', in releases/' . $payload['current_release']) . ' (channel ' . $payload['channel'] . ').',
                'Releases on disk: ' . implode(', ', (array) $payload['releases']) . '.',
                'Maintenance mode: ' . ($payload['maintenance'] === true ? 'on' : 'off') . '.',
                'Update sources: ' . ((array) $payload['sources'] === [] ? 'none configured' : implode(', ', (array) $payload['sources'])) . '.',
                'Trusted release keys: ' . ((array) $payload['trusted_key_ids'] === [] ? 'none configured — updates are refused' : implode(', ', (array) $payload['trusted_key_ids'])) . '.',
            ];
            if (isset($payload['store_reason'])) {
                $lines[] = 'Update history is not readable: ' . $payload['store_reason'] . ($payload['store_reason'] === 'update_store_unavailable' ? ' (run php artisan migrate).' : '.');
            }
            if (isset($payload['unfinished_cycle'])) {
                $lines[] = 'An update cycle is not finished: ' . $payload['unfinished_cycle'] . '.';
            }
            if ((array) $payload['cycles'] === []) {
                $lines[] = 'No update cycles yet.';
            }
            foreach ((array) $payload['cycles'] as $cycle) {
                if (!is_array($cycle)) {
                    continue;
                }
                $line = '- ' . ($cycle['updated_at'] ?? '') . '  ' . ($cycle['from'] ?? '?') . ' → ' . ($cycle['to'] ?? '?') . '  '
                    . self::describeState((string) ($cycle['state'] ?? '')) . '  [' . ($cycle['cycle_id'] ?? '') . ']';
                if (isset($cycle['failed_step'])) {
                    $line .= '  step ' . $cycle['failed_step'] . ': ' . ($cycle['reason'] ?? '');
                } elseif (isset($cycle['reason'])) {
                    $line .= '  ' . $cycle['reason'];
                }
                $lines[] = $line;
            }

            return $lines;
        }, ['ok']);
    }
}
