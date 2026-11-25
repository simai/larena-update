<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Larena\UpdateClient\Release\CycleCandidate;
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
        foreach ($releases as $release) {
            $archive = $this->batch->archives[$release->package]
                ?? throw PackageBatchRefused::one('update_batch_signature_invalid', $release->package, 'archive missing');
            PackageBatchLayout::verifyArchive($release, $archive);
            $refusals = [...$refusals, ...PackageArchiveCheck::check($this->extractor, $release, $archive, $installed)];
        }
        if ($refusals !== []) {
            throw new PackageBatchRefused($refusals);
        }
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
        ], new CheckedPackageBatch($list, $releases, $installed, $from));
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
