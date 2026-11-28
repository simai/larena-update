<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Tests\Support;

use Closure;
use Larena\UpdateClient\Laravel\ArtisanRunner;
use Larena\UpdateClient\Source\UpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Source\UpdateHttpResponse;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Test HTTP client: never opens a connection. Each request is answered by the handler, which returns a response, a
 * status and body pair, or throws (a network failure). A body for a request with a file target is written to that
 * file on a 2xx status, as the real client does; on another status its first 4 KiB come back as the body.
 */
final class FakeUpdateHttpClient implements UpdateHttpClient
{
    /** @var list<UpdateHttpRequest> */
    public array $requests = [];

    /** @param Closure(UpdateHttpRequest): (UpdateHttpResponse|array{0:int,1:string}) $handler */
    public function __construct(private Closure $handler)
    {
    }

    /** @param array<string, array{0:int,1:string}> $files url => [status, body]; anything else is a 404 */
    public static function serving(array $files): self
    {
        return new self(static fn (UpdateHttpRequest $request): array => $files[$request->url] ?? [404, 'not found']);
    }

    public function send(UpdateHttpRequest $request): UpdateHttpResponse
    {
        $this->requests[] = $request;
        $answer = ($this->handler)($request);
        $response = $answer instanceof UpdateHttpResponse ? $answer : new UpdateHttpResponse($answer[0], $answer[1], strlen($answer[1]));
        if (strlen($response->body) > $request->maxBytes) {
            throw new RuntimeException('update_http_too_large');
        }
        if ($request->sinkPath !== null) {
            if ($response->successful()) {
                file_put_contents($request->sinkPath, $response->body);

                return new UpdateHttpResponse($response->status, '', strlen($response->body));
            }

            // As the real client: a refused download returns the first 4 KiB of the answer, no file.
            return new UpdateHttpResponse($response->status, substr($response->body, 0, 4096), strlen($response->body));
        }

        return $response;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (UpdateHttpRequest $request): string => $request->method . ' ' . $request->url, $this->requests);
    }
}

/** Test artisan runner: records every call and answers through the handler (output text, or a throw). */
final class FakeArtisanRunner implements ArtisanRunner
{
    /** @var list<array{release:string, arguments:list<string>}> */
    public array $calls = [];

    /** @param Closure(string, list<string>): string $handler */
    public function __construct(private Closure $handler)
    {
    }

    public function run(string $releaseDirectory, array $arguments, string $failureReason, ?int $timeoutSeconds = null): string
    {
        $this->calls[] = ['release' => $releaseDirectory, 'arguments' => $arguments];
        try {
            return ($this->handler)($releaseDirectory, $arguments);
        } catch (RuntimeException $exception) {
            throw new RuntimeException($exception->getMessage() === '' ? $failureReason : $exception->getMessage());
        }
    }

    /** @return list<string> `basename(release) command` of every call */
    public function journal(): array
    {
        return array_map(static fn (array $call): string => basename($call['release']) . ' ' . $call['arguments'][0], $this->calls);
    }
}

/** The state a fake artisan runner keeps for a pretend site: schema version, maintenance flag, copies. */
final class PretendSite
{
    public string $schema = '1.2.0';
    public bool $down = false;
    public bool $failHealth = false;
    public bool $unreachable = false;

    /** @var array<string,string> copy id => schema version */
    public array $copies = [];
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level:mixed, message:string, context:array<array-key,mixed>}> */
    public array $records = [];

    /** @param array<array-key,mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class UpdateClientTestKit
{
    /** Freshness fields of a release description the update server signs ({@see \Larena\UpdateClient\Source\ReleaseListFreshness}). */
    public const FRESH = ['list_version' => 1, 'expires_at' => '2099-01-01T00:00:00+00:00'];

    /** A gzip tar with `artisan` and `public/index.php`, the shape a release archive has. */
    public static function tar(string $version): string
    {
        $tar = '';
        foreach ([['public/', '5', ''], ['artisan', '0', "<?php // $version\n"], ['public/index.php', '0', "<?php echo '$version';\n"]] as [$name, $type, $data]) {
            $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
                . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 1763337600) . "\0" . '        ' . $type . str_repeat("\0", 100) . "ustar\0" . '00';
            $header = str_pad($header, 512, "\0");
            $header = substr_replace($header, sprintf('%06o', array_sum(array_map('ord', str_split($header)))) . "\0 ", 148, 8);
            $tar .= $header . ($data === '' ? '' : str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0"));
        }

        return (string) gzencode($tar . str_repeat("\0", 1024));
    }

    /**
     * A signed release: [release.json bytes, release.json.sig bytes, archive bytes]. The key is a throwaway pair made
     * by the test; no real release key is ever in a repository. `$list`: the freshness fields (null: none, as GitHub).
     *
     * @param array<string,mixed>|null $list
     * @return array{0:string,1:string,2:string}
     */
    public static function release(string $version, string $secretKey, string $keyId = 'release.test', ?string $archive = null, string $minimum = '1.0.0', string $product = 'larena', ?array $list = self::FRESH): array
    {
        $archive ??= self::tar($version);
        $bytes = (string) json_encode([
            'schema' => 'larena.release', 'schema_version' => 1, 'product' => $product, 'version' => $version, 'channel' => 'stable',
            'minimum_version' => $minimum, 'php' => '^8.3',
            'archive' => ['file' => 'larena-' . $version . '.tar.gz', 'sha256' => hash('sha256', $archive), 'bytes' => strlen($archive)],
            'packages' => ['larena/core' => str_repeat('a', 40)], 'backward_incompatible_migrations' => false,
            'notes' => ['en' => 'Release ' . $version],
        ] + ($list ?? []), JSON_UNESCAPED_SLASHES);
        $signature = (string) json_encode(['key_id' => $keyId, 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secretKey))]);

        return [$bytes, $signature, $archive];
    }

    public static function temp(string $name): string
    {
        $path = sys_get_temp_dir() . '/larena-update-' . $name . '-' . bin2hex(random_bytes(5));
        mkdir($path, 0755, true);

        return $path;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0644);
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0755);
        foreach ((array) scandir($path) as $child) {
            if (is_string($child) && $child !== '.' && $child !== '..') {
                self::remove($path . '/' . $child);
            }
        }
        rmdir($path);
    }

    /** @param callable(): mixed $call */
    public static function reason(callable $call): string
    {
        try {
            $call();
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return 'accepted';
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
