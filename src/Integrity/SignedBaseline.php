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
 *
 * After a package batch the release also keeps the package releases it installed ({@see PackageLayer}): then
 * {@see $list} is the set list with those packages laid over it, {@see $setList} the set list as signed, and
 * {@see $layer} says where a clean copy of each file comes from.
 */
final readonly class SignedBaseline
{
    public const FILES = ['release.json', 'release.json.sig', FileList::FILE];

    private function __construct(
        public FileList $list,
        public VerifiedRelease $release,
        public string $archivePath,
        public string $fingerprint,
        public FileList $setList,
        public ?PackageLayer $layer = null,
    ) {
    }

    /**
     * Where a clean copy of a release file comes from ({@see PackageLayer::source()}; `['set']` without a layer).
     *
     * @return array{0:string,1?:string,2?:string}
     */
    public function source(string $path): array
    {
        return $this->layer?->source($path) ?? ['set'];
    }

    /**
     * @param array<string,string> $trustedKeys key id => base64 public key
     * @param list<string>         $revokedKeyIds
     *
     * @throws IntegrityUnavailable
     */
    public static function load(IntegrityLayout $layout, array $trustedKeys, array $revokedKeyIds = [], string $product = 'larena'): self
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
            $verifier = new ReleaseSignatureVerifier($trustedKeys, $revokedKeyIds, $product);
            $release = $verifier->verifyKept($bytes['release.json'], $bytes['release.json.sig']);
            if ($release->files === null || $release->files->file !== FileList::FILE) {
                throw new RuntimeException('release_files_not_signed');
            }
            $set = FileList::forRelease($release, $bytes[FileList::FILE]);
            $archive = $directory . '/' . $release->archiveFile;
            $layer = PackageLayer::load($layout, $verifier, $set, $archive);
            $list = $layer === null ? $set : $layer->apply($set);
        } catch (Throwable $failure) {
            throw new IntegrityUnavailable('integrity_list_untrusted', self::code($failure));
        }

        return new self($list, $release, $archive, $fingerprint, $set, $layer);
    }

    /**
     * The three signed files as they lie now (size, times, inode), and the batch record when there is one: a different
     * value means «load and verify again».
     */
    public static function fingerprint(IntegrityLayout $layout): string
    {
        $parts = [];
        foreach (self::FILES as $name) {
            $parts[] = IntegrityLayout::stat($layout->signedDirectory() . '/' . $name) ?? '-';
        }
        $record = PackageLayer::fingerprint($layout);

        // A site from source has no signed files at all; its marker changes the value when that comes or goes.
        return implode('|', $parts) . ($parts === ['-', '-', '-'] && $layout->sourceCheckout() ? '|source' : '')
            . ($record !== null ? '|packages:' . $record : '');
    }

    private static function code(Throwable $failure): string
    {
        $message = $failure->getMessage();

        return preg_match('/\A[a-z][a-z0-9_]{0,80}\z/D', $message) === 1 ? $message : 'release_verification_failed';
    }
}
