<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use Larena\UpdateClient\Release\FileList;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Release\VerifiedRelease;
use RuntimeException;
use Throwable;

/**
 * What the files of the running release are compared with: the file list kept in `<release>/.larena-signed/`,
 * trusted again on every load through its signed `release.json` and the site's public release keys — never because
 * a local copy exists. The kept archive of the release (A4) is named by the same signed description.
 */
final readonly class SignedBaseline
{
    public const FILES = ['release.json', 'release.json.sig', FileList::FILE];

    private function __construct(
        public FileList $list,
        public VerifiedRelease $release,
        public string $archivePath,
        public string $fingerprint,
    ) {
    }

    /**
     * @param array<string,string> $trustedKeys key id => base64 public key
     * @param list<string>         $revokedKeyIds
     *
     * @throws IntegrityUnavailable
     */
    public static function load(IntegrityLayout $layout, array $trustedKeys, array $revokedKeyIds = []): self
    {
        $directory = $layout->signedDirectory();
        $fingerprint = self::fingerprint($layout);
        if ($layout->sourceCheckout()) {
            throw new IntegrityUnavailable('integrity_source_checkout');
        }
        $bytes = [];
        foreach (self::FILES as $name) {
            $path = $directory . '/' . $name;
            $read = !is_link($directory) && is_file($path) && !is_link($path) ? @file_get_contents($path) : false;
            if (!is_string($read)) {
                throw new IntegrityUnavailable('integrity_list_missing');
            }
            $bytes[$name] = $read;
        }
        if ($trustedKeys === []) {
            throw new IntegrityUnavailable('integrity_keys_missing');
        }
        try {
            $verifier = new ReleaseSignatureVerifier($trustedKeys, $revokedKeyIds);
            $release = $verifier->verifyKept($bytes['release.json'], $bytes['release.json.sig']);
            if ($release->files === null || $release->files->file !== FileList::FILE) {
                throw new RuntimeException('release_files_not_signed');
            }
            $list = FileList::forRelease($release, $bytes[FileList::FILE]);
        } catch (Throwable $failure) {
            throw new IntegrityUnavailable('integrity_list_untrusted', self::code($failure));
        }

        return new self($list, $release, $directory . '/' . $release->archiveFile, $fingerprint);
    }

    /** The three signed files as they lie now (size, times, inode): a different value means «load and verify again». */
    public static function fingerprint(IntegrityLayout $layout): string
    {
        $parts = [];
        foreach (self::FILES as $name) {
            $parts[] = IntegrityLayout::stat($layout->signedDirectory() . '/' . $name) ?? '-';
        }

        // A site from source has no signed files at all; its marker changes the value when that comes or goes.
        return implode('|', $parts) . ($parts === ['-', '-', '-'] && $layout->sourceCheckout() ? '|source' : '');
    }

    private static function code(Throwable $failure): string
    {
        $message = $failure->getMessage();

        return preg_match('/\A[a-z][a-z0-9_]{0,80}\z/D', $message) === 1 ? $message : 'release_verification_failed';
    }
}
