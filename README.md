# Cockpit

**Link control center for ProcessWire CMS.**

![Cockpit — redirects, analytics, and code integrations](assets/images/cockpit-hero.png)

Cockpit manages redirects from any available relative path and tracks aggregate click statistics without requiring ProcessWire templates, pages, or fields.

```text
https://modenza.org/go/124 → https://instagram.com/modenza_cabinets
https://modenza.org/instagram → https://instagram.com/modenza_cabinets
https://modenza.org/social/modenza → https://instagram.com/modenza_cabinets
```

## Requirements

- ProcessWire 3.0.200+
- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.3+

## Installation

1. Copy the `Cockpit/` directory to `site/modules/`.
2. In the ProcessWire admin, run **Modules > Refresh**.
3. Install **Cockpit**.
4. Open **Cockpit** from the main admin navigation.

To create the example above, add a link with:

- Path: `go/124`
- Target URL: `https://instagram.com/modenza_cabinets`
- Redirect: `302 — Temporary`
- Status: `Active`

Leading and trailing slashes are optional when entering a path.

## Main Features

- Create, edit, enable, disable, and delete links from the admin workspace.
- Use any single-level or nested path, including `/go/124`, `/instagram`, and `/campaign/summer`.
- Protect ProcessWire pages, URL segments, PagePathHistory entries, admin routes, `/wire/*`, and `/site/*` from conflicts.
- Reserve routes owned by APIs, checkout flows, webhooks, and other modules with wildcard patterns.
- Generate a path automatically when the path field is left empty.
- Redirect with status codes `301`, `302`, `307`, and `308`.
- Track total clicks and the most recent click timestamp.
- View daily, weekly, and monthly aggregate statistics.
- Filter statistics by an individual link.
- Explore analytics with today/7/30/90-day and 12-month shortcuts, exact date ranges, automatic or explicit day/week/month grouping, link state, and redirect-status filters.
- Compare every selection with its immediately preceding period and scan exact bucket values in a compact horizontal detail strip.
- Use a permission-aware admin workspace with Overview, Links, Analytics, and Audit views.
- Compare traffic with responsive line and donut charts built on the ProcessWire admin theme without an external chart library.
- Configure allowed and blocked destination hosts, including wildcard hosts.
- Filter bots, HEAD requests, and selected IP addresses from statistics.
- Run CRUD, statistics, route inspection, and maintenance operations from the CLI.
- Import preserved links and daily statistics from the legacy ShortLinks tables.
- Work with ProcessWire database table prefixes automatically.

Cockpit does not store visitor IP addresses, cookies, or User-Agent strings in its statistics tables.

## Configuration

Module settings include:

- generated-path prefix and code length;
- optional canonical public base URL for correct CLI-generated HTTPS links;
- default redirect status and enabled state;
- optional trailing slash display;
- query-string forwarding;
- no-store headers for temporary redirects;
- additional reserved paths with `*` wildcard support;
- fail-closed destination policy with allowed/blocked hosts or an explicit opt-in for arbitrary public HTTP(S) hosts;
- statistics, bot filtering, HEAD requests, and excluded IP addresses;
- daily-statistics retention;
- data preservation or deletion during uninstall.

Settings are grouped into native ProcessWire sections for Links, Routing, Analytics, Integrations, and Data & privacy. Current provider status, destination-policy status, and destructive uninstall behavior are shown with contextual notices.

