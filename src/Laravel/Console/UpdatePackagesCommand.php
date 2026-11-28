<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use DateTimeImmutable;
use Larena\Licensing\Site\SiteDataAccess;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Packages\PackageBatch;
use Larena\UpdateClient\Packages\PackageBatchPlan;
use Larena\UpdateClient\Packages\PackageBatchPlanner;
use Larena\UpdateClient\Packages\PackageBatchRefused;
use Larena\UpdateClient\Release\ReleaseDirectory;
use RuntimeException;
use Throwable;

/**
 * Package updates as one batch (owner variant D, 2026-11-25). Without `--apply` it only shows the batch the signed
 * package list offers this site: the packages that would change, the closed packages the licence does not cover any
 * more, and why a batch cannot go. With `--apply` it downloads the archives and installs the batch in one update cycle
 * (copy, new release folder, switch, health check, automatic rollback). `larena:update:status` and
 * `larena:update:rollback` see the batch as one cycle. An addon installed again after `larena:update:remove` (same or
 * newer version) is taken off larena/licensing's «removed, data kept» list, so editing comes back on the same data.
 */
final class UpdatePackagesCommand extends UpdateCommand
{
    protected $signature = 'larena:update:packages
        {--apply : Install the batch (otherwise only show it)}
        {--only=* : Take only these packages (vendor/name)}
        {--actor= : Existing active administrator subject_ref (the site copy checks it); required with --apply}
        {--idempotency-key= : Repeat a request safely; generated when omitted}
        {--json : Render a machine-readable result}';

    protected $description = 'Show or install the package updates this site may take, as one batch with one copy and automatic rollback.';

