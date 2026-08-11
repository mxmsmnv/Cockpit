# Changelog

## Unreleased

## 1.1.5 — 2026-08-11

- Added a permission-aware Quick create block to the Dashboard with path, destination, redirect status, and active state.
- Kept Dashboard creation on the canonical CSRF, validation, route ownership, audit, and cache-purge path.
- Added an inline post-create result with copy, open, and edit actions while retaining entered values after validation errors.

## 1.1.4 — 2026-08-11

- Added active/disabled route health to the Dashboard link KPI.
- Expanded Top links into an operational overview with status, redirect code, last click, and a compact Analytics action.
- Kept the dashboard table responsive with bounded horizontal containment on narrow screens.

## 1.1.3 — 2026-08-11

- Fixed responsive canvas sizing so the Distribution donut remains circular instead of being rescaled from mismatched drawing dimensions.
- Balanced the four Analytics KPI cards across the full row and added an explicit selected-range summary.
- Added percentages alongside exact click counts in the traffic-share legend.

## 1.1.2 — 2026-08-11

- Replaced verbose Links table actions with compact edit, statistics, QR, copy, and delete icons.
- Added accessible labels, native tooltips, keyboard focus rings, and a restrained destructive hover state to every icon action.

## 1.1.1 — 2026-08-11

- Rebuilt the Cockpit workspace structure and visual rhythm from the owned Tickets module while retaining Cockpit's independent routing and analytics behavior.
- Replaced underlined tab navigation with compact horizontal pill navigation and a separate settings control.
- Added restrained page introductions, icon metrics, consistently sized insight panels, compact filter actions, and deliberate section spacing across Dashboard, Links, Analytics, and Audit.
- Fixed layout gaps that depended on an undefined design token and added cache-busted admin assets plus responsive light/dark/mobile regression coverage.
- Extended the managed demo fixture with self-cleaning, privacy-minimal audit events so every workspace view has representative local test data.

## 1.1.0 — 2026-08-11

- Rebuilt Analytics around quick periods and bounded custom date ranges with link, state, redirect-status, and automatic/day/week/month grouping filters.
- Added selected-period KPIs, previous-period comparison, daily average, peak bucket, and filtered top-link traffic share.
- Replaced the tall period-detail table with a compact horizontally scrollable bucket strip and reduced analytics chart height.
- Added read-only runtime coverage for range normalization, continuous buckets, comparisons, and filter boundaries.

## 1.0.9 — 2026-08-11

- Completed the optional FieldtypeQRCode integration with permission-gated QR preview and SVG download actions for canonical Cockpit short URLs.
- Added a native Integrations settings section with live compatibility status, provider settings guidance, and EPRC/Romain Cazier attribution.
- Added verified compatibility with the installed FieldtypeQRCode 1.1.4 positional generator API while retaining the richer 2.0.1–2.x options API and failing closed on untested versions.
- Added safe provider diagnostics to the public API and CLI, plus live provider contract and admin UI regression coverage.

## 1.0.8 — 2026-08-11

- Aligned the Cockpit admin workspace with `mxmsmnv/pw-design-system`: native `uk-tab` primary navigation, canonical workspace/head/stat/filter/table/empty-state composition, and responsive overflow behavior.
- Rebuilt the short-link editor with ProcessWire's Inputfield API, including native column widths, validation attributes, notes, icons, submit behavior, and CSRF rendering.
- Scoped all module CSS under the Cockpit workspace, removed local color-token aliases, and connected charts directly to the current `--pw-*` light/dark theme tokens.
- Replaced button-style pagination and plain empty messages with native UIkit pagination and actionable empty states.

## 1.0.7 — 2026-08-11

- Added view-aware Cockpit breadcrumbs, concise page headlines, and descriptive browser titles for Dashboard, Links, Analytics, Audit log, and link editing.
- Improved module settings chrome with a `Modules → Cockpit → Settings` trail, a clear `Cockpit settings` heading, and a direct workspace ancestor.

## 1.0.6 — 2026-08-11

- Reorganized module configuration into focused Links, Routing, Analytics, and Data & privacy sections using native ProcessWire Inputfields and responsive column widths.
- Added contextual workspace links, examples, notes, icons, current destination-policy status, privacy guidance, and an explicit warning for high-trust public-host mode.
- Isolated the uninstall deletion setting behind a collapsed destructive section with a permanent-data-loss warning.
- Opened Cockpit settings from the admin workspace with ProcessWire's module-information panel collapsed so configuration starts in view.

## 1.0.5 — 2026-08-11

- Added an idempotent, conflict-safe local demo fixture covering all redirect statuses, enabled and disabled campaigns, deterministic click history, preview/apply/remove modes, and aggregate/filter verification.
- Rebuilt the admin workspace around the same ProcessWire/AdminThemeUikit navigation and card patterns used by Ichiban.
- Split the workspace into permission-aware Overview, Links, Analytics, and Audit views while preserving filters, edit links, statistics deep links, and post-action redirects.
- Added responsive canvas line and donut charts with theme-aware colors, hover details, accessible labels, exact-value tables, and no external chart dependency.
- Reduced bespoke visual styling by using native UIkit cards, buttons, labels, tables, forms, progress bars, spacing, and theme variables.

