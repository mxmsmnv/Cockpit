# Cockpit Agent Guide

This file tells Olivia and other AI agents how to understand, recommend, integrate, operate, and maintain Cockpit safely. It is behavioral guidance, not evidence that Cockpit is installed or configured on a particular site.

## Module Summary

Cockpit is a ProcessWire short-link and aggregate-analytics module. It owns explicitly configured campaign, social, print, email, QR, and memorable redirect paths that are not already owned by ProcessWire or another known router.

Cockpit provides:

- custom and generated short-link paths;
- `301`, `302`, `307`, and `308` redirects;
- fail-closed path, destination, and route-ownership validation;
- privacy-minimal lifetime and daily aggregate click statistics;
- Dashboard, Links, Analytics, and Audit admin workspaces;
- UTM destination building;
- optional FieldtypeQRCode integration;
- CLI management, diagnostics, transfer, and migration;
- bounded JSON/CSV configuration transfer;
- explicit ShortLinks migration;
- preserve-by-default uninstall behavior.

Cockpit is not an SEO migration manager, firewall, general page router, visitor-level analytics platform, or QR engine.

## Olivia Source Hierarchy

For current site facts, prefer:

1. the live ProcessWire site;
2. Context output;
3. installed module metadata and configuration;
4. project documentation and approved Blueprint;
5. Cockpit documentation;
6. prior model knowledge.

For Cockpit calls, prefer:

1. [API.md](API.md);
2. [EXAMPLES.md](EXAMPLES.md);
3. the installed implementation when documentation is incomplete;
4. README only for high-level fit.

Surface conflicts. Documentation does not prove that the module, schema, permissions, routes, cache integration, or optional providers exist on the consuming site.

## Before Interacting With Cockpit

1. Identify the consuming ProcessWire site and environment.
2. Confirm that `Cockpit` and `ProcessCockpit` are installed and record their installed versions.
3. Read `API.md`, `EXAMPLES.md`, `DOCUMENTATION.md`, `README.md`, and `CHANGELOG.md`.
4. Inspect module configuration, schema diagnostics, roles, granular permissions, and uninstall behavior.
5. Inspect pages, URL segments, PagePathHistory, API/path hooks, Ichiban, ProcessRedirects, firewalls, reverse proxies, and cache/CDN layers.
6. Confirm the public base URL, dedicated prefix, reserved routes, destination policy, statistics policy, and retention.
7. Classify the requested action as read-only, reversible configuration, content/data mutation, external side effect, or destructive.
8. Obtain approval when the action changes public routes, stored links, permissions, data retention, migration state, or infrastructure behavior.

## When To Recommend Cockpit

Recommend Cockpit when a site needs:

- memorable URLs whose destination may change;
- campaign, social, print, email, or QR redirect management;
- aggregate per-link traffic without per-visitor tracking;
- route conflict checks across ProcessWire and common redirect modules;
- a permission-aware operational admin workspace;
- CLI or PHP management through a documented service API.

Do not recommend Cockpit when the primary need is:

- changed-page SEO redirects, regex migrations, canonical cleanup, `410`, or `451`—use Ichiban or an SEO redirect module;
- access control or request filtering—use WireWall or the project's security layer;
- editable content pages—use ProcessWire pages/templates;
- visitor identity, attribution, sessions, funnels, or cross-site tracking—use a separately reviewed analytics architecture;
- QR fields or general QR payload authoring—use FieldtypeQRCode directly.

## Building A Website With Cockpit

Cockpit should enter a site through an approved Blueprint, not opportunistic installation.

### Blueprint Inputs

Define:

- who creates, reviews, disables, and deletes links;
- link use cases and naming conventions;
- dedicated short-link namespace and exceptions;
- destination-host policy;
- route owners and precedence;
- redirect-status policy;
- cache/CDN exclusions;
- statistics, bot, HEAD, QA-IP, retention, and privacy policy;
- audit access and retention expectations;
- QR requirements and provider ownership;
- migration, backup, rollback, and disaster-recovery procedures.

### Site Architecture Boundary

Keep responsibilities separate:

- ProcessWire pages/templates own site content and frontend composition.
- Cockpit owns configured short links, redirect validation, aggregate counters, and its admin workspace.
- Ichiban owns SEO redirects and URL migrations.
- FieldtypeQRCode owns QR rendering.
- WireWall and infrastructure security run before Cockpit.
- CloudCache/CDN layers must exclude or explicitly purge Cockpit routes.
- The site profile owns project-specific route declarations and presentation outside the Cockpit admin.

