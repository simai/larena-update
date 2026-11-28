<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Throwable;

/**
 * Asks the update sources, in order, for the newest signed release; downloads and changes nothing. First it refreshes
 * the signed release key list (`keys.json`, {@see SiteUpdateClient::refreshKeys()}), so a release signed by a key the
 * list just added is taken in the same run.
 */
final class UpdateCheckCommand extends UpdateCommand
{
    protected $signature = 'larena:update:check
        {--json : Render a machine-readable result}';

    protected $description = 'Check the update sources for a newer signed Larena release.';

    public function handle(SiteUpdateClient $client): int
    {
        return $this->respond(function () use ($client): array {
            $installed = $client->installedVersion();
            $keys = $client->refreshKeys();
            $source = $client->source();
            try {
                $offer = $source->latest($installed, $client->channel());
            } catch (Throwable $exception) {
                return ['status' => 'failed', 'reason' => self::reason($exception), 'installed' => $installed, 'source_errors' => $source->errors(), 'keys' => $keys];
            }
            $revoked = $source->revoked();
            $revoked = $revoked !== null && $revoked->version === $installed ? ['version' => $revoked->version, 'fixed_in' => $revoked->fixedIn] : null;
            if ($offer === null) {
                return array_filter(['status' => 'up_to_date', 'installed' => $installed, 'channel' => $client->channel(), 'source_errors' => $source->errors(),
                    'revoked' => $revoked, 'keys' => $keys], static fn (mixed $value): bool => $value !== null);
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
                'keys' => $keys,
            ] + ($revoked === null ? [] : ['revoked' => $revoked]);
        }, static function (array $payload): array {
            $notices = is_array($payload['revoked'] ?? null)
                ? ['This version ' . $payload['installed'] . ' is revoked by the update server — update urgently'
                    . (is_string($payload['revoked']['fixed_in'] ?? null) ? ' (fixed in ' . $payload['revoked']['fixed_in'] . ').' : '.')]
                : [];
            $keys = is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
            $notices = [...$notices, ...match ($keys['result'] ?? null) {
                'accepted' => ['A new release key list ' . ($keys['list_version'] ?? '?') . ' was accepted (signed by ' . ($keys['signed_by'] ?? '?') . ').'],
                'ignored' => ['Warning: the release key list was ignored (' . ($keys['reason'] ?? '') . '); the keys stay as they were.'],
                'refused' => ['Warning: the release key list was refused (' . ($keys['reason'] ?? '') . ').'],
                default => [],
            }];
            if ($payload['status'] === 'up_to_date') {
                return [...$notices, 'Larena ' . $payload['installed'] . ' is the newest release on the ' . $payload['channel'] . ' channel.'];
            }
            $lines = [
                ...$notices,
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
