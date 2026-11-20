<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Tests\Support;

use Larena\UpdateClient\Integrity\CoreFileGuard;
use Larena\UpdateClient\Integrity\FindingStore;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\IntegrityRestore;
use Larena\UpdateClient\Integrity\IntegrityScanner;
use Larena\UpdateClient\Integrity\RecordingIntegrityHistory;
use Larena\UpdateClient\Integrity\SignedBaseline;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;

require_once __DIR__ . '/UpdateClientFakes.php';

/**
 * A small installed site in the release layout, made for one test: a signed release with its file list, unpacked by
 * the update client (so the release keeps `.larena-signed/` with the archive), shared storage with uploads, caches and
 * compiled views. The key pair is made for the test and never leaves it.
 */
final class IntegritySiteKit
{
    public const FILES = [
        'app/Version.php' => "<?php return '1.3.0';\n",
        'artisan' => "<?php // artisan\n",
        'bootstrap/app.php' => "<?php return 'app';\n",
        'config/app.php' => "<?php return ['name' => 'Larena'];\n",
        'public/.htaccess' => "RewriteEngine On\nRewriteRule ^ index.php [L]\n",
        'public/index.php' => "<?php echo 'site';\n",
        'public/robots.txt' => "User-agent: *\n",
        'vendor/autoload.php' => "<?php // autoload\n",
    ];
    public const CORE = ['bootstrap/app.php', 'config/app.php', 'public/.htaccess', 'public/index.php', 'vendor/autoload.php'];

    public string $site;
    public string $release;
    public string $storage;
    public string $publicKey;
    public RecordingIntegrityHistory $history;
    public IntegrityLayout $layout;
    public FindingStore $findings;
    public CoreFileGuard $guard;
    public IntegrityScanner $scanner;
    public IntegrityRestore $restore;
    /** @var array{0:string,1:string,2:string,3:string} release.json, sig, files.json, archive */
    public array $signed;

    public static function make(string $name = 'integrity', bool $withList = true): self
    {
        $kit = new self();
        $pair = sodium_crypto_sign_keypair();
        $kit->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $kit->site = UpdateClientTestKit::temp($name);
        $archive = self::tar(self::FILES);
        $entries = [];
        $dirs = [];
        foreach (self::FILES as $path => $data) {
            $entries[] = ['path' => $path, 'type' => 'file', 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
            $parts = explode('/', $path);
            array_pop($parts);
            $prefix = '';
            foreach ($parts as $part) {
                $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                $dirs[$prefix] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            $entries[] = ['path' => $dir, 'type' => 'dir', 'bytes' => 0];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $list = (string) json_encode(['schema' => 'larena.release-files', 'schema_version' => 1, 'product' => 'larena', 'version' => '1.3.0',
            'archive_sha256' => hash('sha256', $archive), 'entries' => $entries, 'core_files' => self::CORE], JSON_UNESCAPED_SLASHES);
        $description = ['schema' => 'larena.release', 'schema_version' => 1, 'product' => 'larena', 'version' => '1.3.0', 'channel' => 'stable',
            'minimum_version' => '1.0.0', 'php' => '^8.3',
            'archive' => ['file' => 'larena-1.3.0.tar.gz', 'sha256' => hash('sha256', $archive), 'bytes' => strlen($archive)],
            'files' => ['file' => 'files.json', 'sha256' => hash('sha256', $list), 'bytes' => strlen($list)],
            'packages' => ['larena/core' => str_repeat('a', 40)], 'backward_incompatible_migrations' => false, 'notes' => []];
        $bytes = (string) json_encode($description, JSON_UNESCAPED_SLASHES);
        $signature = (string) json_encode(['key_id' => 'release.test', 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($pair)))]);
        $kit->signed = [$bytes, $signature, $list, $archive];

        $download = $kit->site . '/download';
        mkdir($download);
        file_put_contents($download . '/larena-1.3.0.tar.gz', $archive);
        file_put_contents($download . '/files.json', $list);
        $verifier = new ReleaseSignatureVerifier(['release.test' => $kit->publicKey]);
        $release = $verifier->verify($bytes, $signature, '1.2.0');
        $kit->release = (new ReleaseArchiveExtractor())->extract($release, $download . '/larena-1.3.0.tar.gz', $kit->site, null,
            $withList ? $bytes : null, $withList ? $signature : null);
        UpdateClientTestKit::remove($download);
        if (!$withList) {
            UpdateClientTestKit::remove($kit->release . '/' . ReleaseArchiveExtractor::SIGNED);
        }

        $kit->storage = $kit->site . '/storage';
        foreach (['app/public', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $folder) {
            mkdir($kit->storage . '/' . $folder, 0755, true);
        }
        file_put_contents($kit->storage . '/app/public/photo.jpg', 'jpeg');
        file_put_contents($kit->storage . '/framework/views/' . sha1('view') . '.php', "<?php echo 'compiled';\n");
        symlink('../../storage', $kit->release . '/storage');
        symlink('../storage/app/public', $kit->release . '/public/storage');
        $kit->wire();

        return $kit;
    }

    public function wire(): void
    {
        $this->history = new RecordingIntegrityHistory();
        $this->layout = new IntegrityLayout($this->release, $this->storage . '/app/larena/integrity', IntegrityLayout::storageRoots($this->storage));
        $layout = $this->layout;
        $key = $this->publicKey;
        $baseline = static fn (): SignedBaseline => SignedBaseline::load($layout, ['release.test' => $key]);
        $this->findings = new FindingStore($this->layout);
        $this->guard = new CoreFileGuard($this->layout, $baseline);
        $this->scanner = new IntegrityScanner($this->layout, $baseline, $this->findings);
        $this->restore = new IntegrityRestore($this->layout, $baseline, $this->findings, $this->scanner, $this->guard, $this->history);
    }

    /** A full check from the start to its end; returns the completed run. @return array<string,mixed> */
    public function scan(int $portion = 3): array
    {
        $this->scanner->start('test', true);
        $state = [];
        for ($i = 0; $i < 1000; $i++) {
            $state = $this->scanner->step($portion, 5.0);
            if (($state['current']['state'] ?? null) !== 'running') {
                break;
            }
        }

        return (array) $state['current'];
    }

    /** @param array<string,string> $files */
    public static function tar(array $files): string
    {
        $tar = '';
        $dirs = [];
        foreach (array_keys($files) as $path) {
            $parts = explode('/', $path);
            array_pop($parts);
            $prefix = '';
            foreach ($parts as $part) {
                $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                $dirs[$prefix] = true;
            }
        }
        $items = [];
        foreach (array_keys($dirs) as $dir) {
            $items[] = [$dir . '/', '5', ''];
        }
        foreach ($files as $path => $data) {
            $items[] = [$path, '0', $data];
        }
        foreach ($items as [$name, $type, $data]) {
            $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
                . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 1763337600) . "\0" . '        ' . $type . str_repeat("\0", 100) . "ustar\0" . '00';
            $header = str_pad($header, 512, "\0");
            $header = substr_replace($header, sprintf('%06o', array_sum(array_map('ord', str_split($header)))) . "\0 ", 148, 8);
            $tar .= $header . ($data === '' ? '' : str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0"));
        }

        return (string) gzencode($tar . str_repeat("\0", 1024));
    }

    public function cleanup(): void
    {
        UpdateClientTestKit::remove($this->site);
    }
}
