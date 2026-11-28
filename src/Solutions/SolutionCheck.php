<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

use Closure;
use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Packages\PackageBatchPlanner;
use Larena\UpdateClient\Packages\PackageBatchRefused;
use Larena\UpdateClient\Packages\PackageList;
use Larena\UpdateClient\Packages\PackageRelease;
use Larena\UpdateClient\Packages\VersionConstraint;
use RuntimeException;
use Throwable;

/**
 * S1 + S2 of a solution (§14 stage 4, matrix 34–35): everything is decided before anything is written.
 *
 * S1 ({@see SolutionVerifier}): signature by a release key, shape, product and version range, SitePack checksum, no
 * secret in `settings`; then every setting key must be declared by a package the solution installs: the `settings` of
 * its signed package release, or of its `module.yaml` when it is installed already (`solution_setting_undeclared`).
 *
 * S2, all refusals together:
 * - packages: one already installed in a version the solution's rule takes is `present`; any other comes from the
 *   signed package list (the newest release its rule takes; none: `solution_package_unavailable`, no list:
 *   `solution_package_list_unavailable`) and is checked by the batch planner ({@see PackageBatchPlanner::check()}:
 *   its `update_batch_*` refusals as they are);
 * - rights: every package with a capability the licence does not allow — one refusal `solution_rights_missing`
 *   naming all of them with «Попробовать» and «Купить» data ({@see SolutionRights});
 * - content: this stage installs only on a clean site — the site's own records where the SitePack goes are
 *   `solution_site_not_empty`, named ({@see SolutionSiteContent}).
 */
final readonly class SolutionCheck
{
    public function __construct(
        private SolutionVerifier $verifier,
        private PackageBatchPlanner $planner,
        private SolutionRights $rights,
        private SolutionSiteContent $content,
    ) {
    }

    /** @param Closure(): PackageList $packageList the verified package list of the site's sources, read only when needed */
    public function check(string $path, InstalledPackages $installed, Closure $packageList): SolutionPlan
    {
        [$bytes, $signature, $directory] = SolutionVerifier::files($path);
        $solution = $this->verifier->verify($bytes, $signature, $installed->productVersion, $directory);

        $refusals = [];
        $present = [];
        /** @var array<string,PackageRelease> $install */
        $install = [];
        $list = null;
        $listFailed = null;
        foreach ($solution->packages as $package) {
            $name = $package['name'];
            $current = $installed->versions[$name] ?? null;
            if ($current !== null && VersionConstraint::satisfies($current, $package['version'])) {
                $present[$name] = $current;
                continue;
            }
            if ($list === null && $listFailed === null) {
                try {
                    $list = $packageList();
                } catch (PackageBatchRefused $refused) {
                    $listFailed = $refused->getMessage();
                } catch (Throwable $exception) {
                    $listFailed = $exception instanceof RuntimeException && preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $exception->getMessage()) === 1
                        ? $exception->getMessage() : 'update_download_failed';
                }
            }
            if ($list === null) {
                $refusals[] = ['reason' => 'solution_package_list_unavailable', 'subject' => $name, 'detail' => (string) $listFailed];
                continue;
            }
            $pick = null;
            foreach ($list->of($name) as $release) {
                if (VersionConstraint::satisfies($release->version, $package['version'])
                    && ($pick === null || version_compare($release->version, $pick->version, '>'))) {
                    $pick = $release;
                }
            }
            if ($pick === null) {
                $refusals[] = ['reason' => 'solution_package_unavailable', 'subject' => $name, 'detail' => $package['version']];
                continue;
            }
            $install[$name] = $pick;
        }

        // S1, the whitelist: only keys the packages declare.
        $declared = [];
        foreach ($solution->packages as $package) {
            $name = $package['name'];
            $keys = isset($install[$name]) ? $install[$name]->settings : null;
            if ($keys === null && isset($present[$name])) {
                $keys = SolutionSettings::declared($installed->releaseDirectory . '/vendor/' . $name . '/module.yaml');
            }
            foreach ($keys ?? [] as $key) {
                $declared[$key] = true;
            }
        }
        $undeclared = [];
        foreach (array_keys($solution->settings) as $key) {
            if (!isset($declared[$key])) {
                $undeclared[] = ['reason' => 'solution_setting_undeclared', 'subject' => $key, 'detail' => 'no package of the solution declares it'];
            }
        }
        if ($undeclared !== []) {
            throw new SolutionRefused([...$undeclared, ...$refusals]);
        }

        // S2: rights, all missing ones in one refusal.
        $addons = [];
        $missing = [];
        foreach ($solution->packages as $package) {
            $capability = $package['capability'];
            if ($capability === null || $this->rights->allows($capability)) {
                continue;
            }
            $missing[$package['name']] = true;
            $offer = $this->rights->offer($capability, $package['name']);
            $addons[] = ['capability' => $capability, 'package' => $package['name']] + $offer;
        }
        if ($addons !== []) {
            $refusals[] = [
                'reason' => 'solution_rights_missing',
                'subject' => implode(', ', array_column($addons, 'package')),
                'detail' => implode(', ', array_column($addons, 'capability')),
                'addons' => $addons,
            ];
        }

        // S2: a clean site only.
        if ($solution->sitepack !== null) {
            try {
                $blockers = $this->content->blockers($solution->sitepack['path']);
            } catch (Throwable $exception) {
                $code = $exception->getMessage();
                $refusals[] = ['reason' => 'solution_sitepack_invalid', 'subject' => $solution->sitepack['ref'],
                    'detail' => preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'unreadable'];
                $blockers = [];
            }
            if ($blockers !== []) {
                $refusals[] = [
                    'reason' => 'solution_site_not_empty',
                    'subject' => implode(', ', array_column($blockers, 'label')),
                    'detail' => implode(', ', array_map(static fn (array $blocker): string => $blocker['structure'] . ': ' . $blocker['records'], $blockers)),
                    'blockers' => $blockers,
                ];
            }
        }

        // S2: the batch planner's own refusals for what would be installed (the missing rights are named above).
        $checked = array_values(array_filter($install, static fn (PackageRelease $release): bool => !isset($missing[$release->package])));
        foreach ($this->planner->check($checked, $installed) as $refusal) {
            $refusals[] = ['reason' => $refusal['reason'], 'subject' => $refusal['package'], 'detail' => $refusal['requirement']];
        }

        if ($refusals !== []) {
            throw new SolutionRefused($refusals);
        }
        $keys = array_keys($declared);
        sort($keys, SORT_STRING);

        return new SolutionPlan($solution, $present, array_values($install), $keys);
    }
}
