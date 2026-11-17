<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use Larena\UpdateClient\Release\VerifiedRelease;

/**
 * A release ready for the update cycle: the exact signed bytes of `release.json` and `release.json.sig` and the
 * archive on disk whose size and sha256 match the verified description. Nothing of it is unpacked yet.
 */
final readonly class DownloadedRelease
{
    public function __construct(
        public string $source,
        public VerifiedRelease $release,
        public string $releaseBytes,
        public string $signatureBytes,
        public string $archivePath,
    ) {
    }
}