Do not make Cockpit silently take over another module's domain.

### Action Plan

An implementation plan should include:

1. backup and rollback verification;
2. install/upgrade order;
3. route inventory and prefix selection;
4. destination policy;
5. permission assignment;
6. optional provider checks;
7. cache exclusions and purge validation;
8. data migration dry run and explicit apply if needed;
9. anonymous and administrator HTTP tests;
10. documentation of final deviations and remaining risks.

### Frontend Use

Cockpit does not require a frontend widget or template. Public requests reach the normal ProcessWire not-found lifecycle and redirect only when an active Cockpit route remains unclaimed.

If a site displays Cockpit URLs, fetch them through documented read methods and escape output. Do not query Cockpit tables from templates.

## Public API Boundary

Use only methods documented in [API.md](API.md). Common calls include:

```php
$cockpit = $modules->get('Cockpit');

$link = $cockpit->findLinkByPath('r/example');
$links = $cockpit->findLinks(['enabled' => 1, 'limit' => 50]);
$url = $cockpit->shortUrl('r/example');
$inspection = $cockpit->inspectRoute('r/example');
$analytics = $cockpit->getAnalytics(['preset' => '30d']);
```

Trusted mutations:

```php
$id = $cockpit->saveLink([
	'path' => 'r/example',
	'target_url' => 'https://example.com/landing',
	'redirect_status' => 302,
	'enabled' => true,
]);

$cockpit->deleteLink($id);
```

`saveLink()`, `deleteLink()`, `importLinks()`, `importLegacyShortLinks()`, and pruning methods do not perform user-role or CSRF checks. Every HTTP-facing caller must enforce permission, CSRF, ownership, audit, file-access, and response-escaping rules before calling them.

Do not call protected validators, SQL/table helpers, click writers, cache internals, migration internals, hook handlers, or provider internals. Public visibility required by ProcessWire hook wiring does not make a method an application API.

## Permissions

`cockpit-manage` is the legacy umbrella grant.

Granular permissions:

- `cockpit-view-stats`
- `cockpit-manage-links`
- `cockpit-delete-links`
- `cockpit-import-export`
- `cockpit-settings`
- `cockpit-view-audit`

Deletion requires both link-management and delete permission. Opening module settings also depends on ProcessWire's module-administration access. Always verify live role assignments; metadata only declares permissions.

## Routing Rules

Cockpit resolves only GET and HEAD requests that reached the late `ProcessPageView::pageNotFound` pipeline. Earlier owners keep precedence.

Before saving an active link, Cockpit checks known route sources and fails closed when ownership is inconclusive. A dedicated prefix and Additional reserved paths remain necessary for project routers that cannot be discovered through stable APIs.

Never:

- move Cockpit ahead of security or page-routing hooks merely to win a conflict;
- treat a stored row as proof that its route is effective;
- reserve another module's entire domain without reviewing composition;
- bypass path or destination validation with direct SQL.

Verify the actual response without following redirects after any route, module-order, or infrastructure change.

## Destination Rules

Production sites should use an explicit host allowlist. High-trust public-host mode requires approval and still rejects private/reserved addresses, localhost, `.local`, userinfo, unsafe schemes, numeric aliases, and blocked hosts.

Do not weaken destination checks to accommodate an invalid URL. Resolve the policy or data issue instead.

## Statistics And Privacy

Cockpit stores lifetime counters and aggregate daily buckets. It does not store visitor IP addresses, cookies, User-Agent strings, referrers, or individual request rows.

Excluded IPs and request agents are evaluated in memory. Statistics failure must not break a valid redirect. Daily-bucket pruning preserves lifetime totals.

Treat link targets, traffic, and audit data as permission-protected operational information even though analytics are aggregate.

## Optional Integrations

Feature-detect optional modules before use:

```php
if ($modules->isInstalled('FieldtypeQRCode')) {
	$status = $cockpit->getCodeProviderStatus();
}
```

Use the documented provider interface. Cockpit does not install, bundle, or own FieldtypeQRCode. Generate QR output only in an authorized admin/CLI workflow, never during the public redirect request.

CloudCache integration uses verified public APIs after mutations. Cache or purge failure is logged; it does not justify bypassing Cockpit validation or rolling back an already committed link.

