<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * The release keys a site or a test stand adds itself, from `LARENA_UPDATE_TRUSTED_KEYS` in `.env`:
 * `id=base64,id2=base64` (key id => base64 of a 32-byte Ed25519 public key). Read strictly: a pair without `=`, with
 * an id {@see ReleaseSignatureVerifier} would not take, with a value that is not strict base64 of exactly 32 bytes, or
 * with an id already given, is left out. Nothing is logged and no value is ever repeated: a malformed pair is simply
 * not trusted. Revoked key ids (`revoked_key_ids`) still win over keys from here.
 */
final class TrustedKeyList
{
    private const MAX_LENGTH = 65536;

    /** @return array<string,string> key id => base64 public key */
    public static function parse(mixed $value): array
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return [];
        }
        $keys = [];
        $seen = [];
        foreach (explode(',', $value) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $id = trim($parts[0]);
            $encoded = trim($parts[1]);
            if (isset($seen[$id])) {
                // One id names one key: an id given twice is not trusted at all.
                unset($keys[$id]);
                continue;
            }
            $seen[$id] = true;
            if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $id) !== 1 || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $encoded) !== 1) {
                continue;
            }
            $raw = base64_decode($encoded, true);
            if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            $keys[$id] = $encoded;
        }

        return $keys;
    }
}
