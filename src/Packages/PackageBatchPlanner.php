<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

/**
 * Chooses and checks a batch of package updates for one release folder (owner variant D, 2026-11-25). The server
 * offers only compatible batches; the site decides again here from the signed list, its own installed versions and
 * its licence, before anything is touched.
 *
 * Compatibility: every release's `requires.larena` holds for the product version the site runs, every
 * `requires.packages` rule holds for the version that package will have after the batch, and the packages a batch
 * installed earlier keep their own rules. A package whose installed version is not a number (`dev-main`) cannot be
 * compared, so it is refused, never guessed.
 *
 * A package the site does not have yet (a bought addon, a partner module; owner variant A: batches are for those)
 * is installed only when it is asked for by name (`--only`): the newest release the licence covers, with the same
 * rules. Its classes are added to the generated autoloader ({@see PackageAutoload}).
 */
final readonly class PackageBatchPlanner
{
    public function __construct(private PackageUpdateLicence $licence)
    {
    }

    /** @param list<string> $only take only these packages (all installed ones when empty) */
    public function plan(PackageList $list, InstalledPackages $installed, array $only = []): PackageBatchPlan
    {
        $refusals = [];
        $names = [];
        foreach ($only as $name) {
            if (!isset($installed->versions[$name]) && $list->of($name) === []) {
                $refusals[] = self::incompatible($name, 'not installed and not in the package list');
            }
            $names[$name] = true;
        }
        if ($only === []) {
            foreach ($list->releases as $release) {
                if (isset($installed->versions[$release->package])) {
                    $names[$release->package] = true;
                }
            }
        }
        ksort($names, SORT_STRING);

        $chosen = [];
        $held = [];
        foreach (array_keys($names) as $name) {
            $releases = $list->of($name);
            if (!isset($installed->versions[$name])) {
                // Not installed: only here because it was asked for by name; any release of it is «newer».
                $newer = $releases;
            } else {
                $current = VersionConstraint::normalize($installed->versions[$name]);
                if ($current === null) {
                    if ($releases !== []) {
                        $refusals[] = self::incompatible($name, 'installed version ' . $installed->versions[$name] . ' is not a release number');
                    }
                    continue;
                }
                $newer = array_values(array_filter($releases, static fn (PackageRelease $release): bool => version_compare($release->version, $current, '>')));
            }
            $pick = null;
            foreach ($newer as $release) {
                if (!$release->isPaid() || $this->licence->refusal($name, $release->releasedAt) === null) {
                    $pick = $release;
                    break;
                }
            }
            if ($pick !== null) {
                $chosen[] = $pick;
            }
            if ($newer !== [] && $pick !== $newer[0]) {
                $newest = $newer[0];
                $held[] = [
                    'package' => $name, 'version' => $newest->version, 'reason' => 'update_batch_licence_ended',
                    'requirement' => self::licenceRequirement((string) $this->licence->refusal($name, $newest->releasedAt), $newest),
                ];
            }
        }

        return new PackageBatchPlan($list, $chosen, $held, [...$refusals, ...$this->check($chosen, $installed)]);
    }

    /**
     * Checks exactly these releases against the release folder: newer than installed (or not installed yet), covered
     * by the licence, compatible after the batch. Used by {@see plan()} and again by the cycle right before it starts.
     *
     * @param list<PackageRelease> $releases
     * @return list<array{reason:string, package:string, requirement:string}>
     */
    public function check(array $releases, InstalledPackages $installed): array
    {
        $refusals = [];
        $after = $installed->versions;
        $batch = [];
        foreach ($releases as $release) {
            $after[$release->package] = $release->version;
            $batch[$release->package] = true;
        }
        foreach ($releases as $release) {
            $name = $release->package;
            if (isset($installed->versions[$name])) {
                $current = VersionConstraint::normalize($installed->versions[$name]);
                if ($current === null) {
                    $refusals[] = self::incompatible($name, 'installed version ' . $installed->versions[$name] . ' is not a release number');
                    continue;
                }
                if (version_compare($release->version, $current, '<=')) {
                    $refusals[] = self::incompatible($name, $release->version . ' is not newer than installed ' . $current);
                    continue;
                }
            } elseif (is_dir($installed->releaseDirectory . '/vendor/' . $name) || is_link($installed->releaseDirectory . '/vendor/' . $name)) {
                $refusals[] = self::incompatible($name, 'vendor/' . $name . ' is in the release but not in installed.json');
                continue;
            }
            if ($release->isPaid() && ($reason = $this->licence->refusal($name, $release->releasedAt)) !== null) {
                $refusals[] = ['reason' => 'update_batch_licence_ended', 'package' => $name, 'requirement' => self::licenceRequirement($reason, $release)];
                continue;
            }
            if (!VersionConstraint::satisfies($installed->productVersion, $release->requiresLarena)) {
                $refusals[] = self::incompatible($name, 'larena ' . $release->requiresLarena . ' (the site runs ' . $installed->productVersion . ')');
            }
            foreach ($release->requiresPackages as $dependency => $rule) {
                $refusals = [...$refusals, ...self::rule($name, $dependency, $rule, $after)];
            }
        }
        // A package an earlier batch installed keeps its own rules against the packages this batch replaces.
        foreach ($installed->requirements as $name => $rules) {
            if (isset($batch[$name])) {
                continue;
            }
            foreach ($rules['packages'] as $dependency => $rule) {
                if (isset($batch[$dependency])) {
                    $refusals = [...$refusals, ...self::rule($name, $dependency, $rule, $after)];
                }
            }
        }

        return $refusals;
    }

    /**
     * @param array<string,string> $after
     * @return list<array{reason:string, package:string, requirement:string}>
     */
    private static function rule(string $package, string $dependency, string $rule, array $after): array
    {
        if (!isset($after[$dependency])) {
            return [self::incompatible($package, $dependency . ' ' . $rule . ' (not installed)')];
        }
        if (!VersionConstraint::satisfies($after[$dependency], $rule)) {
            return [self::incompatible($package, $dependency . ' ' . $rule . ' (would be ' . $after[$dependency] . ')')];
        }

        return [];
    }

    /** @return array{reason:string, package:string, requirement:string} */
    private static function incompatible(string $package, string $requirement): array
    {
        return ['reason' => 'update_batch_incompatible', 'package' => $package, 'requirement' => $requirement];
    }

    private static function licenceRequirement(string $reason, PackageRelease $release): string
    {
        return $reason . ' (' . $release->version . ' released ' . $release->releasedAt->format('Y-m-d') . ')';
    }
}
