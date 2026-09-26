# Cockpit Public API

This document describes the public PHP surface verified in Cockpit 1.0.0. For installation, configuration, permissions, operations, and troubleshooting, see [DOCUMENTATION.md](DOCUMENTATION.md).

## Olivia Usage Rules

Olivia must confirm the live module version and site configuration before using this API. API.md is authoritative for supported calls, but it does not prove that Cockpit, its schema, permissions, optional providers, or a specific route exists on the consuming site.

For website architecture, Cockpit should be added through an approved Blueprint defining route ownership, destination hosts, roles, statistics/privacy policy, cache exclusions, migration, and rollback. ProcessWire pages/templates own frontend composition; Cockpit owns only configured short links and aggregate counters. Use Ichiban for SEO migrations, FieldtypeQRCode for QR rendering, and the site's security layer before Cockpit.

## Compatibility

- ProcessWire 3.0.200+
- PHP 7.4+
- MySQL 5.7+, MariaDB 10.3+, SQLite, or PostgreSQL through ProcessWire's database layer

Cockpit must be installed before its database-backed methods are used.

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('Cockpit')) {
	/** @var Cockpit $cockpit */
	$cockpit = $modules->get('Cockpit');
}
```

## Trust Boundary

The methods below are a module/service API. They do not perform ProcessWire role checks or CSRF validation themselves. `ProcessCockpit` supplies those checks for its admin forms and the CLI is a trusted local execution context.

Site code that exposes a mutation to an HTTP request must check an appropriate permission, validate CSRF, validate ownership where applicable, and avoid returning private targets or statistics to unauthorized users.

```php
if (!$user->hasPermission('cockpit-manage')) throw new WirePermissionException();
$session->CSRF->validate();
$id = $cockpit->saveLink($data);
```

## Link Shape

Raw link methods return database-shaped associative arrays:

```php
[
	'id' => 12,
	'path' => 'r/example',
	'target_url' => 'https://example.com/landing',
	'redirect_status' => 302,
	'enabled' => 1,
	'hits' => 42,
	'last_hit_at' => '2026-08-11 10:00:00',
	'created_at' => '2026-08-10 09:00:00',
	'updated_at' => '2026-08-11 08:00:00',
]
```

Numeric and boolean database values may be returned as strings by PDO. Cast them at the application boundary.

## Read Methods

### `findLinkByPath(string $path): ?array`

Returns an exact stored-path match or `null`. Call `normalizePath()` first when the value comes from user input.

### `findLinkById(int $id): ?array`

Returns a link or `null`.

### `findLinks(array $filters = []): array`

Accepts optional `query`, `enabled`, `redirect_status`, `active_only`, `limit` (maximum 500), and `offset` filters. Without filters it returns every link ordered newest first for backward compatibility; prefer bounded calls.

### `countLinks(array $filters = []): int`

Returns the total for the same search/state/status/activity filters without applying pagination.

### `getPageConflict(string $path): ?array`

Returns `id`, `path`, and `title` for a ProcessWire page, URL-segment owner, or PagePathHistory owner. Returns `null` when none is found. This does not inspect every third-party router; configure reserved paths and use a dedicated prefix.

### `inspectRoute(string $path): Cockpit\\Routing\\RouteInspection`

Runs the read-only route-claim registry without redirecting or incrementing counters. The JSON-serializable result includes deterministic owner/claim metadata and source errors. An inconclusive result must be treated as unavailable. Current adapters feature-detect ProcessWire pages and history, Ichiban, ProcessRedirects, and common hook-only namespaces.

### `getDashboardTotals(): array`

Returns `links`, `active_links`, `disabled_links`, `total`, `today`, `seven_days`, and `thirty_days` integer counters.

### `getStatistics(string $group, int $linkId = 0): array`

`$group` must be `day`, `week`, or `month`. An invalid value falls back to `day`. The result is a continuous list of `bucket` and `clicks` rows: 30 days, 12 weeks, or 12 months.

### `getAnalytics(array $filters = []): array`

Returns a bounded analytics report with normalized `filters`, continuous `buckets`, top-link `shares`, and a `summary` containing selected-period clicks, previous-period clicks and percentage change, daily average, peak bucket, and day count.

Supported filters are `preset` (`today`, `7d`, `30d`, `90d`, `12m`, or `custom`), `date_from`, `date_to`, `group` (`auto`, `day`, `week`, or `month`), `link_id`, `state` (`all`, `active`, or `disabled`), and redirect `status`. Custom ranges are capped at 366 inclusive days; future end dates and invalid filters are normalized safely. Results contain aggregate counts only.

## Mutation Methods

### `saveLink(array $data, bool $deferCachePurge = false): int`

Creates or updates a link and returns its ID.

Supported keys:

- `id`: omit or use `0` to create;
- `path`: relative path; an empty value generates one under the configured prefix;
- `target_url`: absolute HTTP(S) URL;
- `redirect_status`: `301`, `302`, `307`, or `308`;
- `enabled`: truthy to activate the route.

The method validates the path, fail-closed destination policy, ProcessWire route ownership, reserved patterns, duplicates, self redirects, and active Cockpit cycles. A destination requires either a matching allowlist entry or the explicit Allow any public HTTP(S) destination host setting. It throws `WireException` when validation fails.

The second argument exists for Cockpit's transactional transfer service. Ordinary callers must leave it at `false`; setting it to `true` suppresses post-save cache invalidation and can leave stale public routes.

The admin Campaign URL Builder is a client-side composition aid, not a separate mutation API. It writes the generated campaign URL into `target_url`, so the same `saveLink()` validation and destination policy apply. UTM metadata is not stored separately.

### `validateLinkCandidate(array $data): array`

Runs the same canonical path, destination, ownership, loop, status, and duplicate checks as `saveLink()` without writing, counting, auditing, or purging. It never generates an empty path. The returned shape is `id`, `path`, `target_url`, `redirect_status`, and boolean `enabled`.

### `deleteLink(int $id): void`

Deletes the link and its daily statistics in one transaction. A non-positive or unknown ID is a no-op. Database failures are rethrown after rollback.

### `pruneOldStatistics(int $days = 0): int`

Deletes daily buckets older than the requested retention period and returns the affected row count. Passing `0` uses module configuration; configured `0` keeps buckets indefinitely. Lifetime totals in `cockpit_links.hits` are preserved.

### `diagnoseSchema(): array`

Returns current/expected schema versions, table presence, row counts, orphan statistic buckets, and foreign-key state without changing the database.

### `importLegacyShortLinks(bool $apply = false, int $rowLimit = 10000, int $errorLimit = 100): array`

Validates preserved ShortLinks `code` or `path` tables through Cockpit's current route and target policies. The default dry run rolls back all inserted rows. Apply mode commits only when every link and statistic is valid and Cockpit is empty. Run only from an explicitly authorized local maintenance context after a backup; review the bounded report before apply.

### Link transfer

- `exportLinks(string $format = 'json'): string`
- `planLinkImport(string $payload, string $format, bool $replaceExisting = false): TransferPlan`
- `importLinks(string $payload, string $format, bool $apply = false, bool $replaceExisting = false): TransferReport`

JSON uses schema `cockpit.links.transfer`, version `1`; CSV has exact `path,target_url,redirect_status,enabled` columns. Both are bounded to 5 MiB and 5,000 rows by default. Import is dry-run and non-replacing unless the caller explicitly selects otherwise. Authorization, CSRF, upload/file access, audit context, and apply confirmation remain caller responsibilities.

### Code providers

- `getCodeProviders(): array`
- `getCodeProvider(string $id): ?CodeProviderInterface`
- `getCodeProviderStatus(): array`

The bundled adapter ID is `fieldtype-qrcode`. It is available when a tested FieldtypeQRCode 1.1.4–2.x release is installed and exposes its documented static raw generator. FieldtypeQRCode 1.1.4 supports format and recovery-level selection; 2.0.1+ additionally supports the documented size, color, and transparency options. Generation validates payload length, QR format/options/colors, output size, SVG active content/external references, and GIF signatures. It must be called only from an authorized admin/CLI workflow, never the public redirect request.

`getCodeProviderStatus()` returns safe capability and version diagnostics without generating a code. The Cockpit admin uses the provider only on an authorized management page for a stored link and encodes the canonical short URL, not its mutable destination.

## URL And Path Helpers

### `normalizePath(string $path): string`

Returns the lowercase canonical form of a supported ASCII route, or an empty string for ambiguous/unsupported input. Percent encoding, encoded or literal dot segments, duplicate slashes, backslashes, whitespace, control bytes, Unicode, and leading/trailing dots in a segment are rejected rather than rewritten. Route availability is checked separately.

### `generatePath(int $length = 0): string`

Generates an unused candidate under the configured prefix. Final availability is checked by `saveLink()`.

### `shortUrl(string $path): string`

Builds a public short URL using the configured Public base URL when valid, otherwise ProcessWire's current `httpRoot`.

### Defaults

- `getBasePath(): string`
- `getDefaultRedirectStatus(): int`
- `getGeneratedCodeLength(): int`
- `newLinksEnabledByDefault(): bool`

## Runtime Routing Contract

Cockpit runs as a late `ProcessPageView::pageNotFound` after-hook with priority `200`. Earlier owners keep precedence, including real pages, URL segments, security modules, API/path hooks, Ichiban, PagePathHistory, and standard 404 redirect modules.

Before an active link is saved, the read-only route registry checks known owners and fails closed when a source cannot be inspected. Use a dedicated prefix and Additional reserved paths for project routers the portable registry cannot discover.

Redirects use `Session::redirect()` so ProcessWire and compatible cache/infrastructure hooks observe the normal redirect lifecycle.

## CLI

Run CLI commands from the ProcessWire site root. Use `php index.php --cockpit-help` for the canonical command and option list. JSON mode returns `ok: false` and a non-zero exit status on failure.

## Internal APIs

Do not call protected table-name helpers, target validators, host matchers, migration helpers, click writers, or route-resolution internals. Their signatures and behavior are not public compatibility promises.
