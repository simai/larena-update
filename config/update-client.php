<?php

declare(strict_types=1);

// Larena Update Client (plan docs/architecture/2026-11-17-update-client-plan.md in larena-specs).
return [
    // `<site>` of the release layout (`<site>/releases/<release>`, `<site>/current`, shared `.env` and `storage`).
    // Empty: two levels above the running release.
    'site_root' => env('LARENA_UPDATE_SITE_ROOT'),

    'channel' => env('LARENA_UPDATE_CHANNEL', 'stable'),

    // Update sources, tried in order. Each is an HTTPS releases address serving `<source>/latest/download/<file>`
    // and `<source>/download/<tag>/<file>` (GitHub releases; a mirror serves the same paths). The first is GitHub,
    // the second may be the SIMAI mirror. Comma-separated in LARENA_UPDATE_SOURCES.
    'sources' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'LARENA_UPDATE_SOURCES',
        'https://github.com/simai/larena/releases',
    ))))),

    // Release tag = prefix + version (`v1.3.0`).
    'tag_prefix' => 'v',

    /*
     * Public Ed25519 keys that may sign `release.json`: key id => base64 of the 32-byte public key. Public keys only;
     * the private key never enters a repository. The keys the distribution ships come from the site's
     * `config/larena-release-keys.php` (same format) and are added to these. No key at all means every release is
     * refused.
     *
     * Example (not a real key):
     *     'trusted_keys' => [
     *         'larena-release-2026' => 'MCowBQYDK2VwAyEA...base64 of 32 bytes...=',
     *     ],
     */
    'trusted_keys' => [],

    // Key ids no longer trusted even when listed above.
    'revoked_key_ids' => [],

    'download' => [
        // Download folder; empty: `<site>/storage/app/larena/update/downloads`.
        'directory' => env('LARENA_UPDATE_DOWNLOAD_DIRECTORY'),
        'timeout' => 600,
        'connect_timeout' => 15,
        'max_archive_bytes' => 1073741824,
    ],

    // Address the health check and the activation call use; empty: app.url (APP_URL).
    'site_url' => env('LARENA_UPDATE_SITE_URL'),

    'health' => [
        'timeout' => 10,
        'verify_tls' => (bool) env('LARENA_UPDATE_VERIFY_TLS', true),
    ],

    'process' => [
        // PHP CLI for `php <release>/artisan …`; empty: the PHP running the command.
        'php_binary' => env('LARENA_UPDATE_PHP'),
        'timeout' => 900,
        // How long the site copy (backup + trial restore) may take.
        'backup_wait' => 3600,
    ],

    'queue' => [
        // The Laravel database queue table; without it the queue check is skipped and noted.
        'jobs_table' => 'jobs',
    ],

    'activation' => [
        'path' => '_larena/update/opcache-reset',
        'token_ttl' => 120,
    ],
];
