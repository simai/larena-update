<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Larena\Licensing\Site\SiteDataAccess;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Packages\PackageBatchRefused;
use Larena\UpdateClient\Release\ReleaseDirectory;
use RuntimeException;

/**
 * `larena:update:remove <vendor/name>` (matrix 20): removes an addon's code and keeps its data. Without `--apply` it
 * only says whether the addon can be removed. With `--apply` it runs one update cycle (copy, new release folder
 * without the addon, switch, health check, automatic rollback) and then notes «removed addon, data kept» in
 * larena/licensing: the addon's functions are read-only until it is installed again with `larena:update:packages
 * --only=<vendor/name> --apply` (same or newer version), which brings editing back on the same data. No table, record
 * or file of the addon is dropped.
 */
final class UpdateRemoveCommand extends UpdateCommand
{
    protected $signature = 'larena:update:remove
        {package : The addon to remove (vendor/name); its data stays, read-only}
        {--apply : Remove it (otherwise only show whether it can be removed)}
        {--actor= : Existing active administrator subject_ref (the site copy checks it); required with --apply}
        {--idempotency-key= : Repeat a request safely; generated when omitted}
        {--json : Render a machine-readable result}';

    protected $description = 'Remove an addon\'s code in one update cycle with a copy and automatic rollback; its data stays, read-only.';

    public function handle(SiteUpdateClient $client, SiteDataAccess $data): int
    {
        return $this->respond(function () use ($client, $data): array {
            $package = strtolower(trim((string) $this->argument('package')));
            if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,99}\/[a-z0-9][a-z0-9_.-]{0,99}\z/D', $package) !== 1) {
                throw new RuntimeException('update_remove_package_invalid');
            }
            $apply = (bool) $this->option('apply');
            $actor = $apply ? $this->actor() : null;
            $installedVersion = $client->installedVersion();
            $release = $client->installedRelease();
            $candidate = $client->removalCandidate($package);
            try {
                $version = $candidate->check(InstalledPackages::read($client->siteRoot() . '/releases/' . ReleaseDirectory::id($release), $installedVersion));
            } catch (PackageBatchRefused $refused) {
                return ['status' => 'refused', 'reason' => $refused->getMessage(), 'package' => $package, 'installed' => $installedVersion, 'refusals' => $refused->refusals];
            }
            $payload = ['package' => $package, 'version' => $version, 'installed' => $installedVersion, 'release' => $release, 'data' => 'kept'];
            if (!$apply || $actor === null) {
                return ['status' => 'available'] + $payload;
            }
            $key = trim((string) $this->option('idempotency-key'));
            if ($key === '') {
                $key = 'remove:' . str_replace('/', '.', $package) . ':' . bin2hex(random_bytes(8));
            } elseif (preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{2,127}\z/D', $key) !== 1) {
                throw new RuntimeException('update_idempotency_key_invalid');
            }
            $preflight = $client->preflight();
            $cycle = $client->cycle($actor, $preflight)->applyCandidate($client->removalRequest($package, $version, $actor, $key), $candidate, $client->channel());
            $readOnly = [];
            if ($cycle->state === UpdateCycleState::Completed) {
                $readOnly = $data->addonRemoved($package, $version);
            }

            return ['status' => $cycle->state->value, 'idempotency_key' => $key, 'read_only' => $readOnly, 'notes' => $preflight->notes()]
                + self::cycle($cycle) + $payload;
        }, function (array $payload): array {
            $status = (string) $payload['status'];
            $lines = [];
            if (isset($payload['cycle_id'])) {
                $lines[] = 'Removing ' . $payload['package'] . ' ' . $payload['version'] . ': ' . self::describeState($status) . '.';
                if ($status === UpdateCycleState::Completed->value) {
                    $lines[] = 'Its data is kept, read-only. Install it again with: php artisan larena:update:packages --only=' . $payload['package'] . ' --apply --actor=<administrator subject_ref>';
                } elseif (isset($payload['reason'])) {
                    $lines[] = 'Reason: ' . $payload['reason'] . '.' . (($text = $this->explain((string) $payload['reason'])) !== null ? ' ' . $text : '');
                }
                $lines[] = 'Cycle: ' . $payload['cycle_id'] . '. Details: php artisan larena:update:status';
            } elseif ($status === 'available') {
                $lines[] = $payload['package'] . ' ' . $payload['version'] . ' can be removed; its data stays, read-only.';
                $lines[] = 'Remove it with: php artisan larena:update:remove ' . $payload['package'] . ' --apply --actor=<administrator subject_ref>';
            } else {
                $lines[] = 'The addon cannot be removed: ' . ($payload['reason'] ?? 'unknown') . '.';
                if (($text = $this->explain((string) ($payload['reason'] ?? ''))) !== null) {
                    $lines[] = $text;
                }
                foreach ((array) ($payload['refusals'] ?? []) as $refusal) {
                    $lines[] = '  ' . $refusal['package'] . ': ' . $refusal['reason'] . ' — ' . $refusal['requirement'];
                }
            }

            return $lines;
        }, ['available', UpdateCycleState::Completed->value]);
    }
}