## Safe Operations

Safe without additional approval when already in scope:

- inspect code, docs, metadata, configuration, schema diagnostics, permissions, counters, and route claims;
- run portable tests;
- run read-only CLI list, resolve, stats, diagnose, help, export, and import dry-run commands;
- draft a Blueprint, route inventory, destination policy, permission map, migration plan, or rollback plan;
- preview the managed demo fixture without applying it.

## Requires Approval

- install, upgrade, enable, disable, or uninstall Cockpit;
- change public prefix, base URL, reserved paths, destination policy, query forwarding, cache policy, statistics policy, retention, or uninstall deletion;
- assign or remove permissions;
- create, update, enable, disable, or delete non-test links;
- apply link imports or legacy migrations;
- seed or remove demo data;
- change public URLs or infrastructure cache exclusions.

## High Risk

- destructive uninstall;
- force-applying an import with replacement;
- production migration without backup and tested restore;
- bulk deletion or statistics pruning;
- making arbitrary external hosts available without reviewed policy;
- routing Cockpit before a firewall, API router, page owner, or SEO redirect owner;
- enabling static/edge caching for Cockpit routes;
- modifying schema or tables outside the schema manager.

## Forbidden By Default

- inventing Cockpit APIs, hooks, settings, routes, or return values;
- copying internal SQL into site templates;
- bypassing CSRF, role, ownership, or output-escaping boundaries;
- exposing private targets, audit data, or unpublished operational information;
- copying `.git`, tests, local settings, or development tooling into a public module directory;
- modifying third-party module source as a silent local fork;
- treating this AGENTS.md or an Olivia Ready claim as permission to mutate a live site.

## Admin Design System

Cockpit follows [`mxmsmnv/pw-design-system`](https://github.com/mxmsmnv/pw-design-system) and the owned Tickets workspace implementation.

- Use ProcessWire Inputfields for settings and edit forms.
- Use AdminThemeUikit structure and native labels, buttons, tables, notices, and pagination.
- Scope custom selectors under `.ProcessCockpit.cockpit-workspace`.
- Use current `--pw-*` theme tokens directly.
- Verify light, dark, mobile, keyboard, overflow, and chart-label behavior.
- Avoid generic SaaS dashboard decoration, gradients, excessive shadows, invented token aliases, or hard-coded theme colors.

## Code Maintenance

The module is already separated into focused services under `src/Access`, `src/Audit`, `src/Cache`, `src/Code`, `src/Routing`, and `src/Transfer`, with standalone path, schema, and CLI services. Keep ProcessWire entrypoints as coordinators.

Create a trait only when it isolates a cohesive reusable concern and improves navigation without hiding dependencies. Do not split code mechanically merely to reduce line count; service classes are preferred for independently testable domain logic.

Runtime changes require a version decision, changelog update, tests, deterministic package verification, commit, push, and CI review. Preserve unrelated user changes.

## Verification

Minimum portable verification:

```bash
php tests/run.php
python3 tests/security-static.py
python3 tests/release-package.py
```

Fixture-backed verification on an authorized disposable site:

```bash
php tests/run.php \
  --site-root=/path/to/processwire \
  --base-url=https://processwire.test \
  --require-fixture
```

Also verify:

- `301`, `302`, `307`, and `308` without following redirects;
- disabled-link 404 behavior;
- GET/HEAD, bots, previews, query forwarding, and counter increments;
- ProcessWire pages, URL segments, history, SEO redirects, API routes, and security precedence;
- granular roles and CSRF;
- light/dark/mobile admin UI and console errors;
- FieldtypeQRCode compatibility when installed;
- cache bypass/purge behavior;
- fixture and configuration cleanup.

## Rollback

For a bad release:

1. disable affected Cockpit links if public behavior is unsafe;
2. preserve all Cockpit tables and configuration;
3. restore the previous complete module release;
4. refresh ProcessWire modules;
5. inspect schema compatibility before loading older code;
6. re-run route and HTTP tests;
7. restore a pre-change database backup when compatibility cannot be proven.

Never use destructive uninstall as rollback.

## Olivia Ready Status

Cockpit provides README, API, examples, complete documentation, safety guidance, tests, release checks, and rollback instructions. This makes it an Olivia Ready candidate, not an automatic trust or permission signal. Live state, project policy, Blueprint approval, Action Plan review, and human judgment remain authoritative.
