<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * Freshness of the product release description a source names as its newest (`<source>/latest/download/release.json`),
 * against a frozen or replayed answer (research §9 «защита от заморозки», matrix 3). The one place of the rule.
 *
 * Inside the signed bytes of `release.json`:
 * - `list_version` — a whole number ≥ 1 that only grows: the update server signs the description again with a higher
 *   number whenever it re-issues it;
 * - `expires_at` — ISO 8601 with offset (`2026-12-01T00:00:00+00:00`): after it the description is no longer an answer.
 *
 * Which source must carry them ({@see required()}):
 * - a GitHub releases page (`github.com`) may omit both: a release asset there is signed once when it is published and
 *   cannot be signed again every day; its transport is GitHub's own;
 * - a folder on this server (`file:///…`, an offline site or Larena Panel putting a release next to the site) may omit
 *   both: nobody on the network can freeze it, and an offline site must not lock itself out when a date passes;
 * - every other source — the update server and its mirrors — must carry both, else `update_release_list_invalid`.
 * Where the fields are present they are always checked, whatever the source.
 *
 * Refusals (before anything is written; the site keeps the highest number it saw in {@see ReleaseListState}):
 * - `update_release_list_stale` — the description expired, or its number is lower than one this site already saw;
 * - `update_release_list_invalid` — a field is missing where required, given alone, or malformed.
 */
final class ReleaseListFreshness
{
    /** Whether a source must carry `list_version` and `expires_at`: every source except github.com and a folder. */
    public static function required(string $source): bool
    {
        return !ReleaseSource::isGitHub($source) && !str_starts_with($source, 'file://');
    }

    /**
     * @param string $releaseBytes the description's exact bytes, whose signature already holds
     * @param int    $seen         the highest list version this site accepted for the product and channel (0: none)
     * @return int|null the description's list version; null when an optional source gave none
     */
    public static function check(string $releaseBytes, bool $required, DateTimeImmutable $now, int $seen): ?int
    {
        try {
            $data = json_decode($releaseBytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_release_list_invalid');
        }
        if (!is_array($data)) {
            throw new RuntimeException('update_release_list_invalid');
        }
        $hasVersion = array_key_exists('list_version', $data);
        $hasExpiry = array_key_exists('expires_at', $data);
        if (!$hasVersion && !$hasExpiry && !$required) {
            return null;
        }
        $version = $data['list_version'] ?? null;
        $expires = $data['expires_at'] ?? null;
        if (!is_int($version) || $version < 1 || !is_string($expires)) {
            throw new RuntimeException('update_release_list_invalid');
        }
        $expiresAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $expires);
        if ($expiresAt === false || $expiresAt->format(DATE_ATOM) !== $expires) {
            throw new RuntimeException('update_release_list_invalid');
        }
        if ($expiresAt->setTimezone(new DateTimeZone('UTC')) <= $now) {
            throw new RuntimeException('update_release_list_stale');
        }
        if ($version < $seen) {
            throw new RuntimeException('update_release_list_stale');
        }

        return $version;
    }
}