    public function handle(SiteUpdateClient $client, SiteDataAccess $data): int
    {
        return $this->respond(function () use ($client, $data): array {
            $apply = (bool) $this->option('apply');
            $actor = $apply ? $this->actor() : null;
            $only = array_values(array_filter(array_map('trim', (array) $this->option('only')), static fn (string $name): bool => $name !== ''));
            $installedVersion = $client->installedVersion();
            $source = $client->packageSource();
            try {
                [$listBytes, $signatureBytes, $from] = $source->list();
                $state = $client->packageListState();
                $list = $client->packageListVerifier()->verify($listBytes, $signatureBytes, $client->channel(), new DateTimeImmutable(), $state->seen());
                $state->see($list->listVersion);
            } catch (PackageBatchRefused $refused) {
                return ['status' => 'refused', 'reason' => $refused->getMessage(), 'installed' => $installedVersion, 'refusals' => $refused->refusals];
            } catch (Throwable $exception) {
                return ['status' => 'failed', 'reason' => self::reason($exception), 'installed' => $installedVersion, 'source_errors' => $source->errors()];
            }
            $release = $client->installedRelease();
            $installed = InstalledPackages::read($client->siteRoot() . '/releases/' . ReleaseDirectory::id($release), $installedVersion);
            $plan = (new PackageBatchPlanner($client->packageLicence()))->plan($list, $installed, $only);
            $payload = self::plan($plan, $installed) + ['installed' => $installedVersion, 'release' => $release, 'source' => $from];
            if ($plan->refused()) {
                return ['status' => 'refused', 'reason' => $plan->refusals[0]['reason']] + $payload;
            }
            if ($plan->empty()) {
                return ['status' => 'up_to_date'] + $payload;
            }
            if (!$apply || $actor === null) {
                return ['status' => 'available'] + $payload;
            }

            $key = trim((string) $this->option('idempotency-key'));
            if ($key === '') {
                $key = 'packages:' . $list->listVersion . ':' . bin2hex(random_bytes(8));
            } elseif (preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{2,127}\z/D', $key) !== 1) {
                throw new RuntimeException('update_idempotency_key_invalid');
            }
            $archives = [];
            try {
                foreach ($plan->releases as $package) {
                    $archives[$package->package] = $source->download($package, $from);
                }
            } catch (Throwable $exception) {
                return ['status' => 'failed', 'reason' => self::reason($exception)] + $payload;
            }
            $preflight = $client->preflight();
            $cycle = $client->cycle($actor, $preflight)->applyCandidate(
                $client->batchRequest($plan, $actor, $key),
                $client->packageCandidate(new PackageBatch($listBytes, $signatureBytes, $plan->selection(), $archives)),
                $client->channel(),
            );
            if ($cycle->state === UpdateCycleState::Completed) {
                foreach ($plan->releases as $package) {
                    if (!isset($installed->versions[$package->package])) {
                        $data->addonInstalled($package->package);
                    }
                }
                try {
                    $source->forget();
                } catch (Throwable) {
                    // A leftover download folder is cleaned on the next run; the batch itself is installed.
                }
            }

            return ['status' => $cycle->state->value, 'idempotency_key' => $key, 'notes' => $preflight->notes()] + self::cycle($cycle) + $payload;
        }, function (array $payload): array {
            $lines = [];
            $status = (string) $payload['status'];
            if (isset($payload['cycle_id'])) {
                $lines[] = 'Package batch ' . ($payload['from'] ?? '?') . ' → ' . ($payload['to'] ?? '?') . ': ' . self::describeState($status) . '.';
                if (isset($payload['failed_step'])) {
                    $lines[] = 'Failed step: ' . $payload['failed_step'] . ' (' . ($payload['reason'] ?? 'unknown') . ').';
                } elseif (isset($payload['reason'])) {
                    $lines[] = 'Reason: ' . $payload['reason'] . '.' . (($text = $this->explain((string) $payload['reason'])) !== null ? ' ' . $text : '');
                }
            } elseif ($status === 'up_to_date') {
                $lines[] = 'No package updates for this site in list ' . ($payload['list_version'] ?? '?') . '.';
            } elseif ($status === 'available') {
                $lines[] = 'Package updates in list ' . $payload['list_version'] . ' (valid until ' . $payload['list_expires_at'] . '), one batch:';
            } else {
                $lines[] = 'The package batch cannot be installed: ' . ($payload['reason'] ?? 'unknown') . '.';
                if (($text = $this->explain((string) ($payload['reason'] ?? ''))) !== null) {
                    $lines[] = $text;
                }
            }
            foreach ((array) ($payload['packages'] ?? []) as $package) {
                $lines[] = '  ' . $package['package'] . ' ' . $package['from'] . ' → ' . $package['to'] . ' (' . $package['released_at'] . ($package['paid'] ? ', paid' : '') . ')';
            }
            foreach ((array) ($payload['held'] ?? []) as $held) {
                $lines[] = '  held: ' . $held['package'] . ' ' . $held['version'] . ' — ' . $held['requirement'] . (($text = $this->explain($held['reason'])) !== null ? ' ' . $text : '');
            }
            foreach ((array) ($payload['refusals'] ?? []) as $refusal) {
                $lines[] = '  ' . $refusal['package'] . ': ' . $refusal['reason'] . ' — ' . $refusal['requirement'];
            }
            if ($status === 'available') {
                $lines[] = 'Install it with: php artisan larena:update:packages --apply --actor=<administrator subject_ref>';
            }
            if (isset($payload['cycle_id'])) {
                $lines[] = 'Cycle: ' . $payload['cycle_id'] . '. Details: php artisan larena:update:status';
            }

            return $lines;
        }, ['up_to_date', 'available', UpdateCycleState::Completed->value]);
    }

    /** @return array<string,mixed> */
    private static function plan(PackageBatchPlan $plan, InstalledPackages $installed): array
    {
        $packages = [];
        foreach ($plan->releases as $release) {
            $packages[] = [
                'package' => $release->package,
                'from' => $installed->versions[$release->package] ?? 'new',
                'to' => $release->version,
                'released_at' => $release->releasedAt->format('Y-m-d'),
                'paid' => $release->isPaid(),
                'backward_incompatible_migrations' => $release->backwardIncompatibleMigrations,
            ];
        }

        return [
            'list_version' => $plan->list->listVersion,
            'list_expires_at' => $plan->list->expiresAt->format(DATE_ATOM),
            'packages' => $packages,
            'held' => $plan->held,
            'refusals' => $plan->refusals,
        ];
    }
}
