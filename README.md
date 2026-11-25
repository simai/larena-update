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
the internal activation address `POST /_larena/update/opcache-reset` and the
commands:

- `larena:update:check` — ask the sources for a newer signed release;
- `larena:update:apply <version> --actor=<subject_ref>` — download, verify,
  copy the site through `larena/backup`, unpack, migrate, switch `current`,
  activate, check health; rolls back automatically on failure;
- `larena:update:packages` — show the package updates this site may take as one
  batch; with `--apply --actor=<subject_ref>` install them in one cycle (see
  «Package batches» below; `--only=vendor/name` narrows it);
- `larena:update:rollback --actor=<subject_ref>` — back to the previous release
  (database from the copy unless `--keep-database`);
- `larena:update:status` — installed release, releases on disk, recent cycles.

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

A new site installs the signed set release; after that each package publishes
its own releases (owner variant D, 2026-11-25). The update source serves one
signed list for the product and channel, `<source>/packages/packages.json` and
`packages.json.sig` (schema `larena.package-list`: `list_version` that only
grows, `issued_at`, `expires_at`, and every package release as its own signed
`larena.package-release`: version, `released_at`, `open` or `closed`, archive
file, sha256 and size, and `requires.larena` / `requires.packages` from the
package's `module.yaml` `delivery` block). Archives are at
`<source>/packages/download/<vendor>/<name>/v<version>/<file>`.

The site chooses the batch itself: for each installed package the newest newer
release that its licence covers (closed packages ask
`SiteLicensing::allowsUpdate()`; later releases are held with
`update_batch_licence_ended`) and that fits the core version and the other
packages after the batch. Then one cycle runs, the same steps as a release: one
copy, one new folder `releases/<version>-p<list>-<hash>` (the running release
with the batch's `vendor/<vendor>/<name>` folders replaced), one switch, the
health check, and on any failure the same rollback (code first, then the
database). Before the copy the site checks everything again and refuses with
`update_batch_incompatible` (naming the package and the rule),
`update_batch_licence_ended`, `update_batch_signature_invalid` (a list, release or
archive that does not match its signature) or `update_batch_list_stale` (expired,
or older than a list this site already saw, kept in
`storage/app/larena/update/package-list.json`). A package archive must keep the
installed `autoload` and providers, need no package the release does not have,
and, with the distribution's authoritative class map, bring no new class file;
otherwise it needs a set release. The batch release keeps what it installed in
`.larena-packages.json` and does not carry the set's `.larena-signed/` file list
(it no longer describes the files), so its file check reports «no list» until
the integrity checks learn package lists.

Canonical specifications are in `simai/larena-specs`.
