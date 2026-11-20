<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Release\FileList;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// The signed file list of a release (files.json, schema larena.release-files): build, verify, extract, keep.
(static function (): void {
    // A throwaway key for this test only; no real release key is ever in a repository.
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $verifier = new ReleaseSignatureVerifier(['release.test' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $root = Kit::temp('files');

    /** @param list<array{0:string,1:string,2?:string}> $entries name, tar type, data */
    $tar = static function (array $entries): string {
        $tar = '';
        foreach ($entries as $entry) {
            [$name, $type, $data] = $entry + [2 => ''];
            $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
                . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 1763337600) . "\0" . '        ' . $type . str_repeat("\0", 100) . "ustar\0" . '00';
            $header = str_pad($header, 512, "\0");
            $header = substr_replace($header, sprintf('%06o', array_sum(array_map('ord', str_split($header)))) . "\0 ", 148, 8);
            $tar .= $header . ($data === '' ? '' : str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0"));
        }

        return (string) gzencode($tar . str_repeat("\0", 1024));
    };
    $code = [
        ['public/', '5'],
        ['artisan', '0', "<?php // artisan\n"],
        ['public/.htaccess', '0', "RewriteEngine On\n"],
        ['public/index.php', '0', "<?php echo 'site';\n"],
    ];
    /** @param list<array{0:string,1:string,2?:string}> $entries */
    $entriesOf = static function (array $entries): array {
        $list = [];
        foreach ($entries as $entry) {
            [$name, $type, $data] = $entry + [2 => ''];
            $path = rtrim($name, '/');
            $list[] = $type === '5'
                ? ['path' => $path, 'type' => 'dir', 'bytes' => 0]
                : ['path' => $path, 'type' => 'file', 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
        }
        usort($list, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $list;
    };
    $listBytes = static fn (string $archive, array $entries, array $override = []): string => (string) json_encode(array_replace([
        'schema' => 'larena.release-files', 'schema_version' => 1, 'product' => 'larena', 'version' => '1.3.0',
        'archive_sha256' => hash('sha256', $archive), 'entries' => $entries, 'core_files' => ['public/.htaccess', 'public/index.php'],
    ], $override), JSON_UNESCAPED_SLASHES);
    $release = static function (string $archive, ?string $list) use ($secret): array {
        $description = [
            'schema' => 'larena.release', 'schema_version' => 1, 'product' => 'larena', 'version' => '1.3.0', 'channel' => 'stable',
            'minimum_version' => '1.0.0', 'php' => '^8.3',
            'archive' => ['file' => 'larena-1.3.0.tar.gz', 'sha256' => hash('sha256', $archive), 'bytes' => strlen($archive)],
            'packages' => ['larena/core' => str_repeat('a', 40)], 'backward_incompatible_migrations' => false, 'notes' => [],
        ];
        if ($list !== null) {
            $description['files'] = ['file' => 'files.json', 'sha256' => hash('sha256', $list), 'bytes' => strlen($list)];
        }
        $bytes = (string) json_encode($description, JSON_UNESCAPED_SLASHES);

        return [$bytes, (string) json_encode(['key_id' => 'release.test', 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))])];
    };
    $case = 0;
    /** Writes archive and list into a folder of their own, verifies the signed release, extracts into a fresh site. */
    $extract = static function (string $archive, ?string $list, ?string $signedList = null) use (&$case, $root, $release, $verifier): array {
        $folder = $root . '/case-' . ++$case;
        mkdir($folder . '/site', 0755, true);
        file_put_contents($folder . '/larena-1.3.0.tar.gz', $archive);
        if ($list !== null) {
            file_put_contents($folder . '/files.json', $list);
        }
        [$bytes, $signature] = $release($archive, $signedList ?? $list);
        $verified = $verifier->verify($bytes, $signature, '1.2.0');
        $directory = (new ReleaseArchiveExtractor())->extract($verified, $folder . '/larena-1.3.0.tar.gz', $folder . '/site', null, $bytes, $signature);

        return [$verified, $directory, $folder];
    };
    $reason = static fn (callable $call): string => Kit::reason($call);

    try {
        // A good list passes: verified, compared, hashed, and kept with the release.
        $archive = $tar($code);
        $list = $listBytes($archive, $entriesOf($code));
        [$verified, $directory] = $extract($archive, $list);
        Kit::check($verified->files !== null && $verified->files->file === 'files.json' && $verified->files->sha256 === hash('sha256', $list), 'release.json must name the signed list.');
        Kit::check(is_file($directory . '/public/index.php'), 'A release that matches its list must be unpacked.');
        Kit::check(file_get_contents($directory . '/.larena-signed/files.json') === $list
            && is_file($directory . '/.larena-signed/release.json') && is_file($directory . '/.larena-signed/release.json.sig'), 'The checked list and the signed description must be kept with the release.');
        $parsed = FileList::parse($list, 'larena', '1.3.0', hash('sha256', $archive));
        Kit::check($parsed->coreFiles === ['public/.htaccess', 'public/index.php'] && count($parsed->entries) === 4, 'The list must read back with its core files.');
        Kit::check($parsed->check($directory) === [], 'An untouched release must match its list.');
        file_put_contents($directory . '/public/index.php', "<?php echo 'bots';\n");
        unlink($directory . '/artisan');
        Kit::check($parsed->check($directory) === ['artisan' => 'missing', 'public/index.php' => 'checksum_differs'], 'A changed or removed file must be named.');

        // A release without files still verifies, with files null, and keeps no list.
        [$old, $oldDirectory] = $extract($archive, null);
        Kit::check($old->files === null && !file_exists($oldDirectory . '/.larena-signed/files.json') && is_file($oldDirectory . '/artisan'), 'A release built before file lists must verify with files null.');

        // Each failure has its own code, and leaves no release behind.
        $failures = [
            'release_files_digest_differs' => fn () => $extract($archive, $list, $listBytes($archive, $entriesOf($code), ['version' => '1.3.1'])),
            'release_files_entry_extra' => fn () => $extract($tar([...$code, ['extra.php', '0', '<?php']]), $listBytes($tar([...$code, ['extra.php', '0', '<?php']]), $entriesOf($code))),
            'release_files_entry_missing' => fn () => $extract($tar(array_slice($code, 0, 3)), $listBytes($tar(array_slice($code, 0, 3)), $entriesOf($code))),
        ];
        $grown = $entriesOf($code);
        $grown[0]['bytes']++;
        $failures['release_files_entry_size_differs'] = fn () => $extract($archive, $listBytes($archive, $grown));
        $otherSha = $entriesOf($code);
        $otherSha[0]['sha256'] = hash('sha256', 'something else');
        $failures['release_files_checksum_differs'] = fn () => $extract($archive, $listBytes($archive, $otherSha));
        foreach ($failures as $code_ => $call) {
            $before = $case;
            $got = $reason($call);
            Kit::check($got === $code_, "Expected {$code_}, got {$got}.");
            $releases = $root . '/case-' . ($before + 1) . '/site/releases';
            Kit::check(!is_dir($releases . '/1.3.0') && !file_exists($releases . '/.1.3.0.partial'), "{$code_} must leave no release.");
        }

        // Entries that are not normalised relative paths inside the root are refused by the list itself.
        $file = ['type' => 'file', 'bytes' => 1, 'sha256' => hash('sha256', 'x')];
        $bad = [
            'release_files_path_traversal' => [['path' => 'app/../../escape.php'] + $file],
            'release_files_path_absolute' => [['path' => '/etc/passwd'] + $file],
            'release_files_entry_duplicate' => [['path' => 'twice'] + $file, ['path' => 'twice'] + $file],
            'release_files_link_escape' => [['path' => 'public/up', 'type' => 'link', 'bytes' => 8, 'target' => '../../..']],
            'release_files_path_invalid' => [['path' => './app.php'] + $file],
            'release_files_entry_order' => [['path' => 'b'] + $file, ['path' => 'a'] + $file],
        ];
        foreach ($bad as $code_ => $entries) {
            $got = $reason(fn () => FileList::parse($listBytes($archive, $entries, ['core_files' => []])));
            Kit::check($got === $code_, "Expected {$code_}, got {$got}.");
        }
        $under = [['path' => 'link', 'type' => 'link', 'bytes' => 3, 'target' => 'app'], ['path' => 'link/x.php'] + $file];
        Kit::check($reason(fn () => FileList::parse($listBytes($archive, $under, ['core_files' => []]))) === 'release_files_link_escape', 'An entry under a link must be refused.');
        $inside = [['path' => 'public', 'type' => 'dir', 'bytes' => 0], ['path' => 'public/latest', 'type' => 'link', 'bytes' => 6, 'target' => '../app']];
        Kit::check($reason(fn () => FileList::parse($listBytes($archive, $inside, ['core_files' => []]))) === 'accepted', 'A link that stays inside the root is allowed in a package list.');
        Kit::check($reason(fn () => FileList::parse($listBytes($archive, $entriesOf($code), ['core_files' => ['public']]))) === 'release_files_core_invalid', 'A core file must be a listed file.');
        Kit::check($reason(fn () => FileList::parse($listBytes($archive, $entriesOf($code)), 'larena', '1.4.0')) === 'release_files_version_differs', 'A list of another version must be refused.');
        Kit::check($reason(fn () => FileList::parse($listBytes($archive, $entriesOf($code), ['schema_version' => 2]))) === 'release_files_schema_unsupported', 'An unknown list schema version must be refused.');
        Kit::check(FileList::parse($listBytes($archive, $entriesOf($code), ['product' => 'vendor/package', 'version' => '2.1.0-beta']), 'vendor/package')->product === 'vendor/package', 'One package is described by the same format.');

        // The signed reference itself must be well formed.
        [$bytes] = $release($archive, $list);
        $broken = str_replace('"file":"files.json"', '"file":"../files.json"', $bytes);
        Kit::check($reason(fn () => $verifier->verify($broken, (string) json_encode(['key_id' => 'release.test', 'signature' => base64_encode(sodium_crypto_sign_detached($broken, $secret))]), '1.2.0')) === 'release_files_reference_invalid', 'A path in the list name must be refused.');

        // The source fetches the list after the signature and before the archive, and refuses a replaced one.
        [$bytes, $signature] = $release($archive, $list);
        $base = 'https://github.com/example/larena/releases/download/v1.3.0/';
        $served = [$base . 'release.json' => [200, $bytes], $base . 'release.json.sig' => [200, $signature], $base . 'files.json' => [200, $list], $base . 'larena-1.3.0.tar.gz' => [200, $archive]];
        $http = FakeUpdateHttpClient::serving($served);
        $download = (new ReleaseSource($http, ['https://github.com/example/larena/releases'], $root . '/downloads', $verifier))->download('1.3.0', '1.2.0');
        Kit::check(file_get_contents(dirname($download->archivePath) . '/files.json') === $list && $http->requests[2]->url === $base . 'files.json' && $http->requests[2]->maxBytes === strlen($list), 'The list must be fetched up to its signed size before the archive.');
        Kit::remove($root . '/downloads');
        $http = FakeUpdateHttpClient::serving([$base . 'files.json' => [200, strrev($list)]] + $served);
        Kit::check($reason(fn () => (new ReleaseSource($http, ['https://github.com/example/larena/releases'], $root . '/downloads', $verifier))->download('1.3.0', '1.2.0')) === 'release_files_digest_differs'
            && count($http->requests) === 3, 'A replaced list must be refused before the archive is requested.');
    } finally {
        Kit::remove($root);
    }
})();
