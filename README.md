# Larena Update Client

Installed-site trust and safety boundary for consuming signed update data,
calculating plans, verifying artifacts, coordinating preflight and rollback, and
running ordered restart-aware update steps.

## Canonical ownership

`larena/update-client` owns the local update lifecycle. `larena/update` remains
the server-side catalog, signed-manifest and artifact-delivery authority.
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
- `larena:update:rollback --actor=<subject_ref>` — back to the previous release
  (database from the copy unless `--keep-database`);
- `larena:update:status` — installed release, releases on disk, recent cycles.

Every command prints readable lines or `--json`. The site must use the release
layout (`<site>/releases/<release>`, `<site>/current`). The version a release
runs is the `version` in its `.larena-release` marker (written when a release
is unpacked; the installer must write it for the release it installs), or its
folder name when that is a version. No release key ships with the package: the
distribution lists its public keys in the site's `config/larena-release-keys.php`
(`key id => base64 public key`); `update-client.trusted_keys` may add more.
Sources (`LARENA_UPDATE_SOURCES`, in order) are HTTPS release pages or local
folders `file:///path` with the layout the release step writes
(`download/v<version>/release.json`, `release.json.sig`, `files.json`, the archive).
`files.json` (schema `larena.release-files`) lists every path of the release
with its type, size and sha256, plus `core_files` (entry point, loader, config,
`.htaccess`); `release.json` names its size and sha256, so the one signature
covers it. Before unpacking, the archive must match the list exactly, and every
unpacked file must match its sha256. The checked list and the signed
description stay in `<site>/releases/<release>/.larena-signed/` for the
integrity checks. A release built before file lists has no `files` and is
reported as having no list. The same `FileList` format describes one package.
A site run from its source code is not a release: when the running base path has
no `.larena-signed/` folder and is a git checkout (`.git`) or does not lie in a
release layout (`<site>/releases/<release>`), the checks answer
«Сайт установлен из исходного кода — проверка файлов не ведётся», an information
that a person hides once, not a warning. A release-layout install without a list
stays the warning «списка файлов нет». «Вернуть как было» shows the same states
at the same level as the check page.
A database restore during a rollback keeps the update history: the cycles are
carried over the restore. Nothing is installed without an operator running
`larena:update:apply`.

Canonical specifications are in `simai/larena-specs`.
