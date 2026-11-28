<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use Larena\UpdateClient\Integrity\PackageLayer;
use Larena\UpdateClient\Release\CycleCandidate;
use Larena\UpdateClient\Release\FileList;
use Larena\UpdateClient\Release\PreparedRelease;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use RuntimeException;
use Throwable;

/**
 * Removing an addon (matrix 20) as one cycle of {@see \Larena\UpdateClient\Release\ReleaseUpdateCycle}: the same
 * copy, new release folder, switch, health check and rollback as a batch. The new release is the running one without
 * the addon's code — its folder `vendor/<vendor>/<name>`, its kept archive, its entry in the record, its classes in
 * the generated Composer files (computed again from the signed set archive and the packages that stay, the set's own
 * files when none stays) — and without Laravel's package discovery cache, so its provider is gone on the next boot.
 *
 * Its data stays: shared `storage` is never copied or touched, no migration runs down, no table is dropped. The
 * update client then notes «removed addon, data kept» in larena/licensing, which makes the addon's functions
 * read-only until it is installed again through a batch (same or newer version, `larena:update:packages --only`).
 *
 * Only a package a batch added (a bought addon, a partner module) can be removed: a package of the set is part of the
 * product (`update_remove_not_addon`); a package another installed package requires stays (`update_remove_required`).
 * {@see prepare()} writes nothing.
 */
final readonly class PackageRemovalCandidate implements CycleCandidate
{
    public function __construct(
        private ReleaseSignatureVerifier $verifier,
        private ReleaseArchiveExtractor $extractor,
        private string $package,
    ) {
    }

    public function diskBytes(string $siteRoot): int
    {
        $current = (new \Larena\UpdateClient\Release\ReleasePointer($siteRoot))->current();
        $release = $current === null ? 0 : PackageBatchLayout::size(rtrim($siteRoot, '/') . '/releases/' . $current);

        return intdiv($release, 3) + 1;
    }

    /** The package's installed version in a release folder, when it is an addon that can be removed (else a refusal). */
    public function check(InstalledPackages $installed): string
    {
        $name = $this->package;
        if (!isset($installed->versions[$name])) {
            throw PackageBatchRefused::one('update_remove_not_installed', $name, 'not installed in the running release');
        }
        if (!isset($installed->kept[$name]) || !$installed->isAdded($name)) {
            throw PackageBatchRefused::one('update_remove_not_addon', $name, 'part of the product set: it updates with the product');
        }
        $refusals = [];
        foreach ($installed->composer as $other => $entry) {
            $requires = is_array($entry['require'] ?? null) ? $entry['require'] : [];
            if ($other !== $name && array_key_exists($name, $requires)) {
                $refusals[] = ['reason' => 'update_remove_required', 'package' => $name, 'requirement' => 'required by ' . $other];
            }
        }
        foreach ($installed->requirements as $other => $rules) {
            if ($other !== $name && isset($rules['packages'][$name])) {
                $refusals[] = ['reason' => 'update_remove_required', 'package' => $name, 'requirement' => 'required by ' . $other];
            }
        }
        if ($refusals !== []) {
            throw new PackageBatchRefused(array_values(array_unique($refusals, SORT_REGULAR)));
        }

        return $installed->versions[$name];
    }

    public function prepare(string $siteRoot, string $from, string $fromVersion): PreparedRelease
    {
        $source = rtrim($siteRoot, '/') . '/releases/' . ReleaseDirectory::id($from);
        $installed = InstalledPackages::read($source, $fromVersion);
        $version = $this->check($installed);

        $listVerifier = new PackageListVerifier($this->verifier);
        $kept = [];
        $describe = [];
        foreach ($installed->kept as $name => $item) {
            if ($name === $this->package) {
                continue;
            }
            try {
                $release = $listVerifier->verifyRelease($item['release'], $item['signature']);
            } catch (Throwable) {
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
        ksort($describe, SORT_STRING);
        $originals = PackageAutoload::originals($this->extractor, ...$this->setList($source));
        $added = [];
        foreach ($describe as [$release, $archive]) {
            $added[] = PackageAutoload::describe($this->extractor, $release, $archive);
        }
        // With no added package left the release takes the set's own Composer files, as the integrity checks expect.
        $generated = $added === [] ? $originals : PackageAutoload::render($originals, $added);

        $id = $fromVersion . '-p' . $installed->keptListVersion . '-' . substr(hash('sha256', 'remove:' . $this->package . '@' . $version . ':' . $from), 0, 8);

        return new PreparedRelease($id, false, [
            'kind' => 'package_removal',
            'packages' => $this->package . '@' . $version,
            'list_version' => $installed->keptListVersion,
        ], new CheckedPackageBatch(null, [], $installed, $from, $kept, array_keys($describe), $generated, [$this->package]));
    }

    public function lay(string $siteRoot, PreparedRelease $prepared): void
    {
        $batch = $prepared->subject instanceof CheckedPackageBatch ? $prepared->subject : throw new RuntimeException('update_release_invalid');
        PackageBatchLayout::build($this->extractor, $siteRoot, $batch, $prepared->releaseId, []);
        ReleaseSharedLinks::link($siteRoot, $prepared->releaseId);
    }

    /**
     * The signed set list and set archive the running release keeps (`.larena-signed/`), verified again.
     *
     * @return array{0: FileList, 1: string}
     */
    private function setList(string $release): array
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
        } catch (Throwable) {
            throw PackageBatchRefused::one('update_batch_set_list_missing', $this->package, 'the running release keeps no signed set file list');
        }

        return [$set, $signed . '/' . $verified->archiveFile];
    }
}
