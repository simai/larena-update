# Larena Update

Installed-site trust and safety boundary for consuming signed update data,
calculating plans, verifying artifacts, coordinating preflight and rollback, and
running ordered restart-aware update steps.

## Canonical ownership

`larena/update` owns the local update lifecycle of an installed site. The
server-side catalog, signed-manifest and artifact-delivery authority lives in
`simai/larena-server-update` (registration in `simai/larena-server-registration`).
This package was named `larena/update-client` until 2026-11-24; its PHP
namespace `Larena\UpdateClient\`, the configuration key `update-client`, the
view and translation namespace `larena-update-client::` and the route names
`larena.update-client.*` keep that name.
Access, Core, Filesystem, Licensing, Backup and Audit retain their own
cross-package contracts; an application Root only composes adapters.

G4.8 adds a deterministic fail-closed reference runtime. It consumes the public
Access query-scope, Core operation, Filesystem logical-file and Licensing
contracts, plus package-owned Backup and Audit ports. The included in-memory
store and recording audit adapter are test/reference implementations, not a
production persistence selection.

The reference cycle proves entitlement and manifest denial, artifact staging
through Filesystem, explicit recovery preflight, ordered execution, confirmed
rollback, retry from a known failed step and idempotent replay.

## Laravel site

`Larena\UpdateClient\Laravel\UpdateClientServiceProvider` (package
discovery) adds the configuration `update-client`, the `update_cycles` table,
the internal activation address `POST /_larena/update/opcache-reset`, the
health address `GET /_larena/update/health` (answers
`{"status":"ok","check":"larena_update_health"}`) and the commands:

- `larena:update:check` — ask the sources for a newer signed release;
- `larena:update:apply <version> --actor=<subject_ref>` — download, verify,
  copy the site through `larena/backup`, unpack, migrate, switch `current`,
  activate, check health; rolls back automatically on failure;
- `larena:update:packages` — show the package updates this site may take as one
  batch; with `--apply --actor=<subject_ref>` install them in one cycle (see
  «Package batches» below; `--only=vendor/name` narrows it);
- `larena:update:rollback --actor=<subject_ref>` — back to the previous release
  (database from the copy unless `--keep-database`);
- `larena:update:recover --actor=<subject_ref> --confirm=<cycle id>` — leave
  `manual_recovery_required` (a rollback that did not finish keeps the site in
  maintenance and refuses new cycles): the previous release, the database from
  the cycle's copy when the cycle had changed it (`--keep-database` only when
  the failed update never reached its migrations), maintenance off; the cycle
  ends `recoverable_failed` (a failed update) or `rolled_back` (a failed manual
  rollback). Without `--confirm` it only says what it would do. Each run reaches
  the update journal as `update.cycle.recovered` or `update.cycle.recovery_failed`
  with `recovered_by`;
- `larena:update:status` — installed release, releases on disk, recent cycles.

Before the first write the preflight checks that the site reaches itself at
`LARENA_UPDATE_SITE_URL` (else `APP_URL`): the health address must give its JSON
answer (`update_self_health_unreachable`) and the activation address without a
token its own 404 (`update_self_activation_unreachable`). After the switch the
health check counts the home page and `/admin/login` as live unless they answer
5xx or a PHP error page, and `/_larena/update/health` only with its JSON marker
(a release from before that address: Laravel's `/up` with 2xx). The activation
call counts only the reset address's own JSON answer. In the automatic rollback
a failed activation of the previous release is the warning
`rollback_activation_failed`; the database restore and leaving maintenance
still run.

Every command prints readable lines or `--json`. The site must use the release
layout (`<site>/releases/<release>`, `<site>/current`). The version a release
runs is the `version` in its `.larena-release` marker (written when a release
is unpacked; the installer must write it for the release it installs), or its
folder name when that is a version. No release key ships with the package: the
distribution lists its public keys in the site's `config/larena-release-keys.php`
(`key id => base64 public key`); `update-client.trusted_keys` may add more, and a
site or a test stand adds its own in `.env` as
`LARENA_UPDATE_TRUSTED_KEYS=id=base64,id2=base64` (a malformed pair or a value
that is not 32 bytes after base64 is ignored; `revoked_key_ids` still win).
Keys change without a new build through a signed key list (`Release\ReleaseKeyList`): `keys.json`
(`{"schema":"larena.release-keys","list_version","expires_at","signed_by","keys":[{"id","public_key","not_before"}],"revoked":[…]}`)
with `keys.json.sig` (the `release.json.sig` format) at `<source>/keys.json` (a folder: `<folder>/keys.json`;
github.com: the newest release's asset), signed by a key the site already trusts — «the old key signs the new one»;
a key never authorises itself. `larena:update:check` and «Проверить сейчас» refresh it first. Accepted lists are kept
in `storage/app/larena/update/release-keys.json` as a chain that is checked again from the shipped keys on every read
(`Release\ReleaseKeyState`; a key written there by hand is never trusted), merged over the shipped keys for updates,
batches and the integrity checks. Revoked ids always win, also over the shipped keys. A lower, replayed or expired
list is ignored with a warning (`update_keys_stale`, `update_keys_expired`: the accepted keys stay); a bad signature
or shape is refused and logged (`update_keys_signature_invalid`, `update_keys_invalid`). `larena:update:status`
shows the list version, the revoked ids and the last refresh.
A refused download names its reason: `update_license_required`,
`update_license_expired`, `update_license_revoked` (the update server's
`license_*` code), `update_site_key_required` (no site key) or
`update_site_key_rejected` (a 401 with a key); other failures stay
`update_download_failed`.
Sources (`LARENA_UPDATE_SOURCES`, in order) are HTTPS release pages or local
folders `file:///path` with the layout the release step writes
(`download/v<version>/release.json`, `release.json.sig`, `files.json`, the archive).
`LARENA_UPDATE_SITE_KEY` (the site key from the update server) is sent as
`Authorization: Bearer <key>` to every HTTPS source except github.com; it never
goes into an address, a log or an error, and without it requests go as before.
`ReleaseSource::entitlement()` asks `GET <source>/entitlement` with the same
header and returns the raw answer (only `schema_version` 1 is checked).
The update server's newest release comes through its signed channel index (`Source\ReleaseIndex`, the one place of
the rule; contract agreed with Larena Server 2026-11-28): `<source>/index.json` + `index.json.sig`
(`larena.release-index`: `product`, `channel`, `list_version` growing with every publish or revocation, `expires_at`
now + 7 days and signed again daily, `signed_by`, `latest` `{version, release_sha256}`, `revoked`
`[{version, fixed_in}]`). Releases stay immutable, signed once. The highest `list_version` seen per product and
channel is kept in `storage/app/larena/update/release-list.json`; an expired or lower index is
`update_release_list_stale`, a missing or malformed one `update_release_list_invalid`, both before any write and even
when it would say «not newer». The release then read (`download/v<version>/release.json`) must hash to
`release_sha256` (`update_release_index_mismatch`). github.com release pages and `file://` folders have no index.
A revoked installed version is taken from the index's `revoked` list: kept in `storage/app/larena/update/revoked.json`
and shown as the danger «Эта версия отозвана — обновите срочно» (with «Исправлено в X») on «Обновления» and in the
admin's alert bar (`update-client:updates:revoked`), in `larena:update:check` and `larena:update:status`, until a
newer version is installed or the index no longer lists it. A download answered 410
`{"error":"release_revoked","version","fixed_in"}` is refused as `update_release_revoked` (`Source\ReleaseRevoked`).
`LARENA_UPDATE_PRODUCT` (default `larena`; `larena-panel` for the panel) is the
`product` a signed `release.json` must name, for updates and integrity checks.
`files.json` (schema `larena.release-files`) lists every path of the release
with its type, size and sha256, plus `core_files` (entry point, loader, config,
`.htaccess`); `release.json` names its size and sha256, so the one signature
covers it. Before unpacking, the archive must match the list exactly, and every
unpacked file must match its sha256. The checked list and the signed
description stay in `<site>/releases/<release>/.larena-signed/` for the
integrity checks. A release built before file lists has no `files` and is
reported as having no list. The same `FileList` format describes one package.
A site run from its source code (a developer checkout) is marked explicitly:
`LARENA_SOURCE_INSTALL=1` in its `.env`. `APP_ENV=local` is no sign (every
installer writes it today). Only then, and
only while it has no `.larena-signed/` folder, the checks answer
«Сайт установлен из исходного кода — проверка файлов не ведётся», an information
that a person hides once, not a warning. Neither a `.git` folder nor the folder
layout turns the checks off: Larena installs on any hosting, and an archive
unpacked into a plain folder (no `releases/`) is checked like a release once
the installer sealed it (`php artisan larena:install-seal --from=<folder with
release.json, release.json.sig, files.json and the archive>`, which works in any
folder). A site without the sign and without a list is the warning «списка
файлов нет». A site that has the signed folder is always checked, whatever the
sign says. «Вернуть как было» shows the same states at the same level as the
check page.
A database restore during a rollback keeps the update history: the cycles are
carried over the restore. Nothing is installed without an operator running
`larena:update:apply`.

