# Ichiban Integration

## Verified Module

- Module: [Ichiban](https://github.com/mxmsmnv/Ichiban)
- Purpose: SEO control center for ProcessWire
- Author: Maxim Semenov
- Verified version: 0.3.0-alpha
- Requirements: ProcessWire 3.0.200+, PHP 8.1+, MySQL 5.7+ or MariaDB 10.3+
- License: declared MIT in the README and source; Ichiban should add a standalone `LICENSE` file before public release
- Research date: August 11, 2026

Ichiban already manages SEO redirects, automatic `301` responses after page-path changes, regex rules, `410`/`451` responses, hit counters, CSV import/export, sitemaps, robots.txt, llms.txt, Search Console, audits, and canonical URLs. Cockpit also intercepts public paths, so enabling both modules without coordination is unsafe. They need an explicit routing contract.

## Responsibility Boundary

| Scenario | Ichiban | Cockpit |
| --- | --- | --- |
| Previous page URL after a slug change | Owns the automatic SEO `301` | Does not create a duplicate |
| Permanent SEO URL migration | Owns `301`, regex, and import workflows | Links to Ichiban |
| Removed or legally unavailable URL | Owns `410` and `451` | Never overrides it |
| Campaign, QR, or printed short link | Not required | Owns stable paths and analytics |
| Temporary `302`/`307` routing | May support it | Owns campaign use cases |
| Canonical tags, sitemap, robots, Search Console | Owns the capability | Does not duplicate SEO features |
| Daily, weekly, and monthly short-link statistics | Not required | Owns the capability |

The integration does not merge database tables or turn Cockpit into an SEO module. It coordinates path ownership, exposes related read-only data, and provides explicit transfer operations.

## P0 — Shared Routing Contract

- [ ] Add optional `IchibanIntegration`, loaded only when Ichiban is available.
- [ ] Do not declare Ichiban as a hard Cockpit dependency; both modules must operate independently.
- [ ] Remove any dependency on autoload order or `ProcessPageView::execute` hook-registration order.
- [ ] Define precedence: ProcessWire core/admin/real page/URL segments/PagePathHistory → Ichiban utility endpoints → coordinated redirect owner.
- [ ] Always prioritize Ichiban `410` and `451`; Cockpit must not bypass legal or gone responses.
- [ ] Block ambiguous exact-path conflicts by default and require an administrator decision.
- [ ] Add a legacy-conflict policy: `block`, `ichiban_first`, or `cockpit_first`; default to `block`.
- [ ] Never permit `cockpit_first` for core/admin/pages, `410`/`451`, or protected utility paths.
- [ ] Evaluate Ichiban regex redirects only after exact claims and expose the matching pattern.
- [ ] Reserve `robots.txt`, `llms.txt`, the sitemap directory, and IndexNow key endpoints when enabled in Ichiban.
- [ ] Never increment either module's hit counter during conflict detection.
- [ ] Increment only the final route owner's counter after a successful redirect.
- [ ] Add a correlation/request ID to technical logs without recording personal data.
- [ ] Add a hookable route-decision object containing normalized path, owner, match type, status, target, and reason.
- [ ] Document one precedence table and show it consistently in both admin workspaces.

## P0 — Read-Only Ichiban Conflict API

The current `IchibanRedirectManager::match()` increments its hit counter, so Cockpit must not call it for previews or diagnostics.

- [ ] Add a public read-only Ichiban method such as `peekMatch()` or `findMatch($url, false)`.
- [ ] Define and version a stable result DTO/array shape.
- [ ] Add a fast exact lookup that does not scan regex rules.
- [ ] Add a bounded regex conflict scan with limits, guards, and reporting for unchecked rules.
- [ ] Never query `ichiban_redirects` directly from Cockpit.
- [ ] Never invoke protected or admin-only Ichiban methods.
- [ ] Use `getRedirectManager()` and documented hooks/services.
- [ ] Detect capabilities for older Ichiban versions lacking the new API.
- [ ] Treat conflict inspection as incomplete when no safe read-only API exists; do not auto-publish the path.

## P1 — CRUD and Import Checks

- [ ] Check Ichiban exact and regex claims before creating or updating a Cockpit link.
- [ ] Check Cockpit claims through its public read-only API before saving an Ichiban redirect.
- [ ] Add Cockpit `inspectPath()` or `claimForPath()` without statistics writes.
- [ ] Check cross-module conflicts during CSV/JSON import dry runs.
- [ ] Show owner, redirect type, target, note/source, and an edit link for every conflict.
- [ ] Add a bulk conflict scanner after either module is installed or updated.
- [ ] Detect intersections between Cockpit paths and Ichiban regex rules.
- [ ] Detect redirect loops and chains across both datasets.
- [ ] Limit chain length and show the complete path to a cycle.
- [ ] Detect self-redirects across scheme, host, query, slash, encoding, and case variations according to site policy.
- [ ] Never let a bulk operation silently replace a route owner.
- [ ] Export conflict reports without secret query parameters.

## P1 — Explicit Redirect Transfer

- [ ] Add “Transfer to Ichiban as SEO redirect” for users holding both permissions.
- [ ] Add “Create Cockpit campaign link” from an Ichiban redirect or landing page without modifying the source.
- [ ] Preview source, target, status, query policy, statistics, and consequences before every transfer.
- [ ] Use copy-then-disable rather than delete-first; deletion requires a separate confirmed action.
- [ ] Do not merge hit counts as if both modules measured traffic identically; preserve historical figures as separate notes or reports.
- [ ] Store provenance: source module, source ID, date, actor, and transfer reason.
- [ ] Never synchronize records bidirectionally in the background.
- [ ] Never create a duplicate in the other module after a normal save.
- [ ] Support rollback while the source record remains archived or available.
- [ ] Record transfers in both audit logs without target secrets.

## P1 — SEO and Sitemap

- [ ] Exclude Cockpit campaign paths from the Ichiban sitemap by default.
- [ ] Do not generate canonical, Open Graph, Schema.org, or indexable HTML for redirect-only paths.
- [ ] Add Cockpit path exclusions through public Ichiban settings/hooks with a preview.
- [ ] For a local ProcessWire target, show its Ichiban title, canonical, and audit status in a read-only preview.
- [ ] Never modify target-page SEO fields while editing a Cockpit link.
- [ ] Never submit campaign or short URLs to IndexNow as standalone pages.
- [ ] Associate Search Console data only with the canonical landing page; do not merge it with Cockpit statistics.
- [ ] Explain metric differences: Cockpit counts redirects while Search Console measures search data under its own rules.
- [ ] Warn when a permanent Cockpit redirect targets a non-canonical URL for a local page.
- [ ] Never place UTM parameters in an Ichiban canonical URL.

## P1 — Admin and Diagnostics

- [ ] Add an Ichiban integration card showing installation, version, API capabilities, and conflict policy.
- [ ] Link to Ichiban Redirects, Audit, and Sitemap through its supported admin URL helper.
- [ ] Add an Ichiban read-only Cockpit card for active campaign links and conflicts.
- [ ] Show route-owner badges: Page, PagePathHistory, Ichiban, or Cockpit.
- [ ] Add a shared conflict report without merging the primary CRUD workspaces.
- [ ] Add `cockpit integration:ichiban status` CLI diagnostics.
- [ ] Add `cockpit integration:ichiban conflicts --format=json` without mutations.
- [ ] Add a transfer dry run and a separate confirmed write command.
- [ ] Include integration state in diagnostic bundles while excluding OAuth tokens, Moz credentials, and URL secrets.

## Security and Reliability

- [ ] Test both module load orders.
- [ ] Test exact/exact, exact/regex, regex/exact, disabled, `301`/`302`/`307`/`308`/`410`/`451`, and query-string cases.
- [ ] Ensure broad or invalid Ichiban regex rules cannot capture admin, core, or protected Cockpit paths.
- [ ] Limit regex complexity and research ReDoS protection in Ichiban.
- [ ] Do not silently copy Cockpit host policies into Ichiban; show configuration differences.
- [ ] Validate external targets, CRLF, dangerous schemes, and chains at each module boundary.
- [ ] Prevent duplicate redirect responses, `Location` headers, session redirects, or exit paths.
- [ ] If one module's database fails, do not select the other owner without an explicit fail-closed/fail-open policy.
- [ ] Prevent cache poisoning by including host, normalized path, and route-owner version in cache keys.
- [ ] Never let statistics cleanup in one module modify the other's counters.
- [ ] Uninstalling either module must not remove the other's tables or settings.
- [ ] Add backup and restore tests for both redirect datasets.

## Versions and Releases

- [ ] Define the minimum compatible Ichiban version after its read-only route API exists.
- [ ] Mark the integration experimental while Ichiban remains alpha.
- [ ] Test Cockpit without Ichiban, with minimum/current versions, and with an unknown new major version.
- [ ] Add API contract tests and a route-precedence snapshot.
- [ ] Coordinate breaking changes through both changelogs.
- [ ] Do not announce official support before staging tests on a real ProcessWire site.

## Acceptance Criteria

- [ ] Every path has one deterministic owner regardless of module load order.
- [ ] Conflicts cannot be saved or imported without an explicit administrator decision.
- [ ] Previews and scans never increment hit counters.
- [ ] A public request is counted exactly once.
- [ ] Cross-module loops are detected before publication.
- [ ] Cockpit paths are absent from sitemap and IndexNow by default.
- [ ] Disabling or uninstalling Ichiban does not break Cockpit links, CLI, or statistics.
- [ ] Disabling or uninstalling Cockpit does not break Ichiban redirects, sitemap, or audit.
- [ ] The integration uses only public APIs/hooks and never reads another module's tables directly.
