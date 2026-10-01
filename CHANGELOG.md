# Changelog

## 1.0.3 — 2026-09-30

- Fixed database-driver detection to use PDO's public driver attribute, preventing
  repeated upgrade failures on ProcessWire versions without a `dialect()` helper.
- Contained navigation and data tables inside their workspace at phone widths,
  so wide content scrolls locally instead of widening the admin document.
- Added layout/paint containment to local table scrollers so mobile emulation
  cannot expand the layout viewport to a table's intrinsic width.

## 1.0.2 — 2026-09-26

- Replaced boolean `SUM()` expressions in dashboard link totals with portable
  conditional aggregates for PostgreSQL.

## 1.0.1 — 2026-09-26

- Added portable MySQL, SQLite, and PostgreSQL schema introspection through ProcessWire's public database helpers.
- Created the statistics foreign key inline on fresh installs and added a transactional SQLite rebuild for existing statistics tables that lack it.

## 1.0.0 — 2026-08-12

First release of Cockpit.

- Added custom and generated short-link paths with `301`, `302`, `307`, and `308` redirects.
- Added one canonical management workspace per link with editing, status, traffic, QR, and audit history.
- Added Dashboard, Links, Analytics, and Audit admin views aligned with ProcessWire AdminThemeUikit and `pw-design-system` conventions.
- Added quick-period and exact-range analytics, previous-period comparison, daily/weekly/monthly grouping, link/state/status filters, line charts, donut distribution, and compact period detail.
- Added a browser-local Campaign URL Builder for GA-compatible UTM parameters.
- Added privacy-minimal lifetime and daily aggregate counters with bot, preview, HEAD, and exact-IP exclusion policy plus bounded retention.
- Added canonical fail-closed path parsing, destination allow/deny policy, private-address protection, duplicate/self/cycle checks, and late not-found routing.
- Added deterministic route inspection for ProcessWire pages, URL segments, PagePathHistory, Ichiban, ProcessRedirects, common hook namespaces, and configured reserved routes.
- Added redirect no-cache headers and optional CloudCache purge integration.
- Added granular admin permissions, CSRF-protected mutations, and a privacy-minimal administrative audit log.
- Added bounded JSON/CSV link export and transactional dry-run/apply import with explicit replacement and formula-injection protection.
- Added explicit transactional migration from preserved ShortLinks tables without automatic import or deletion.
- Added optional, capability-detected FieldtypeQRCode 1.1.4–2.x integration with validated SVG/GIF output and maintainer attribution.
- Added CLI management, statistics, route resolution, schema/configuration diagnostics, transfer, migration, and pruning commands.
- Added schema versioning, idempotent install/upgrade/repair, table-prefix support, referential integrity, and preserve-by-default uninstall behavior.
- Added portable PHP 7.4–8.5 CI, JavaScript checks, self-cleaning ProcessWire/HTTP fixtures, deterministic release archives, manifests, SHA-256 checksums, CycloneDX SBOMs, and static security checks.
- Added complete user, API, Olivia/agent, security, privacy, production, upgrade, integration, and troubleshooting documentation.
- Fixed Path input validation for browsers that compile HTML patterns in Unicode Sets (`v`) mode.
