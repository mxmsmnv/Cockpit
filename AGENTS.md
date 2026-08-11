# Cockpit Agent Guide

## Purpose

Cockpit owns campaign, print, QR, and memorable short-link routes with aggregate click statistics. It is not an SEO redirect manager, firewall, page router, QR generator, or full analytics platform.

Use Ichiban or another SEO redirect module for changed page URLs, canonical migrations, regex SEO rules, and `410`/`451`. Use FieldtypeQRCode as the preferred ProcessWire QR provider. Let WireWall and equivalent security infrastructure inspect requests before Cockpit.

## Before Using Cockpit

1. Confirm the consuming site and inspect its live state.
2. Confirm Cockpit and ProcessCockpit are installed and record their versions.
3. Read `API.md`, `README.md`, `EXAMPLES.md`, `CHANGELOG.md`, and `ROADMAP.md`.
4. Inspect pages, URL segments, PagePathHistory, API/path hooks, redirect modules, firewall modules, and cache layers.
5. Select a dedicated prefix that is free in every known router.
6. Configure Additional reserved paths for custom endpoints that cannot be inspected through public APIs.
7. Configure a canonical Public base URL for CLI use when HTTPS cannot be inferred.

## Admin Design System

[`mxmsmnv/pw-design-system`](https://github.com/mxmsmnv/pw-design-system) supplies the canonical tokens and interaction boundaries. The owned `Tickets` module is Cockpit's verified in-workspace implementation reference for page structure, rhythm, navigation, metrics, panels, filters, and tables.

- Start from `src/components/25-module-workspace.html` for Process pages and `25-module-guidelines.html` for implementation boundaries.
- Follow Tickets' horizontal `uk-subnav uk-subnav-pill` workspace navigation with a separate settings control.
- Build settings and edit forms with ProcessWire Inputfields.
- Reuse Tickets' page-intro, spacing scale, metric, panel, filter, and table composition while keeping Cockpit business logic independent.
- Scope every custom selector under `.ProcessCockpit.cockpit-workspace` and use current `--pw-*` tokens directly.
- Verify light, dark, and mobile layouts, including horizontal containment for navigation and dense tables.
- Do not introduce decorative gradients, generic SaaS-dashboard styling, large radii, excessive shadows, or local color aliases for design-system tokens. Local spacing aliases are allowed when they match Tickets' documented rhythm.

Documentation does not prove current site configuration. Prefer live site state and surface conflicts.

## Routing Boundary

Cockpit runs late in the ProcessWire not-found pipeline. Earlier route owners win. This protects pages, security modules, API endpoints, Ichiban, PagePathHistory, and existing 404 redirect modules from being bypassed.

This precedence is necessary but not a complete conflict scanner. A saved link may be shadowed by an earlier third-party route. Do not report a route as effective merely because it exists in `cockpit_links`; verify the real HTTP response without following redirects.

Never move Cockpit back ahead of `ProcessPageView::execute` security hooks. Never make it first merely to win a conflict.

## Safe Operations

- Inspect configuration, code, module metadata, tables, counters, and route ownership.
- Run PHP lint and the self-cleaning integration smoke test on a disposable/local site.
- Use CLI list, resolve, statistics, and help commands.
- Draft configuration, reserved paths, conflict reports, and rollback plans.
- Create clearly named temporary links on an authorized development site and delete them after testing.

## Requires Explicit Approval

- Install, uninstall, upgrade, enable, or disable Cockpit on a consuming site.
- Change the public prefix, public base URL, reserved paths, destination policy, query forwarding, retention, or uninstall deletion setting.
- Assign `cockpit-manage` to a role.
- Create, update, enable, disable, or delete non-test links.
- Enable a cache/CDN layer for Cockpit paths.
- Import or migrate ShortLinks data.

## High Risk

- Enabling Delete data on uninstall.
- Removing a dedicated prefix from cache exclusions.
- Publishing an active route that overlaps a page, API, firewall exception, Ichiban rule, PagePathHistory, or another redirect module.
- Bulk replacement, migration, or deletion without a backup and rollback test.
- Allowing arbitrary public destination hosts without a reviewed policy and explicit configuration.

## API Safety

`saveLink()`, `deleteLink()`, and `pruneOldStatistics()` are trusted service methods. They do not perform role or CSRF checks. Any HTTP-facing caller must enforce permission and CSRF at its own boundary. Use only methods documented in `API.md`; do not copy Cockpit SQL into templates.

## Cache Safety

Cockpit uses ProcessWire's redirect lifecycle, but a static cache, reverse proxy, or CDN can answer before ProcessWire. Exclude the dedicated Cockpit prefix from every such layer. Re-test after any cache-policy change. A cache hit that bypasses PHP also bypasses Cockpit counters.

## Validation

Minimum local validation after runtime changes:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/integration-smoke.php --site-root=/path/to/processwire
```

Also test real HTTP `301`, `302`, `307`, and `308` responses; disabled-link 404; GET/HEAD; query policy; bot filtering; counter increments; page and URL-segment precedence; PagePathHistory; installed redirect/API/security modules; browser CRUD; console errors; and cleanup of fixtures.

## Release Discipline

Runtime behavior changes require a module version decision and changelog entry. Work in the owned Cockpit repository first, validate, commit and push only with authorization, then synchronize released files into consuming sites separately. Never copy `.git`, tests, `AGENTS.md`, or other development-only files into a public document root.

## Rollback

For a bad release, disable the affected Cockpit links first, preserve both tables, restore the previous released module files, refresh ProcessWire modules, and re-run route tests. Uninstall preserves data unless Delete data on uninstall was explicitly enabled.
