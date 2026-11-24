<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use RuntimeException;

/**
 * {@see UpdateHttpClient} over ext-curl. A request that must stay on HTTPS stays on it through every redirect; the
 * body is cut off at the request's byte limit; a download goes to `<file>.part` and is renamed to `<file>` only
 * after the whole body arrived with a 2xx status, so a half-written file never looks like a finished one. A download
 * answered with another status returns at most the first 4 KiB of that answer as its body and keeps no file.
 */
final readonly class CurlUpdateHttpClient implements UpdateHttpClient
{
    private const ERROR_BODY_BYTES = 4096;

    public function __construct(private string $userAgent = 'larena-update-client')
    {
    }

    public function send(UpdateHttpRequest $request): UpdateHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('update_http_unavailable');
        }
        $headers = ['User-Agent: ' . $this->userAgent];
        foreach ($request->headers as $name => $value) {
            if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException('update_http_header_invalid');
            }
            $headers[] = $name . ': ' . $value;
        }

        $sink = null;
        $target = $request->sinkPath;
        $partial = $target === null ? null : $target . '.part';
        // A file target is written to `<file>.part` first.
        if ($target !== null) {
            if (is_link($partial) || is_link($target)) {
                throw new RuntimeException('update_download_path_invalid');
            }
            $sink = fopen($partial, 'wb');
            if ($sink === false) {
                throw new RuntimeException('update_download_path_invalid');
            }
        }

        $body = '';
        $bytes = 0;
        $tooLarge = false;
        $handle = curl_init($request->url);
        $protocols = $request->httpsOnly ? CURLPROTO_HTTPS : (CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => $request->followRedirects,
            CURLOPT_MAXREDIRS => 5,
            // An `Authorization` header (the site key) never follows a redirect to another host.
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_PROTOCOLS => $protocols,
            CURLOPT_REDIR_PROTOCOLS => $protocols,
            CURLOPT_SSL_VERIFYPEER => $request->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $request->verifyTls ? 2 : 0,
            CURLOPT_MAXFILESIZE => $request->maxBytes,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($request, $sink, &$body, &$bytes, &$tooLarge): int {
                $bytes += strlen($chunk);
                if ($bytes > $request->maxBytes) {
                    $tooLarge = true;

                    return 0;
                }
                if ($sink !== null) {
                    return fwrite($sink, $chunk) === strlen($chunk) ? strlen($chunk) : 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($request->method === 'POST') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, '');
        }

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        if ($sink !== null) {
            fclose($sink);
        }

        if ($tooLarge || $error === CURLE_FILESIZE_EXCEEDED) {
            $this->discard($partial);
            throw new RuntimeException('update_http_too_large');
        }
        if ($ok === false || $error !== 0) {
            $this->discard($partial);
            throw new RuntimeException('update_http_failed');
        }
        if ($target !== null) {
            if ($status < 200 || $status >= 300) {
                // A refusal of a download keeps its first bytes as the body, so the caller can read the server's
                // refusal code (`{"error":"license_required"}`); the file itself is never kept.
                $body = $this->head($partial);
                $this->discard($partial);
            } elseif (!rename($partial, $target)) {
                $this->discard($partial);
                throw new RuntimeException('update_download_path_invalid');
            }
        }

        return new UpdateHttpResponse($status, $body, $bytes);
    }

    private function head(?string $path): string
    {
        if ($path === null || !is_file($path) || is_link($path)) {
            return '';
        }
        $handle = fopen($path, 'rb');
        $head = $handle === false ? false : fread($handle, self::ERROR_BODY_BYTES);
        if ($handle !== false) {
            fclose($handle);
        }

        return is_string($head) ? $head : '';
    }

    private function discard(?string $path): void
    {
        if ($path !== null && is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }
}
