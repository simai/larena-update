<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Larena\UpdateClient\Integrity\PackageLayer;
use Larena\UpdateClient\Release\CycleCandidate;
use Larena\UpdateClient\Release\FileList;
use Larena\UpdateClient\Release\PreparedRelease;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use RuntimeException;

/**
 * A batch of package updates as one cycle of {@see \Larena\UpdateClient\Release\ReleaseUpdateCycle} (owner variant
 * D, 2026-11-25): one maintenance window, one copy, one new release folder, one switch, one health check and, on any
 * failure, the same rollback — code back first, then the database.
 *
 * {@see prepare()} runs before the copy and writes nothing to the site's code: the signed list again
 * ({@see PackageListVerifier}: signature, expiry, list version not lower than seen), every chosen release is in it, the
 * batch fits the running release ({@see PackageBatchPlanner::check()}: newer, licence for closed packages,
 * compatibility), every archive matches its signed sha256, and every archive can replace its package folder
 * ({@see PackageArchiveCheck}). Only then the list version is noted as seen. The new release is
 * `<version>-p<list version>-<8 hex of the selection>`; {@see lay()} builds it ({@see PackageBatchLayout}).
 */
final readonly class PackageBatchCandidate implements CycleCandidate
{
    /** @param (Closure(): DateTimeImmutable)|null $clock */
    public function __construct(
        private ReleaseSignatureVerifier $verifier,
        private ReleaseArchiveExtractor $extractor,
        private PackageUpdateLicence $licence,
        private PackageBatch $batch,
        private string $channel = 'stable',
        private ?Closure $clock = null,
    ) {
    }

    public function diskBytes(string $siteRoot): int
    {
        $current = (new ReleasePointer($siteRoot))->current();
        $archives = 0;
        foreach ($this->batch->archives as $path) {
            $archives += is_file($path) && !is_link($path) ? (int) filesize($path) : 0;
        }
        // The preflight wants three times this free: the copy of the release once, every archive three times.
        $release = $current === null ? 0 : PackageBatchLayout::size(rtrim($siteRoot, '/') . '/releases/' . $current);

        return intdiv($release, 3) + 1 + $archives;
    }

    public function prepare(string $siteRoot, string $from, string $fromVersion): PreparedRelease
    {
        if ($this->batch->selection === []) {
            throw new RuntimeException('update_batch_empty');
        }
        $state = PackageListState::forSite($siteRoot);
        $list = (new PackageListVerifier($this->verifier))->verify($this->batch->listBytes, $this->batch->signatureBytes, $this->channel, $this->now(), $state->seen());
        $installed = InstalledPackages::read(rtrim($siteRoot, '/') . '/releases/' . ReleaseDirectory::id($from), $fromVersion);
        $releases = [];
        foreach ($this->batch->selection as $package => $version) {
            $releases[] = $list->find($package, $version)
                ?? throw PackageBatchRefused::one('update_batch_list_invalid', $package, $version . ' is not in the signed list');
        }
        $refusals = (new PackageBatchPlanner($this->licence))->check($releases, $installed);
        if ($refusals !== []) {
            throw new PackageBatchRefused($refusals);
        }
        $names = array_map(static fn (PackageRelease $release): string => $release->package, $releases);
        foreach ($releases as $release) {
            $archive = $this->batch->archives[$release->package]
                ?? throw PackageBatchRefused::one('update_batch_signature_invalid', $release->package, 'archive missing');
            PackageBatchLayout::verifyArchive($release, $archive);
            $refusals = [...$refusals, ...PackageArchiveCheck::check($this->extractor, $release, $archive, $installed, $names)];
        }
        if ($refusals !== []) {
            throw new PackageBatchRefused($refusals);
        }
        [$kept, $added, $generated] = $this->autoload($siteRoot, $from, $installed, $releases);
        $state->see($list->listVersion);

        $selection = $this->batch->selection;
        ksort($selection, SORT_STRING);
        $named = [];
        foreach ($selection as $package => $version) {
            $named[] = $package . '@' . $version;
        }
        $id = $fromVersion . '-p' . $list->listVersion . '-' . substr(hash('sha256', implode(',', $named)), 0, 8);
        $incompatible = array_filter($releases, static fn (PackageRelease $release): bool => $release->backwardIncompatibleMigrations) !== [];

        // The journal keeps facts up to 160 characters: a long batch is named by its size and checksum.
        $packages = implode(',', $named);
        if (strlen($packages) > 160) {
            $packages = count($named) . ' packages, sha256:' . substr(hash('sha256', $packages), 0, 16);
        }

        return new PreparedRelease($id, $incompatible, [
            'kind' => 'package_batch',
            'packages' => $packages,
            'list_version' => $list->listVersion,
        ], new CheckedPackageBatch($list, $releases, $installed, $from, $kept, $added, $generated));
    }

    /**
     * What the new release carries besides the batch archives: the kept archive of every package an earlier batch
     * installed, and — when the release has packages the set did not have — the Composer files with them in it,
     * computed from the signed set archive and the package archives ({@see PackageAutoload}).
     *
     * @param list<PackageRelease> $releases
     * @return array{0: array<string,string>, 1: list<string>, 2: array<string,string>}
     */
    private function autoload(string $siteRoot, string $from, InstalledPackages $installed, array $releases): array
    {
        $source = rtrim($siteRoot, '/') . '/releases/' . ReleaseDirectory::id($from);
        $batch = [];
        foreach ($releases as $release) {
            $batch[$release->package] = $release;
        }
        $verifier = new PackageListVerifier($this->verifier);
        $kept = [];
        $describe = [];
        foreach ($installed->kept as $name => $item) {
            if (isset($batch[$name])) {
                continue;
            }
            try {
                $release = $verifier->verifyRelease($item['release'], $item['signature']);
            } catch (\Throwable) {
                throw PackageBatchRefused::one('update_batch_signature_invalid', $name, 'the kept release of an earlier batch');
            }
            $archive = PackageLayer::archivePath($source, $name, $release->archiveFile);
            if (!is_file($archive) || is_link($archive)) {
                throw PackageBatchRefused::one('update_batch_layout_invalid', $name, PackageLayer::ARCHIVES . '/' . $name . ' is missing in the running release');
            }
            PackageBatchLayout::verifyArchive($release, $archive);
            $kept[$name] = $archive;
            if (($item['added'] ?? false) === true) {
                $describe[$name] = [$release, $archive];
            }
        }
        foreach ($batch as $name => $release) {
            if (!isset($installed->composer[$name]) || $installed->isAdded($name)) {
                $describe[$name] = [$release, (string) $this->batch->archives[$name]];
            }
        }
        if ($describe === []) {
            return [$kept, [], []];
        }
        ksort($describe, SORT_STRING);
        $originals = PackageAutoload::originals($this->extractor, ...$this->setList($source, (string) array_key_first($describe)));
        $added = [];
        foreach ($describe as [$release, $archive]) {
            $added[] = PackageAutoload::describe($this->extractor, $release, $archive);
        }

        return [$kept, array_keys($describe), PackageAutoload::render($originals, $added)];
    }

    /**
     * The signed set list and set archive the running release keeps (`.larena-signed/`), verified again.
     *
     * @return array{0: FileList, 1: string}
     */
    private function setList(string $release, string $package): array
    {
        $signed = $release . '/' . ReleaseArchiveExtractor::SIGNED;
        try {
            $read = static function (string $name) use ($signed): string {
                $path = $signed . '/' . $name;
                $bytes = !is_link($signed) && !is_link($path) && is_file($path) ? file_get_contents($path) : false;

                return is_string($bytes) ? $bytes : throw new RuntimeException('update_batch_set_list_missing');
            };
            $verified = $this->verifier->verifyKept($read('release.json'), $read('release.json.sig'));
            $set = FileList::forRelease($verified, $read($verified->files->file ?? FileList::FILE));
        } catch (\Throwable) {
            throw PackageBatchRefused::one('update_batch_set_list_missing', $package, 'the running release keeps no signed set file list');
        }

        return [$set, $signed . '/' . $verified->archiveFile];
    }

    public function lay(string $siteRoot, PreparedRelease $prepared): void
    {
        $batch = $prepared->subject instanceof CheckedPackageBatch ? $prepared->subject : throw new RuntimeException('update_release_invalid');
        PackageBatchLayout::build($this->extractor, $siteRoot, $batch, $prepared->releaseId, $this->batch->archives);
        ReleaseSharedLinks::link($siteRoot, $prepared->releaseId);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock !== null ? ($this->clock)() : new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
