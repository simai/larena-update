<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * Where the file list of a release is and what it must be: the `files` object of a signed `release.json`
 * (`{"file": "files.json", "sha256": "…", "bytes": …}`). The signature over `release.json` covers this reference,
 * so the list needs no signature of its own. See {@see FileList}.
 */
final readonly class FileListReference
{
    public function __construct(
        public string $file,
        public string $sha256,
        public int $bytes,
    ) {
    }
}