## Package batches

A product updates with its own signed release; closed paid addons and partner modules update in batches (owner
variant A, 2026-11-25: an open product package in a batch is refused with `update_batch_open_package`). What a batch
is, its list and releases, the compatibility rule and the refusals are shared rules: see [Shared rules](#shared-rules).

How this package does it: `larena:update:packages` shows the batch, `--apply --actor=<subject_ref>` runs it
(`--only=vendor/name` for an addon the site does not have yet). The list's highest version seen is kept in
`storage/app/larena/update/package-list.json`. One cycle runs the release steps: one copy, one new folder
`releases/<version>-p<list>-<hash>` (the running release with the batch's `vendor/<vendor>/<name>` folders replaced),
one switch, the health check and on failure the same rollback. The batch release keeps `.larena-signed/` of the
product, each installed package archive in `.larena-packages/` and the record `.larena-packages.json`; the file
check lays each package's list over the product's (`Integrity\PackageLayer`). A new addon's classes are written into
Composer's generated files by `Packages\PackageAutoload` (`psr-4` only, no Composer on the site).

## Admin: «Лицензия и обновления» → «Обновления»

`/admin/updates` (with larena/admin): the running version, the last check of the update sources with what a newer
release holds («Проверить сейчас» asks them; the page itself never waits for the network), «Установить» and the
installation journal. Installing goes to the queue as the job `update.release.install`, which runs exactly what
`larena:update:apply` runs (copy, new code, migrations, switch, health check, automatic rollback); without the queue the
page names the command. Seeing needs `update.integrity.read`, installing `update.integrity.apply`.

## Shared rules

Shared knowledge lives only in Larena Specs (owner decision 2026-11-26, variant C). `docs/specs-links.json` names the
Specs sections this package relies on, read at one Specs commit; `composer run specs-links:check` (in the quality gate)
checks that each still exists and has not changed since. The main source is
`docs/architecture/larena-licensing-updates-purchase.md`.

Canonical specifications are in `simai/larena-specs`.
