<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * A release description whose signature was checked by a trusted key and whose content passed every rule of
 * {@see ReleaseSignatureVerifier}. Nothing is downloaded or written before a release is verified.
 */
final readonly class VerifiedRelease
{
    /**
     * @param array<string,string> $packages package name => exact revision, sorted by name
     * @param array<string,string> $notes    locale => text
     */
    public function __construct(
        public string $product,
        public string $version,
        public string $channel,
        public string $minimumVersion,
        public string $php,
        public string $archiveFile,
        public string $archiveSha256,
        public int $archiveBytes,
        public array $packages,
        public bool $backwardIncompatibleMigrations,
        public array $notes,
        public string $keyId,
        public string $releaseSha256,
    ) {
    }
}