## 1.0.4 — 2026-08-11

- Added granular admin permissions while preserving `cockpit-manage` as a backward-compatible umbrella, plus idempotent permission provisioning for upgrades.
- Added privacy-minimal administrative audit events for create, update, enable, disable, and delete, with schema version 3 and no stored target URL, query, IP, or user agent.
- Added bounded search, state/status/activity filters, 50-row pagination, and safe parameterized link queries.
- Added bounded, versioned CSV/JSON link backup and import with formula-injection protection, UTF-8/size limits, dry-run plans, explicit replacement, revalidation, race checks, transactional apply, and deferred post-commit cache purge.
- Added `CodeProviderInterface` and a capability-detected FieldtypeQRCode 2.x adapter with EPRC/Romain Cazier attribution, payload/options validation, raw SVG/GIF verification, and no hard dependency.
- Added deterministic installable ZIP, exact manifest, SHA-256 checksums, CycloneDX SBOM, static security checks, Dependabot configuration, and artifact-only GitHub Actions automation.
- Added CLI schema/config diagnostics and explicit dry-run/force legacy migration commands.
- Fixed invalid non-empty paths being replaced by an automatically generated path instead of rejected.

## 1.0.3 — 2026-08-11

- Added portable PHP 7.4–8.5 lint CI and self-cleaning ProcessWire/HTTP test runners with explicit fixture requirements.
- Added live HTTP regression coverage for every redirect status, disabled routes, HEAD and bot filtering, query forwarding, counters, and cleanup.
- Fixed query forwarding so ProcessWire's internal Apache rewrite parameter `it` is never leaked into the destination while client parameter order and duplicates are preserved.
- Added schema version 2 with idempotent install/upgrade/repair, an advisory lock, diagnostics, and a fail-closed cascading statistics foreign key.
- Replaced automatic raw ShortLinks copying with an explicit transactional dry-run/apply importer, bounded reports, row limits, full validation, and tested `code`/`path` schema support.
- Added a deterministic read-only route-claim registry for ProcessWire pages/history, Ichiban, ProcessRedirects, and common hook-only namespaces; save and CLI resolution now fail closed on ownership uncertainty.
- Added explicit no-store/CloudCache bypass headers for every redirect and optional post-mutation purge of old and new route paths.
- Added a threat model, production and rollback checklist, privacy statement, upgrade/downgrade policy, private security-reporting policy, and contribution guide.

## 1.0.2 — 2026-08-11

- Added one canonical path policy shared by saved links, local redirect-chain checks, and public request matching.
- Rejected percent and double encoding, encoded separators, dot segments, duplicate slashes, backslashes, whitespace, control bytes, Unicode confusables, and ambiguous dotted segments instead of silently rewriting them.
- Added dependency-free path-policy regression tests.

## 1.0.1 — 2026-08-11

- Fixed path validation so valid links can be created through the admin and CLI.
- Moved public redirect resolution to the late not-found phase so ProcessWire pages, security modules, API routes, PagePathHistory, Ichiban, and legacy redirects keep precedence.
- Fixed disabled and invalid links returning HTTP 500 instead of relinquishing the route to ProcessWire's normal 404 handling.
- Rejected private/reserved IP targets, URL userinfo, equivalent trailing-dot hosts, self-redirect variants, and active Cockpit redirect cycles.
- Made destination policy fail closed unless an allowlist is configured or arbitrary public HTTP(S) hosts are explicitly enabled.
- Added an optional canonical public base URL so CLI-generated links use the correct HTTPS origin outside a web request.
- Routed redirects through ProcessWire's `Session::redirect()` lifecycle so cache and infrastructure hooks can apply their normal safeguards.
- Added a self-cleaning ProcessWire integration smoke test for CRUD and security invariants.
- Added public API, known-good examples, and agent safety documentation.

- Added a prioritized project roadmap with production, quality, scaling, analytics, and public-release checklists.
- Expanded the roadmap with security, privacy, code-provider integrations, ethical QR-module compatibility, marketing, community, API, and ecosystem plans.
- Added a researched Kuar-inspired feature plan covering QR workflows, payload types, exports, web extension equivalents, additional code formats, and security boundaries.
- Identified FieldtypeQRCode by EPRC/Romain Cazier as the preferred QR provider and documented a non-competing optional integration based on its public API.
- Added an Ichiban integration plan covering deterministic route ownership, redirect conflicts, SEO boundaries, read-only APIs, migration, diagnostics, and cross-module tests.
- Converted the README, roadmap, and integration documentation to English and aligned the README structure with Ichiban.

## 1.0.0 — 2026-08-11

- Initial Cockpit release.
- Custom-path redirects with ProcessWire page and URL-segment conflict protection.
- Daily, weekly, and monthly click statistics.
- Flexible routing, destination, caching, tracking, and retention settings.
- Admin workspace and CLI management commands.
- Automatic import from legacy ShortLinks database tables.
