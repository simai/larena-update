<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use RuntimeException;
use Throwable;

/**
 * Downloads one signed release, checks it, and runs the update cycle with automatic rollback
 * (plan 2026-11-17-update-client-plan.md, §3). Nothing is installed without this command.
 */
final class UpdateApplyCommand extends UpdateCommand
{
    protected $signature = 'larena:update:apply
        {version : The release version to install, for example 1.3.0}
        {--actor= : Existing active administrator subject_ref (the site copy checks it)}
        {--idempotency-key= : Repeat a request safely; generated when omitted}
        {--json : Render a machine-readable result}';

    protected $description = 'Install a signed Larena release: copy, new code, migrations, switch, health check; rolls back on failure.';

    public function handle(SiteUpdateClient $client): int
    {
        return $this->respond(function () use ($client): array {
            $actor = $this->actor();
            $version = (string) $this->argument('version');
            $key = trim((string) $this->option('idempotency-key'));
            if ($key === '') {
                $key = 'update:' . $version . ':' . bin2hex(random_bytes(8));
            } elseif (preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{2,127}\z/D', $key) !== 1) {
                throw new RuntimeException('update_idempotency_key_invalid');
            }
            $installed = $client->installedVersion();
            $source = $client->source();
            try {
                $download = $source->download($version, $installed, $client->channel());
            } catch (Throwable $exception) {
                return ['status' => 'failed', 'reason' => self::reason($exception), 'installed' => $installed, 'source_errors' => $source->errors()];
            }

            $preflight = $client->preflight();
            $cycle = $client->cycle($actor, $preflight)->apply(
                $client->request($download->release, $actor, $key),
                $download->releaseBytes,
                $download->signatureBytes,
                $download->archivePath,
                $client->channel(),
            );
            if ($cycle->state === UpdateCycleState::Completed) {
                try {
                    $source->forget($version);
                } catch (Throwable) {
                    // A leftover download folder is cleaned on the next run; the update itself is done.
                }
            }

            return ['status' => $cycle->state->value, 'source' => $download->source, 'idempotency_key' => $key, 'notes' => $preflight->notes()] + self::cycle($cycle);
        }, static function (array $payload): array {
            $lines = ['Update ' . ($payload['from'] ?? '?') . ' → ' . ($payload['to'] ?? '?') . ': ' . self::describeState((string) $payload['status']) . '.'];
            if (isset($payload['failed_step'])) {
                $lines[] = 'Failed step: ' . $payload['failed_step'] . ' (' . ($payload['reason'] ?? 'unknown') . ').';
            } elseif (isset($payload['reason'])) {
                $lines[] = 'Reason: ' . $payload['reason'] . '.';
            }
            if (isset($payload['backup_ref'])) {
                $lines[] = 'Site copy (larena/backup job): ' . $payload['backup_ref'] . '.';
            }
            if (in_array('queue_check_skipped', (array) ($payload['notes'] ?? []), true)) {
                $lines[] = 'Note: the queue check was skipped (no jobs table in the site database).';
            }
            $lines[] = 'Cycle: ' . ($payload['cycle_id'] ?? '?') . '. Details: php artisan larena:update:status';

            return $lines;
        }, [UpdateCycleState::Completed->value]);
    }
}