The custom admin workspace follows [`mxmsmnv/pw-design-system`](https://github.com/mxmsmnv/pw-design-system) through the verified Tickets implementation: horizontal pill navigation, restrained page introductions, compact metrics and panels, ProcessWire Inputfields for edit forms, scoped CSS, and current `--pw-*` theme tokens.

### Route Safety

Existing ProcessWire pages, PagePathHistory entries, and pages with URL segments always take priority. Cockpit checks conflicts before saving a link. If a real page later claims the same path, the ProcessWire page takes over automatically.

At request time Cockpit resolves links late in ProcessWire's not-found pipeline. This keeps security modules, API/path hooks, Ichiban redirects, PagePathHistory, and existing 404 redirect modules ahead of Cockpit. On a site with custom routers, also reserve their namespaces explicitly so an administrator cannot save a Cockpit link that will be shadowed by an earlier owner.

Redirect responses use ProcessWire's `Session::redirect()` lifecycle so compatible infrastructure hooks, including CloudCache's redirect no-cache policy when enabled, can run normally.

Add third-party or project routes under **Additional reserved paths**:

```text
api/*
checkout/*
webhooks/*
```

Daily-statistics retention runs through ProcessWire `LazyCron` when available. It can also be triggered manually with `--cockpit-prune`.

Cockpit preserves its database tables during uninstall by default. Enable **Delete links and statistics when uninstalling** only when permanent removal is intended.

### Managed local demo data

For UI and statistics testing on an authorized local or disposable site, preview the managed demo fixture first, then apply it explicitly:

```bash
php tests/seed-demo.php --site-root=/path/to/processwire
php tests/seed-demo.php --site-root=/path/to/processwire --apply
```

The fixture owns only eight exact `cockpit-demo/*` paths, covers every redirect status and both enabled states, and creates 120 days of deterministic daily click totals. Re-running `--apply` is idempotent. Remove only those owned rows and their cascaded statistics with:

```bash
php tests/seed-demo.php --site-root=/path/to/processwire --remove
```

The script refuses to overwrite a matching path whose target is not the exact demo target.

## Database Tables

- `cockpit_links` — link configuration and total counters.
- `cockpit_daily_stats` — aggregate daily counters.

ProcessWire's configured `dbPrefix` is applied automatically.

## CLI

Run commands from the ProcessWire site root containing `index.php`.

Help and listing:

```bash
php index.php --cockpit-help
php index.php --cockpit-list
php index.php --cockpit-list --cockpit-format=json
```

Create a link with an explicit or generated path:

```bash
php index.php --cockpit-create \
  --cockpit-path=instagram \
  --cockpit-target='https://instagram.com/modenza_cabinets' \
  --cockpit-status=302

php index.php --cockpit-create \
  --cockpit-target='https://instagram.com/modenza_cabinets'
```

Update or toggle a link:

```bash
php index.php --cockpit-update --cockpit-id=3 --cockpit-path=social/modenza
php index.php --cockpit-disable --cockpit-id=3
php index.php --cockpit-enable --cockpit-id=3
```

Inspect statistics, resolve a path, or prune old data:

```bash
php index.php --cockpit-stats --cockpit-group=day
php index.php --cockpit-stats --cockpit-id=3 --cockpit-group=month
php index.php --cockpit-resolve --cockpit-path=instagram
php index.php --cockpit-prune --cockpit-days=365
```

Deletion requires an explicit confirmation flag:

```bash
php index.php --cockpit-delete --cockpit-id=3 --cockpit-force
```

Available statistic groups are `day`, `week`, and `month`. Add `--cockpit-format=json` to commands that support machine-readable output.

## Tests

Run all portable checks. Without a fixture the runner performs static checks and reports integration tests as skipped:

```bash
php tests/run.php
```

Run the self-cleaning ProcessWire and real HTTP suite against a disposable installation where Cockpit is installed:

```bash
php tests/run.php --site-root=/path/to/processwire --base-url=https://processwire.test --require-fixture
```

Add `--insecure` only for a disposable local HTTPS certificate. The tests create temporary links, validate CRUD, path and URL security, all redirect statuses, HTTP filtering and query behavior, then restore module configuration and remove every fixture.

## Redirect and Cache Notes

Use `302` for links whose destination may change. Browsers and intermediaries can cache `301` responses for a long time, so a later destination update may not immediately reach visitors.

Cockpit sends no-store, `X-CloudCache: BYPASS`, and route-marker headers for every redirect and purges changed paths through the verified CloudCache API when that optional module is available. A static cache, reverse proxy, or CDN can still answer before ProcessWire sees those headers, so exclude the entire Cockpit namespace at every infrastructure layer. A dedicated prefix such as `/r/*` is the easiest configuration at scale.

## Migrating from ShortLinks

Cockpit never imports preserved ShortLinks records automatically. The explicit importer provides a validation-only dry run followed by an all-or-nothing transactional apply; legacy tables are never deleted automatically.

Recommended migration:

1. Disable data deletion during ShortLinks uninstall.
2. Uninstall ShortLinks and remove its directory from `site/modules/`.
3. Install Cockpit and back up both legacy tables and the complete database.
4. Run `importLegacyShortLinks(false)` from an authorized local maintenance script and review every reported error.
5. Run `importLegacyShortLinks(true)` only when the target Cockpit tables are empty and the dry run has no errors.
6. Verify route ownership, links, totals, daily buckets, and an isolated database restore.
7. Preserve the legacy tables until the migration and rollback window is closed.

## Link Backup and Transfer

`exportLinks('json')` and `exportLinks('csv')` create bounded configuration backups without counters or personal data. CSV output protects spreadsheet formula cells. Imports are dry-run by default through `planLinkImport()`/`importLinks()` and never replace an existing path unless replacement is explicitly enabled. Apply revalidates every row inside one transaction and purges changed routes only after commit.

## Optional QR Provider

Cockpit includes no QR engine. When a compatible FieldtypeQRCode 1.1.4–2.x release is installed, every link receives a **QR** action that previews and downloads a verified SVG for the canonical Cockpit short URL. Because the destination is not encoded, it can change without reprinting the QR. The Cockpit settings and CLI diagnostics show provider compatibility without loading it on public redirects.

FieldtypeQRCode is maintained by EPRC/Romain Cazier under the MIT license and remains the recommended module for ProcessWire QR fields. Cockpit neither installs nor bundles it. Version 1.1.4 provides format and recovery-level support; 2.0.1+ additionally exposes size, color, and transparency controls through its public API.

## Permissions and Audit

`cockpit-manage` remains the legacy all-access permission. New installations can separate statistics, link editing, deletion, import/export, settings, and audit access. Administrative link changes create privacy-minimal audit events containing the actor, action, path, changed field names, status, and enabled state—never the destination URL or request identifiers.

## Documentation

- [Public API](API.md)
- [Known-good examples](EXAMPLES.md)
- [Agent safety guide](AGENTS.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Roadmap](ROADMAP.md)
- [Threat model](docs/THREAT-MODEL.md)
- [Production checklist and rollback](docs/PRODUCTION-CHECKLIST.md)
- [Statistics privacy](docs/PRIVACY.md)
- [Upgrade and downgrade policy](docs/UPGRADING.md)
- [FieldtypeQRCode integration plan](docs/FIELDTYPE-QRCODE-INTEGRATION.md)
- [Ichiban integration plan](docs/ICHIBAN-INTEGRATION.md)
- [Kuar-inspired feature plan](docs/KUAR-FEATURES.md)

## Author

Cockpit is built and maintained by [Maxim Semenov](https://github.com/mxmsmnv).

## Support

Use the repository issue tracker for bug reports, feature requests, and integration discussions.

## License

MIT. See [LICENSE](LICENSE).
