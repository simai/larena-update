<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use Larena\UpdateClient\Release\FileList;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use RuntimeException;
use Throwable;

/**
 * Gives a freshly installed release its `.larena-signed/` folder (the installer's seal, core `larena:install-seal`):
 * a first install does not pass through the update client's extractor, so the signed files are kept here, after:
 * the signature of `release.json` by a trusted key; the list's size and sha256 as `release.json` names them; the
 * archive's sha256; every archive entry against the list; and every file of the installed release against the list's
 * sha256. Only then `release.json`, `release.json.sig`, `files.json` and the archive are written, in one rename.
 */
final readonly class ReleaseSealer
{
    /**
     * @param array<string,string> $trustedKeys
     * @param list<string>         $revokedKeyIds
     */
    public function __construct(
        private array $trustedKeys,
        private array $revokedKeyIds = [],
        private ReleaseArchiveExtractor $extractor = new ReleaseArchiveExtractor(),
        private string $product = 'larena',
    ) {
    }

    /** @return array{sealed: bool, reason: ?string, version: ?string, files: int} */
    public function seal(string $releaseRoot, string $signedFrom): array
    {
        $root = rtrim($releaseRoot, '/');
        $from = rtrim($signedFrom, '/');
        if ($root === '' || $root[0] !== '/' || is_link($root) || !is_dir($root) || $from === '' || !is_dir($from)) {
            throw new RuntimeException('release_seal_source_invalid');
        }
        if ($this->trustedKeys === []) {
            throw new RuntimeException('release_trusted_keys_missing');
        }
        $releaseBytes = self::read($from . '/release.json');
        $signatureBytes = self::read($from . '/release.json.sig');
        $release = (new ReleaseSignatureVerifier($this->trustedKeys, $this->revokedKeyIds, $this->product))->verifyKept($releaseBytes, $signatureBytes);
        if ($release->files === null) {
            throw new RuntimeException('release_files_not_signed');
        }
        $listBytes = self::read($from . '/' . $release->files->file);
        $list = FileList::forRelease($release, $listBytes);
        $archive = $from . '/' . $release->archiveFile;
        ReleaseSignatureVerifier::verifyArchive($release, $archive);
        $this->extractor->checkList($list, $archive);
        $problems = $list->check($root, true);
        if ($problems !== []) {
            throw new RuntimeException('release_seal_files_differ');
        }

        $signed = $root . '/' . ReleaseArchiveExtractor::SIGNED;
        if (is_link($signed) || file_exists($signed)) {
            $kept = is_file($signed . '/release.json') && !is_link($signed . '/release.json') ? file_get_contents($signed . '/release.json') : false;
            if ($kept === $releaseBytes) {
                return ['sealed' => true, 'reason' => null, 'version' => $release->version, 'files' => count($list->entries)];
            }
            throw new RuntimeException('release_seal_exists');
        }
        $partial = $signed . '.partial';
        ReleaseDirectory::remove($partial);
        if (!@mkdir($partial, 0755)) {
            throw new RuntimeException('release_directory_write_failed');
        }
        try {
            foreach (['release.json' => $releaseBytes, 'release.json.sig' => $signatureBytes, FileList::FILE => $listBytes] as $name => $bytes) {
                if (file_put_contents($partial . '/' . $name, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('release_directory_write_failed');
                }
            }
            ReleaseArchiveExtractor::keepArchive($release, $archive, $partial);
            if (!rename($partial, $signed)) {
                throw new RuntimeException('release_directory_write_failed');
            }
        } catch (Throwable $failure) {
            ReleaseDirectory::remove($partial);
            throw $failure;
        }

        return ['sealed' => true, 'reason' => null, 'version' => $release->version, 'files' => count($list->entries)];
    }

    private static function read(string $path): string
    {
        $bytes = !is_link($path) && is_file($path) && filesize($path) <= FileList::MAX_BYTES ? file_get_contents($path) : false;

        return is_string($bytes) ? $bytes : throw new RuntimeException('release_files_missing');
    }
}
