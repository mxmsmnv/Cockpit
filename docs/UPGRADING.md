# Upgrading and Downgrading

## Upgrade

Back up the database, record the current module and schema versions, deploy the complete release, refresh ProcessWire modules, and allow only idempotent forward migrations to run. Keep legacy ShortLinks tables until the migration report and an isolated restore test are accepted.

After an upgrade run lint, unit and integration tests, inspect schema diagnostics, compare link/statistics counts, and test real route precedence without following redirects.

## Downgrade limitations

Cockpit does not run automatic down-migrations. Older code may not understand columns or invariants added by a newer schema. To roll back code safely, first disable redirects, preserve the tables, and verify the older version against a restored database copy. Restore a pre-upgrade database backup when compatibility cannot be proven.

Never delete unknown columns, constraints, metadata, audit records, or migration reports merely to make an old version install. Never use uninstall as a downgrade mechanism.

## Deprecation policy

Public APIs documented in `API.md` are changed only with release notes and a migration path. Deprecations should remain functional for at least one minor release where security and correctness permit. Internal/protected methods and raw table layouts are not public compatibility contracts.
