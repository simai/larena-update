<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use RuntimeException;

/**
 * A signed set release (`release.json`, its signature and the archive): the product release a new site installs and
 * a whole-product update takes (plan §1–§3). Checked against the version the running release has; laid out by
 * {@see ReleaseArchiveExtractor} into `releases/<version>` with the shared links.
 */
final readonly class SignedReleaseCandidate implements CycleCandidate
{
    public function __construct(
        private ReleaseSignatureVerifier $verifier,
        private ReleaseArchiveExtractor $extractor,
        private string $releaseBytes,
        private string $signatureBytes,
        private string $archivePath,
        private string $channel = 'stable',
    ) {
    }

    public function diskBytes(string $siteRoot): int
    {
        return is_file($this->archivePath) && !is_link($this->archivePath) ? (int) filesize($this->archivePath) : 0;
    }

    public function prepare(string $siteRoot, string $from, string $fromVersion): PreparedRelease
    {
        $release = $this->verifier->verify($this->releaseBytes, $this->signatureBytes, $fromVersion, $this->channel);
        ReleaseSignatureVerifier::verifyArchive($release, $this->archivePath);
        // The signed file list, when the release has one: checked against release.json and the archive here.
        $files = FileList::beside($release, $this->archivePath);
        if ($files !== null) {
            $this->extractor->checkList($files, $this->archivePath);
        }

        return new PreparedRelease(ReleaseDirectory::id($release->version), $release->backwardIncompatibleMigrations, ['kind' => 'release'], $release);
    }

    public function lay(string $siteRoot, PreparedRelease $prepared): void
    {
        $release = $prepared->subject instanceof VerifiedRelease ? $prepared->subject : throw new RuntimeException('update_release_invalid');
        $this->extractor->extract($release, $this->archivePath, $siteRoot, $prepared->releaseId, $this->releaseBytes, $this->signatureBytes);
        ReleaseSharedLinks::link($siteRoot, $prepared->releaseId);
    }
}
