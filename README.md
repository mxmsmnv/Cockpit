# Cockpit

Cockpit is a short-link control center for ProcessWire. It creates memorable redirect paths, protects routes owned by the site and other modules, tracks privacy-minimal aggregate clicks, and keeps link management, analytics, QR output, and audit history in one admin workspace.

![Cockpit](assets/Cockpit.png)

It is made for campaigns, social profiles, printed material, email, QR codes, and any destination that should be changeable without changing the public short URL.

**Author:** Maxim Semenov<br>
**Website:** [smnv.org](https://smnv.org)<br>
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Cockpit Does

- Creates custom single-level or nested paths such as `/instagram`, `/r/summer`, or `/print/catalog`.
- Generates a path under a configurable prefix when Path is left empty.
- Supports `301`, `302`, `307`, and `308` redirects with plain-language status guidance.
- Keeps ProcessWire pages, URL segments, PagePathHistory, admin routes, API namespaces, Ichiban, ProcessRedirects, and configured project routes ahead of Cockpit.
- Rejects ambiguous paths, unsafe destinations, private networks, unknown route ownership, duplicate routes, and redirect cycles.
- Restricts destinations with allowlists, denylists, or an explicit high-trust public-host mode.
- Tracks lifetime hits, last click, and aggregate daily statistics without per-visitor records.
- Filters known bots, link previews, HEAD requests, and configured QA addresses.
- Builds GA-compatible UTM destinations locally in the admin browser.
- Exports and imports bounded JSON or formula-safe CSV link configurations.
- Provides trusted CLI commands for CRUD, statistics, route inspection, diagnostics, migration, transfer, and retention.

Cockpit does not store visitor IP addresses, cookies, User-Agent strings, referrers, or one row per request.

## Admin Area

Cockpit adds a permission-aware ProcessWire admin workspace with:

- **Dashboard** — link health, rolling traffic, lifetime distribution, top links, and Quick create;
- **Links** — search, filters, compact actions, and one canonical management page per link;
- **Analytics** — quick periods, exact date ranges, link/state/status filters, automatic or explicit grouping, and previous-period comparison;
- **Audit** — privacy-minimal create, update, enable, disable, and delete history;
- **Settings** — link defaults, route policy, destination policy, analytics, integrations, retention, and uninstall behavior.

The interface follows ProcessWire AdminThemeUikit and the [`pw-design-system`](https://github.com/mxmsmnv/pw-design-system) workspace conventions.

## Link Workspace

Each saved link has one management page containing:

- the canonical short URL and current destination;
- redirect code and enabled state;
- lifetime and rolling traffic;
- line-chart timeline and exact period detail;
- a native ProcessWire editor;
- Campaign URL Builder;
- optional QR preview and SVG download;
- link-scoped audit history;
- permission-aware deletion.

Use `302` for ordinary short links, campaigns, social links, print, and QR destinations that may change. Use `301` only when the destination is final. `307` and `308` are specialist method-preserving choices for forms and APIs.

## Optional FieldtypeQRCode Integration

Cockpit contains no QR engine. When a tested FieldtypeQRCode 1.1.4–2.x release is installed, Cockpit uses its documented public generator to create a verified QR image for the canonical short URL.

The stable Cockpit URL—not the mutable destination—is encoded, so a target can change without reprinting the QR. Cockpit validates payloads and generated SVG/GIF output and never loads the provider during a public redirect.

FieldtypeQRCode is maintained by EPRC/Romain Cazier under the MIT license. Cockpit does not install or bundle it.

## Installation

Requirements:

- ProcessWire 3.0.200+
- PHP 7.4+
- MySQL 5.7+, MariaDB 10.3+, SQLite, or PostgreSQL through ProcessWire's database layer

Install:

1. Copy the `Cockpit` folder into `/site/modules/`.
2. In ProcessWire Admin, refresh modules.
3. Install `Cockpit`; ProcessWire installs `ProcessCockpit` with it.
4. Open Cockpit settings and configure an allowed destination host or explicitly enable public-host mode.
5. Choose a dedicated generated-path prefix and reserve project-specific routes.
6. Exclude the Cockpit namespace from static caches, reverse proxies, and CDNs.
7. Open the Cockpit admin section and create the first link.

Cockpit preserves its tables during uninstall by default. Enable destructive uninstall only when permanent data removal is intentional and backed up.

## Permissions

`cockpit-manage` is the backward-compatible all-access permission. Granular permissions are available for statistics, link management, deletion, import/export, settings, and audit access. Deletion requires both link-management and delete permissions.

The public PHP service methods do not perform role or CSRF checks. Any HTTP-facing caller must enforce authorization, CSRF, ownership, and output escaping at its own boundary.

## CLI

Run commands from the ProcessWire site root:

```bash
php index.php --cockpit-help
php index.php --cockpit-list --cockpit-format=json
php index.php --cockpit-resolve --cockpit-path=r/example --cockpit-format=json
php index.php --cockpit-diagnose --cockpit-format=json
```

Mutation, transfer, migration, and deletion commands are documented in [DOCUMENTATION.md](DOCUMENTATION.md). Destructive CLI actions require explicit flags.

## Documentation

- [Complete documentation](DOCUMENTATION.md) — installation, configuration, operations, integrations, testing, and troubleshooting
- [Public API](API.md) — supported PHP methods, inputs, outputs, errors, and trust boundaries
- [Known-good examples](EXAMPLES.md) — safe ProcessWire integration patterns
- [Agent guide](AGENTS.md) — Olivia/AI behavior, Blueprint guidance, approvals, validation, and rollback
- [Changelog](CHANGELOG.md) — first public release contents
- [Security policy](SECURITY.md)
- [Production checklist](docs/PRODUCTION-CHECKLIST.md)
- [Privacy statement](docs/PRIVACY.md)
- [Threat model](docs/THREAT-MODEL.md)

## Development

Run portable checks:

```bash
php tests/run.php
```

Run the self-cleaning integration and HTTP suite against an authorized disposable ProcessWire installation:

```bash
php tests/run.php \
  --site-root=/path/to/processwire \
  --base-url=https://processwire.test \
  --require-fixture
```

## Author

Maxim Semenov<br>
[smnv.org](https://smnv.org)<br>
[maxim@smnv.org](mailto:maxim@smnv.org)

## License

MIT. See [LICENSE](LICENSE).
