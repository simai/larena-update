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
layout (`<site>/releases/<release>`, `<site>/current`). No release key ships
with the package: list the public keys in `update-client.trusted_keys`.
Nothing is installed without an operator running `larena:update:apply`.

Canonical specifications are in `simai/larena-specs`.
