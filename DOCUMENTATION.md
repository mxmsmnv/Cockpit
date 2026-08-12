# Cockpit Documentation

Cockpit is a ProcessWire control center for memorable short links, campaign URLs, QR destinations, and privacy-minimal aggregate click statistics. It owns only explicitly configured paths that are not already claimed by ProcessWire or another known router.

This is the canonical setup and operations guide for Cockpit 1.0.0. Use [API.md](API.md) for exact PHP method contracts and [EXAMPLES.md](EXAMPLES.md) for copyable integrations.

## Contents

- [Requirements](#requirements)
- [Choose Cockpit for the right job](#choose-cockpit-for-the-right-job)
- [Installation and upgrade](#installation-and-upgrade)
- [First-time setup](#first-time-setup)
- [Admin workspace](#admin-workspace)
- [Create and manage a link](#create-and-manage-a-link)
- [Redirect status guide](#redirect-status-guide)
- [Paths and route ownership](#paths-and-route-ownership)
- [Destination policy](#destination-policy)
- [Campaign URL Builder](#campaign-url-builder)
- [Analytics and click counting](#analytics-and-click-counting)
- [Audit log](#audit-log)
- [QR integration](#qr-integration)
- [Permissions](#permissions)
- [Module settings](#module-settings)
- [CLI](#cli)
- [Backup, transfer, and migration](#backup-transfer-and-migration)
- [Caching and infrastructure](#caching-and-infrastructure)
- [Database and data lifecycle](#database-and-data-lifecycle)
- [Privacy and security](#privacy-and-security)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Production checklist](#production-checklist)
- [Further reference](#further-reference)

## Requirements

- ProcessWire 3.0.200 or newer
- PHP 7.4 or newer
- MySQL 5.7 or newer, or MariaDB 10.3 or newer
- InnoDB tables

Cockpit installs two ProcessWire modules:

- `Cockpit` owns validation, storage, public redirect handling, analytics, CLI commands, and integrations.
- `ProcessCockpit` provides the permission-aware admin workspace.

No template, field, or frontend page is required for a short link.

## Choose Cockpit for the right job

Use Cockpit for:

- campaign, social, print, email, and QR short links;
- memorable routes such as `/instagram` or `/campaign/summer`;
- destinations that may change while the public short URL stays stable;
- aggregate per-link and per-day click counts;
- operational link ownership, status, QR, analytics, and audit history in one admin workspace.

Do not use Cockpit as:

- an SEO migration or regex redirect manager—use Ichiban or another SEO redirect module;
- a firewall or access-control layer;
- a replacement for ProcessWire pages, URL segments, or API routers;
- a visitor-level analytics platform;
- a standalone QR engine—Cockpit integrates with FieldtypeQRCode instead.

Cockpit deliberately yields to earlier route owners. A real page or an existing SEO/API route should never be displaced merely because a Cockpit row has the same path.

## Installation and upgrade

### Install from a release archive

1. Copy the `Cockpit/` directory to `site/modules/`.
2. In ProcessWire, open **Modules > Refresh**.
3. Install **Cockpit**. ProcessWire installs `ProcessCockpit` with it.
4. Open **Cockpit** from the admin navigation.
5. Open the Cockpit module settings and complete the first-time setup below.

### Upgrade

1. Back up the database and the existing `site/modules/Cockpit/` directory.
2. Replace the released module files as one unit.
3. Run **Modules > Refresh** and allow ProcessWire to apply the module upgrade.
4. Run `php index.php --cockpit-diagnose --cockpit-format=json` from the site root.
5. Verify representative routes without following redirects.

Schema upgrades are idempotent and do not import legacy ShortLinks data automatically. Cockpit refuses to operate against a schema version newer than the installed code supports.

See [docs/UPGRADING.md](docs/UPGRADING.md) for downgrade rules and [docs/PRODUCTION-CHECKLIST.md](docs/PRODUCTION-CHECKLIST.md) for rollback.

## First-time setup

Complete these decisions before creating active links.

### 1. Select a dedicated namespace

Set **Default prefix for generated paths** to a namespace that is free across the whole site, for example `r` or `out`. This prefix is used only when the Path field is empty; administrators may still enter other free paths explicitly.

Do not select a prefix solely by looking at ProcessWire pages. Check:

- pages and templates with URL segments;
- PagePathHistory;
- Ichiban and ProcessRedirects rules;
- API, webhook, checkout, and application namespaces;
- firewall and cache behavior.

### 2. Configure destination hosts

Cockpit is fail-closed by default. Add production destinations to **Allowed destination hosts**, one per line:

```text
example.com
*.example.com
partner.example.net
```

`*.example.com` includes subdomains, while `example.com` matches the exact host. Use **Blocked destination hosts** to remove a host from a broader allowance.

Enable **Allow any public HTTP(S) host** only when unrestricted external destinations are an explicit project requirement. Private and reserved networks, localhost, `.local`, URL credentials, unsafe schemes, and blocked hosts remain rejected.

### 3. Reserve project routes

Add routes that the portable inspector cannot discover to **Additional reserved paths**:

```text
api/*
checkout/*
webhooks/payment
```

### 4. Set the public origin

Set **Public base URL** to the canonical origin, such as `https://example.com`. This keeps copied links, QR payloads, and CLI output correct when no browser request is available.

### 5. Decide the statistics policy

Review click counting, bot/preview filtering, HEAD behavior, excluded QA addresses, and daily-bucket retention. Cockpit redirects continue to work when statistics are disabled.

### 6. Exclude routes from caches

Exclude the dedicated Cockpit namespace at the static-cache, reverse-proxy, and CDN layers. A response served before ProcessWire cannot increment Cockpit counters or observe a changed destination.

## Admin workspace

The admin UI follows ProcessWire AdminThemeUikit and the [`pw-design-system`](https://github.com/mxmsmnv/pw-design-system) workspace conventions.

### Dashboard

The Dashboard shows operational totals, recent traffic, lifetime distribution, top links, and a compact Quick create form. Quick create uses the same server-side validation, permissions, CSRF, audit, and cache-purge path as the full editor.

### Links

The Links view provides bounded search and filters for state, HTTP status, and click activity. Each row links to one canonical management page for that short link.

The link page combines:

- canonical short URL and destination;
- enabled state and redirect status;
- lifetime and rolling traffic;
- 30-day timeline and exact period detail;
- QR preview/download when a compatible provider is available;
- native ProcessWire edit fields;
- link-scoped audit history;
- permission-aware delete action.

### Analytics

Analytics supports quick periods, an exact date range, link, state, redirect status, and automatic/day/week/month grouping. The current period is compared with the immediately preceding period of the same length.

### Audit

Audit lists privacy-minimal administrative changes. Filters cover path, action, actor, and date range. Deleted links keep their audit events.

### Settings

Settings are rendered through native ProcessWire Inputfields and grouped into Links, Routing, Analytics, Integrations, and Data & privacy.

## Create and manage a link

A link contains:

- **Path** — a relative, canonical route such as `r/summer-campaign`;
- **Target URL** — an absolute public `http://` or `https://` destination allowed by policy;
- **Redirect** — `301`, `302`, `307`, or `308`;
- **Status** — active or disabled.

Leave Path empty to generate a code under the configured prefix. A generated path is still checked for ownership before it is stored.

Disabled links preserve configuration, counters, and history but do not redirect. They yield to ProcessWire's normal not-found lifecycle.

Updating a destination does not change the short URL. Changing the path invalidates the old public URL and triggers cache cleanup for both old and new routes when the supported cache integration is active.

Deleting a link removes its daily statistics as one database operation. The privacy-minimal audit event remains available for accountability.

## Redirect status guide

| Status | Meaning | Choose it when |
| --- | --- | --- |
| `302` | Temporary redirect | Normal short links, campaigns, print, social, and QR destinations that may change. This is the recommended default. |
| `301` | Permanent redirect | The destination is final and long-lived. Browsers and intermediaries may cache it aggressively. |
| `307` | Temporary, method-preserving | A specialist form/API route must preserve the request method and body. |
| `308` | Permanent, method-preserving | A permanent form/API route must preserve the request method and body. |

For ordinary marketing links, use `302`. Do not select `301` merely because the target works today.

## Paths and route ownership

### Canonical path policy

Supported paths are lowercase ASCII segments containing letters, digits, dots, hyphens, underscores, or tildes, separated by single slashes.

Examples:

```text
instagram
r/summer-2026
print/catalog_v2
```

Cockpit rejects ambiguous input rather than rewriting it. Rejected forms include:

- percent or double encoding;
- encoded separators;
- `.` or `..` segments;
- duplicate slashes;
- backslashes;
- whitespace and control bytes;
- Unicode lookalikes;
- leading or trailing dots in a segment.

### Ownership checks when saving

For active links, Cockpit checks:

- ProcessWire pages and URL-segment owners;
- admin, `/wire`, and `/site` namespaces;
- PagePathHistory;
- supported Ichiban routes;
- supported ProcessRedirects routes;
- known hook-only module namespaces;
- Additional reserved paths;
- duplicate Cockpit paths;
- self redirects and active Cockpit chains/cycles.

The route inspection is fail-closed: if an installed source cannot be inspected safely, Cockpit rejects the active route instead of guessing.

### Runtime precedence

Cockpit runs as a late `ProcessPageView::pageNotFound` after-hook. Earlier owners keep precedence. This prevents Cockpit from bypassing pages, security modules, API/path hooks, SEO redirects, PagePathHistory, or normal 404 redirect modules.

A third-party route created after a Cockpit link may shadow it. Verify the real HTTP response after route or module changes.

## Destination policy

All saved targets pass the same validator, whether they come from the admin, CLI, transfer service, or Campaign URL Builder.

Cockpit accepts only absolute HTTP(S) URLs and rejects:

- unsupported or dangerous schemes;
- username/password URL components;
- localhost and `.local` names;
- private, loopback, link-local, multicast, and other reserved IP ranges;
- numeric host encodings intended to disguise an address;
- hosts outside the allowlist unless high-trust public-host mode is enabled;
- hosts matched by the denylist;
- local redirect cycles and chains too deep to validate safely.

The denylist is evaluated after the allowlist. Hostnames are compared case-insensitively and with a trailing DNS dot removed.

## Campaign URL Builder

Open **Campaign URL Builder** in a link editor to compose the destination locally in the browser.

Supported parameters:

- `utm_source`
- `utm_medium`
- `utm_campaign`
- `utm_id`
- `utm_source_platform`
- `utm_term`
- `utm_content`

When any UTM field is used, Source, Medium, and Campaign are required. The builder preserves unrelated query parameters and the URL fragment, replaces supported UTM keys without duplicates, previews the result, and changes Target URL only after **Apply to Target URL**.

UTM values are not stored in a separate Cockpit table. They remain part of the destination URL. Cockpit does not collect visitor-level campaign or referrer data.

## Analytics and click counting

Cockpit stores two traffic measures:

- a lifetime hit counter and last-hit timestamp on each link;
- aggregate daily click buckets for filtering and comparisons.

It does not store a row per visit.

### What counts

By default, Cockpit counts valid active-link GET redirects and excludes:

- known crawlers, uptime checks, and link-preview agents;
- HEAD requests;
- exact IP addresses configured for exclusion.

These checks happen in memory. Excluded IP addresses and User-Agent values are not written to analytics tables.

Statistics failures are logged but do not break a valid redirect. Disabling statistics stops new counters without disabling links.

### Analytics filters

Available presets are Today, 7 days, 30 days, 90 days, and 12 months. A custom range is inclusive and capped at 366 days. Filters also support:

- one link or all links;
- active, disabled, or all link states;
- one redirect status or all statuses;
- automatic, daily, weekly, or monthly grouping.

Automatic grouping uses days for shorter ranges, weeks for medium ranges, and months for long ranges. Empty buckets are included so charts and comparisons remain continuous.

The comparison period is the immediately preceding date range of equal length. `change_percent` is unavailable when the previous period has zero clicks.

### Retention

Daily retention accepts `0` for unlimited storage or 1–3650 days. LazyCron performs configured cleanup daily when available. `--cockpit-prune` can run the same cleanup manually. Pruning daily buckets does not reduce lifetime totals.

## Audit log

ProcessCockpit records admin create, update, enable, disable, and delete actions.

An event can contain:

- actor ID;
- link ID when applicable;
- public path;
- action;
- changed field names;
- before/after redirect status;
- before/after enabled state;
- timestamp.

It never contains destination URLs, query strings, URL fragments, visitor IP addresses, or User-Agent strings. Delete events intentionally survive link deletion, so the audit table has no cascading link foreign key.

The module service methods are trusted APIs and do not automatically know the actor. HTTP-facing integrations must provide their own authorization and audit boundary.

## QR integration

Cockpit does not bundle a QR renderer. It feature-detects [FieldtypeQRCode](https://processwire.com/modules/fieldtype-qrcode/) by EPRC/Romain Cazier and supports tested 1.1.4 through 2.x releases.

When compatible, the link management page can preview and download a verified QR for the canonical short URL. The QR contains the stable short URL, not the mutable destination, so the target can change without reprinting the code.

Supported provider output includes SVG and GIF plus recovery levels L, M, Q, and H. FieldtypeQRCode 2.0.1+ also exposes tested appearance options. Cockpit validates payload size, color values, output size, SVG active content/external references, and GIF signatures before returning the result.

If FieldtypeQRCode is absent or incompatible, redirects and analytics continue normally. Cockpit never installs or bundles the provider.

## Permissions

`cockpit-manage` is the backward-compatible umbrella permission. It grants all Cockpit admin capabilities.

Granular permissions are:

| Permission | Capability |
| --- | --- |
| `cockpit-view-stats` | Open Dashboard and Analytics and view traffic data. |
| `cockpit-manage-links` | Open Links and create or update links. |
| `cockpit-delete-links` | Delete links, but only together with link-management permission. |
| `cockpit-import-export` | Use authorized import/export workflows. The current admin UI does not expose this workflow. |
| `cockpit-settings` | Reach Cockpit settings; ProcessWire module-administration access is also required. |
| `cockpit-view-audit` | Open the administrative Audit view. |

Superusers have all capabilities. A user with only a granular permission sees only the workspace areas implied by it. Mutation actions enforce permissions server-side and validate ProcessWire CSRF tokens.

The PHP service API does not enforce roles or CSRF. Callers must do that at their own HTTP boundary.

## Module settings

### Links

| Setting | Default | Effect |
| --- | --- | --- |
| Public base URL | current ProcessWire HTTP root | Canonical origin for copied links, QR, and CLI output. |
| Default prefix for generated paths | `go` | Prefix used only when Path is empty. |
| Generated code length | `5` | Random characters after the prefix; range 3–24. |
| Default redirect status | `302` | Initial status for new links. |
| Enable new links by default | on | Initial enabled state in admin and CLI. |
| Show trailing slash | off | Display/copy preference; both request forms resolve to the same canonical path. |

### Routing

| Setting | Default | Effect |
| --- | --- | --- |
| Additional reserved paths | empty | Exact paths and `*` patterns protected from Cockpit. |
| Allowed destination hosts | empty | Production host allowlist. Empty is fail-closed unless public-host mode is enabled. |
| Blocked destination hosts | empty | Denylist applied after the allowlist. |
| Forward query parameters | off | Appends client query parameters to the target; internal ProcessWire rewrite parameters are excluded. |
| Disable redirect caching | on | Sends no-store and cache-bypass headers on Cockpit responses. |
| Allow any public HTTP(S) host | off | Explicit high-trust alternative to an allowlist. |

### Analytics

| Setting | Default | Effect |
| --- | --- | --- |
| Record click statistics | on | Writes lifetime and daily aggregate counters. |
| Ignore bots and link previews | on | Excludes known non-human request agents. |
| Count HEAD requests | off | Includes HEAD redirects in counters. |

### Data & privacy

| Setting | Default | Effect |
| --- | --- | --- |
| IP addresses excluded from statistics | empty | Exact IPv4/IPv6 values compared at request time and never stored. |
| Daily statistics retention | `0` | Days to keep daily buckets; `0` keeps them indefinitely. |
| Delete links and statistics when uninstalling | off | Permanently drops all Cockpit tables only during uninstall. |

## CLI

Run commands from the ProcessWire site root containing `index.php`.

### Discover commands

```bash
php index.php --cockpit-help
php index.php --cockpit-help=create
```

### Read and diagnose

```bash
php index.php --cockpit-list
php index.php --cockpit-resolve --cockpit-path=r/example
php index.php --cockpit-stats --cockpit-group=day
php index.php --cockpit-stats --cockpit-id=3 --cockpit-group=month
php index.php --cockpit-diagnose --cockpit-format=json
```

`resolve` does not redirect or increment a counter. It reports whether the stored link is enabled and whether route inspection considers it effective.

### Create, update, and state

```bash
php index.php --cockpit-create \
  --cockpit-path=r/summer \
  --cockpit-target='https://example.com/landing' \
  --cockpit-status=302

php index.php --cockpit-update \
  --cockpit-id=3 \
  --cockpit-target='https://example.com/new-landing'

php index.php --cockpit-disable --cockpit-id=3
php index.php --cockpit-enable --cockpit-id=3
```

Omit `--cockpit-path` during create to generate a path. Create uses the configured default enabled state; update preserves omitted values.

### Destructive maintenance

```bash
php index.php --cockpit-delete --cockpit-id=3 --cockpit-force
php index.php --cockpit-prune --cockpit-days=365
```

Delete requires `--cockpit-force`. Prune removes daily buckets only.

### Machine-readable output

Add `--cockpit-format=json` for JSON where supported. Failures return `ok: false` and a non-zero process exit code.

## Backup, transfer, and migration

### Link configuration export

```bash
php index.php --cockpit-export-links \
  --cockpit-transfer-format=json \
  --cockpit-output=cockpit-links.json

php index.php --cockpit-export-links \
  --cockpit-transfer-format=csv \
  --cockpit-output=cockpit-links.csv
```

Exports contain path, target, redirect status, and enabled state. They intentionally exclude IDs, counters, daily statistics, and audit events. CSV cells are protected against spreadsheet formula injection.

### Plan and apply an import

```bash
php index.php --cockpit-import-links=cockpit-links.json \
  --cockpit-format=json

php index.php --cockpit-import-links=cockpit-links.json \
  --cockpit-replace \
  --cockpit-force \
  --cockpit-format=json
```

Import is a dry run unless `--cockpit-force` is present. Existing paths are rejected unless `--cockpit-replace` is separately present. Every row is revalidated inside a single transaction before apply. The default limits are 5 MiB and 5,000 rows.

The current ProcessCockpit admin does not expose import/export controls; use the trusted CLI or documented PHP API.

### Legacy ShortLinks migration

Cockpit never imports legacy tables during installation or upgrade.

1. Back up the complete database.
2. Preserve `short_links` and `short_link_daily_stats`.
3. Ensure Cockpit has no links or statistics.
4. Run a bounded dry run:

   ```bash
   php index.php --cockpit-import-legacy --cockpit-format=json
   ```

5. Resolve every reported error.
6. Apply only after a clean dry run:

   ```bash
   php index.php --cockpit-import-legacy --cockpit-force --cockpit-format=json
   ```

The import is all-or-nothing, preserves legacy tables, maps link statistics, and refuses to merge into non-empty Cockpit tables.

## Caching and infrastructure

When **Disable redirect caching** is enabled, Cockpit sends no-store/no-cache headers, `X-CloudCache: BYPASS`, and a Cockpit route marker for every supported redirect status.

When the optional CloudCache module is installed, Cockpit uses its verified public APIs to clear local static entries and purge configured external URLs after link changes. Cache failure is logged and does not roll back a successfully saved link.

These response-time measures cannot invalidate a cache that answers before PHP. Configure infrastructure exclusions for the whole Cockpit namespace and verify them after changes to:

- ProcessWire caching;
- reverse proxies;
- CDN rules;
- server-level redirects;
- static site exports.

Forwarded query strings preserve client order and repeated keys while excluding ProcessWire's internal rewrite parameter. If the target already has a query string, client parameters are appended.

## Database and data lifecycle

Cockpit uses the configured ProcessWire `dbPrefix` and maintains:

| Table | Purpose |
| --- | --- |
| `cockpit_links` | Link configuration, lifetime hits, and last-hit timestamp. |
| `cockpit_daily_stats` | Aggregate per-link daily click buckets with cascading deletion. |
| `cockpit_schema` | Installed schema version. |
| `cockpit_audit_log` | Privacy-minimal admin change events. |

The current schema version is 3. Installation and upgrades use an advisory lock, idempotent migrations, required-column checks, indexes, and a foreign key from daily statistics to links.

Uninstall preserves every table by default. If **Delete links and statistics when uninstalling** is enabled, uninstall permanently drops all four tables. Back up and test restore before enabling it.

## Privacy and security

Cockpit analytics are aggregate by design. They do not store cookies, visitor IP addresses, User-Agent strings, referrers, or per-request rows.

The admin audit log stores operational metadata but never stores destination values or visitor identifiers. Authorized users can still see link destinations and traffic totals, so apply least-privilege permissions.

Security boundaries include:

- canonical fail-closed path parsing;
- deterministic route inspection;
- destination host and public-address policy;
- local cycle detection;
- CSRF and permission checks in ProcessCockpit;
- bounded imports and exports;
- transactional writes;
- output escaping and QR payload verification;
- redirect cache bypass.

Review [docs/THREAT-MODEL.md](docs/THREAT-MODEL.md), [docs/PRIVACY.md](docs/PRIVACY.md), and [SECURITY.md](SECURITY.md) before production rollout.

## Testing

### Portable suite

```bash
php tests/run.php
```

This performs PHP lint and dependency-free tests. Live integration checks are explicitly skipped without a fixture.

### Disposable ProcessWire fixture

```bash
php tests/run.php \
  --site-root=/path/to/processwire \
  --base-url=https://processwire.test \
  --require-fixture
```

Add `--insecure` only for a disposable local certificate. The suite validates CRUD, routing, security, schema, permissions, analytics, transfer, optional integration contracts, real `301`/`302`/`307`/`308` responses, query forwarding, counting policy, and cleanup.

### Managed demo data

On an authorized local or disposable site:

```bash
php tests/seed-demo.php --site-root=/path/to/processwire
php tests/seed-demo.php --site-root=/path/to/processwire --apply
php tests/seed-demo.php --site-root=/path/to/processwire --remove
```

Preview is the default. Apply owns eight exact `cockpit-demo/*` paths and 120 deterministic daily buckets. Remove deletes only fixture-owned rows. Never use the demo fixture on production.

### HTTP verification

Test representative active and disabled links without following redirects:

```bash
curl -sS -D - -o /dev/null --max-redirs 0 https://example.com/r/test
```

Confirm status, `Location`, cache headers, counter behavior, and route precedence.

## Troubleshooting

### Every new destination is rejected

The destination policy is probably still fail-closed. Add the exact host or wildcard to **Allowed destination hosts**, or explicitly enable public-host mode after review.

### A path is reported as owned

Run:

```bash
php index.php --cockpit-resolve --cockpit-path=the/path --cockpit-format=json
```

For a new path that has no Cockpit row yet, call `inspectRoute()` from an authorized maintenance script. Review every claim and source error. Do not force Cockpit to outrank the owner.

### A stored active link does not redirect

Check the real response without following redirects. An earlier page, API, security module, Ichiban rule, PagePathHistory entry, ProcessRedirects rule, server redirect, or cache may own the request.

### Click totals do not increase

Verify that statistics are enabled and the request is not HEAD, a known bot/preview, or from an excluded address. Then verify that no static cache or CDN answered before ProcessWire.

### The QR panel is unavailable

Open Cockpit settings and review Integrations. Confirm FieldtypeQRCode is installed and its version is within the tested 1.1.4–2.x range. Run `--cockpit-diagnose` for provider status.

### Copied or QR URLs use the wrong origin

Set an absolute HTTPS **Public base URL**. This is especially important for CLI and background contexts.

### Analytics and lifetime totals differ

Lifetime totals survive daily-bucket pruning. A retained date range may therefore be smaller than the lifetime counter by design.

### Import will not apply

Import is dry-run by default. The plan must contain no issues, apply requires `--cockpit-force`, and replacement requires the separate `--cockpit-replace` flag.

## Production checklist

Before launch:

1. Back up and test database restore.
2. Confirm installed Cockpit and ProcessCockpit versions.
3. Choose and scan a dedicated route prefix.
4. Configure reserved project namespaces.
5. Use an explicit destination allowlist where practical.
6. Set the canonical public base URL.
7. Review granular roles and remove unnecessary umbrella access.
8. Decide bot, HEAD, exclusion, and retention policy.
9. Exclude the namespace from every cache/CDN layer.
10. Run schema diagnostics and the disposable integration suite.
11. Test real active, disabled, page-conflict, SEO redirect, API, and firewall routes.
12. Verify QR output and download if FieldtypeQRCode is used.
13. Confirm analytics and audit visibility for each role.
14. Keep uninstall data deletion disabled unless permanent removal is planned.

## Further reference

- [README](README.md) — product overview and quick start
- [Public API](API.md) — exact supported PHP methods and return shapes
- [Examples](EXAMPLES.md) — known-good integration snippets
- [Agent guide](AGENTS.md) — safe automation and maintenance boundaries
- [Roadmap](ROADMAP.md) — future work, not current API
- [Production checklist](docs/PRODUCTION-CHECKLIST.md)
- [Upgrade and downgrade policy](docs/UPGRADING.md)
- [Threat model](docs/THREAT-MODEL.md)
- [Privacy statement](docs/PRIVACY.md)
- [FieldtypeQRCode integration notes](docs/FIELDTYPE-QRCODE-INTEGRATION.md)
- [Ichiban integration notes](docs/ICHIBAN-INTEGRATION.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)

Cockpit is built and maintained by [Maxim Semenov](https://github.com/mxmsmnv) and released under the [MIT License](LICENSE).
