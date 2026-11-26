<?php

declare(strict_types=1);

// Larena Update (plan docs/architecture/2026-11-17-update-client-plan.md in larena-specs).
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

    // The product this site runs: a release is taken only when its signed `release.json` names the same product
    // (`larena` for a Larena site, `larena-panel` for Larena Panel).
    'product' => env('LARENA_UPDATE_PRODUCT', 'larena'),

    /*
     * The site key from the update server, sent as `Authorization: Bearer <key>` with every request to an update
     * source (not to github.com, whose public releases need no key). It stays in `.env`: never in an address, a log,
     * an error or a page. Empty: requests go without it.
     */
    'site_key' => env('LARENA_UPDATE_SITE_KEY'),

    /*
     * Public Ed25519 keys that may sign `release.json`: key id => base64 of the 32-byte public key. Public keys only;
     * the private key never enters a repository. The keys the distribution ships come from the site's
     * `config/larena-release-keys.php` (same format) and are added to these. No key at all means every release is
     * refused.
     *
     * A site or a test stand adds its own keys in `.env`, without editing any file:
     *     LARENA_UPDATE_TRUSTED_KEYS=id=base64,id2=base64
     * Read strictly ({@see \Larena\UpdateClient\Release\TrustedKeyList}): a malformed pair, or a value that is not
     * base64 of exactly 32 bytes, is left out; values are never logged. `revoked_key_ids` still win.
     */
    'trusted_keys' => \Larena\UpdateClient\Release\TrustedKeyList::parse(env('LARENA_UPDATE_TRUSTED_KEYS')),

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

    // The site's own check of its files (owner decision 2026-11-20: any hosting, bots and user mistakes, everything a
    // user can break comes back): the main files on every request, the full check in portions, «Вернуть как было».
    'integrity' => [
        // A2: the main files of the signed list on every request (global middleware). The site always keeps working.
        'every_request' => (bool) env('LARENA_INTEGRITY_EVERY_REQUEST', true),
        // A3: when the full check starts (cron expression, the site's `schedule:run`); it runs through the Larena queue
        // in portions where the queue is installed, else in portions of at most `scheduled_seconds` per tick.
        'schedule' => env('LARENA_INTEGRITY_SCHEDULE', '17 * * * *'),
        'scheduled_seconds' => 50,
        // One portion: at most this many files and folders, or this many seconds.
        'portion_files' => 2000,
        'portion_seconds' => 5,
        // The checks' own state, findings and quarantine; empty: `storage/app/larena/integrity` (outside the web root).
        'state_directory' => env('LARENA_INTEGRITY_STATE_DIRECTORY'),
        // The site runs from its source code (a developer checkout): its files are not checked, said as information.
        // Only this sign or APP_ENV=local turns that on; a site with a signed `.larena-signed/` folder is always checked.
        'source_install' => env('LARENA_SOURCE_INSTALL', false),
        // The admin pages (`/admin/integrity`): the alert bar links here.
        'admin' => [
            'enabled' => true,
            'prefix' => 'admin/integrity',
            'middleware' => ['web', 'larena-auth.entry', 'larena-auth.admin-required', 'larena-admin.locale'],
        ],
    ],
    // «Лицензия и обновления» → «Обновления» in the admin (with larena/admin): the running version, the last check (kept in
    // storage/app/larena/update/last-check.json), «Установить» through the queue job update.release.install, the journal.
    'updates' => [
        'admin' => [
            'enabled' => true,
            'prefix' => 'admin/updates',
            'middleware' => ['web', 'larena-auth.entry', 'larena-auth.admin-required', 'larena-admin.locale'],
        ],
    ],
];
