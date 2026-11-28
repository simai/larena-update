<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Larena\UpdateClient\Laravel\SiteSolutionRights;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Laravel\StorageSolutionSiteContent;
use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Solutions\SolutionRefused;
use Throwable;

/**
 * `larena:solution:check <file|dir>` (§14 stage 4, S1–S2): checks a signed solution against this site and prints what
 * installing it would do, or why it cannot go — every refusal in plain words, the missing rights in one, each with
 * «Попробовать 14 дней» and «Купить». Nothing is written: no download, no state, no database row. Installing and
 * rolling back a solution are the next stages.
 */
final class SolutionCheckCommand extends UpdateCommand
{
    protected $signature = 'larena:solution:check
        {path : The solution folder (solution.json, solution.json.sig and its SitePack) or its solution.json}
        {--json : Render a machine-readable result}';

    protected $description = 'Check a signed solution against this site before anything is installed: signature, version, settings, rights, a clean site, packages.';

    public function handle(SiteUpdateClient $client): int
    {
        return $this->respond(function () use ($client): array {
            $installedVersion = $client->installedVersion();
            $release = $client->installedRelease();
            $installed = InstalledPackages::read($client->siteRoot() . '/releases/' . ReleaseDirectory::id($release), $installedVersion);
            $check = $client->solutionCheck($this->rights(), new StorageSolutionSiteContent(function (): ConnectionInterface {
                /** @var DatabaseManager $database */
                $database = $this->laravel->make(DatabaseManager::class);

                return $database->connection();
            }));
            try {
                $plan = $check->check((string) $this->argument('path'), $installed, $client->packageListReader());
            } catch (SolutionRefused $refused) {
                return ['status' => 'refused', 'reason' => $refused->getMessage(), 'installed' => $installedVersion,
                    'refusals' => array_map(fn (array $refusal): array => $refusal + ['text' => $this->words($refusal)], $refused->refusals)];
            }

            return ['status' => 'planned', 'installed' => $installedVersion] + $plan->toArray();
        }, function (array $payload): array {
            if ($payload['status'] === 'planned') {
                $lines = [$this->text('planned', ['solution' => $payload['solution'], 'version' => $payload['version']])];
                foreach ($payload['present'] as $package) {
                    $lines[] = '  ' . $package['package'] . ' ' . $package['version'] . ' — ' . $this->text('present');
                }
                foreach ($payload['install'] as $package) {
                    $lines[] = '  ' . $package['package'] . ' ' . $package['version'] . ' — ' . $this->text('install');
                }
                if ($payload['sitepack'] !== null) {
                    $lines[] = '  ' . $this->text('sitepack', ['ref' => $payload['sitepack']['ref']]);
                }
                if ($payload['settings'] !== []) {
                    $lines[] = '  ' . $this->text('settings', ['keys' => implode(', ', $payload['settings'])]);
                }

                return $lines;
            }
            $lines = [$this->text('refused')];
            foreach ((array) ($payload['refusals'] ?? []) as $refusal) {
                $lines[] = '  ' . $refusal['text'];
            }

            return $lines;
        }, ['planned']);
    }

    /** @param array<string,mixed> $refusal */
    private function words(array $refusal): string
    {
        $reason = (string) $refusal['reason'];
        if ($reason === 'solution_rights_missing') {
            $lines = [];
            foreach ((array) $refusal['addons'] as $addon) {
                $actions = [];
                if ($addon['trial'] === true) {
                    $actions[] = '«' . $this->text('try', ['days' => (int) $addon['trial_days']], (int) $addon['trial_days']) . '»';
                }
                $actions[] = '«' . $this->text('buy') . '»' . (is_string($addon['shop_url']) ? ' ' . $addon['shop_url'] : '');
                $lines[] = '«' . ($addon['title'] ?? $addon['capability']) . '» (' . $addon['package'] . '): ' . implode(', ', $actions);
            }

            return $this->text('rights_missing') . ' ' . implode('; ', $lines) . '.';
        }
        if ($reason === 'solution_site_not_empty') {
            $names = array_map(fn (array $blocker): string => '«' . $blocker['label'] . '» — ' . $this->text('records', ['count' => $blocker['records']], (int) $blocker['records']),
                (array) $refusal['blockers']);

            return $this->text('site_not_empty') . ' ' . implode('; ', $names) . '.';
        }
        $explained = $this->explain($reason);

        return $refusal['subject'] . ': ' . ($explained ?? $reason) . ($refusal['detail'] !== '' ? ' (' . $refusal['detail'] . ')' : '');
    }

    /** @param array<string,mixed> $replace */
    private function text(string $key, array $replace = [], ?int $count = null): string
    {
        $line = 'larena-update-client::solutions.' . $key;
        try {
            $translator = $this->laravel->make('translator');
            $text = $count === null ? $translator->get($line, $replace) : $translator->choice($line, $count, $replace);
        } catch (Throwable) {
            return $key;
        }

        return is_string($text) && $text !== $line ? $text : $key;
    }

    private function rights(): SiteSolutionRights
    {
        $make = function (string $class): ?object {
            try {
                return class_exists($class) && $this->laravel->bound($class) ? $this->laravel->make($class) : null;
            } catch (Throwable) {
                return null;
            }
        };

        return new SiteSolutionRights(
            $make(\Larena\Licensing\Site\SiteLicensing::class),
            $make(\Larena\Licensing\Catalog\CapabilityCatalog::class),
            $make(\Larena\Licensing\Site\SiteAddons::class),
        );
    }
}
