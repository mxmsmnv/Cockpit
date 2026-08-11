# Cockpit Roadmap

Working roadmap for the Cockpit ProcessWire module. Priorities move from production readiness to release quality, scale, integrations, and a possible public launch.

## Product Principles

- [x] Build Cockpit as a link-management and analytics module, not a replacement for specialized QR modules.
- [x] Integrate with [FieldtypeQRCode](https://processwire.com/modules/fieldtype-qrcode/) through its public API and recommend it for ProcessWire QR-field workflows.
- [x] Credit EPRC/Romain Cazier and link to FieldtypeQRCode in documentation and integration UI.
- [ ] Never copy third-party code, UI, documentation, names, or marketing language without a compatible license and explicit permission where required.
- [x] Do not auto-migrate users away from FieldtypeQRCode or position Cockpit as its “killer.”
- [ ] Keep new data collection, network checks, and integrations disabled by default or explicitly documented.

## Implemented in 1.0.0

- [x] Arbitrary single-level and nested redirect paths.
- [x] `301`, `302`, `307`, and `308` redirects.
- [x] Admin CRUD, enable, and disable operations.
- [x] Automatic path generation.
- [x] Priority for ProcessWire pages, URL segments, and PagePathHistory.
- [x] Configurable reserved-path patterns for third-party routers.
- [x] Allowed and blocked destination hosts.
- [x] Daily, weekly, and monthly statistics.
- [x] Bot, HEAD-request, and excluded-IP filtering.
- [x] Configurable daily-statistics retention.
- [x] CLI for CRUD, statistics, path inspection, and pruning.
- [x] JSON CLI output.
- [x] Automatic import from preserved ShortLinks tables.
- [x] Private GitHub repository.
- [x] English README and integration documentation.
- [x] FieldtypeQRCode module, maintainer, license, and public API research.
- [x] Ichiban 0.3.0-alpha redirect, hook, sitemap, audit, Search Console, and service research.

## Implemented in 1.0.1

- [x] Fix path validation that prevented every admin and CLI create operation.
- [x] Resolve Cockpit links after pages, security modules, API/path hooks, PagePathHistory, Ichiban, and existing 404 redirect modules.
- [x] Return the normal ProcessWire 404 for disabled or runtime-invalid links instead of an uncaught `Wire404Exception`/HTTP 500.
- [x] Reject URL userinfo, private/reserved IP literals, numeric IP aliases, localhost targets, trailing-dot denylist bypasses, self redirects, and active Cockpit cycles.
- [x] Fail closed when no destination allowlist exists unless arbitrary public HTTP(S) hosts are explicitly enabled.
- [x] Add an optional canonical public base URL for correct CLI/copy output when ProcessWire cannot infer HTTPS from a web request.
- [x] Use ProcessWire's `Session::redirect()` lifecycle so CloudCache and other infrastructure hooks can apply redirect safeguards.
- [x] Add a self-cleaning ProcessWire integration smoke test under `tests/`.
- [x] Add `API.md`, `EXAMPLES.md`, and `AGENTS.md` with explicit permission, routing, cache, validation, and rollback boundaries.
- [x] Validate admin CRUD, automatic path generation, CLI CRUD, `301`/`302`/`307`/`308`, GET/HEAD, bot filtering, query policy, counters, disable, delete, and browser console state on ProcessWire 3.0.255/PHP 8.5/MySQL 8.0.
- [x] Validate live precedence against LQRS pages, URL segments, PagePathHistory, Ichiban, ProcessRedirects, AppApi, Compass, and WireWall's security boundary.

## Implemented in 1.0.2

- [x] Use one fail-closed canonical path policy for stored links, public requests, and local redirect-chain inspection.
- [x] Reject percent/double encoding, encoded separators, dot segments, duplicate slashes, backslashes, whitespace, control bytes, Unicode confusables, and ambiguous dotted segments.
- [x] Add dependency-free path-policy regression tests.

## Implemented in 1.0.3

- [x] Add a portable test runner, PHP 7.4–8.5 lint CI matrix, and strict optional ProcessWire/HTTP fixture mode.
- [x] Exercise `301`, `302`, `307`, `308`, disabled `404`, GET/HEAD, bot filtering, query forwarding, counters, and fixture cleanup over real HTTP.
- [x] Prevent ProcessWire's internal `it` rewrite parameter from leaking through query forwarding.
- [x] Add the threat model, production/rollback checklist, privacy statement, upgrade policy, security-reporting policy, and contribution guide.
- [x] Add schema version 2, idempotent install/upgrade/repair, diagnostics, referential integrity, and explicit transactional legacy import.
- [x] Add deterministic route claims for ProcessWire, Ichiban, ProcessRedirects, and common hook-only namespaces.
- [x] Add full redirect cache-bypass headers plus optional CloudCache purge for route mutations.

## Implemented in 1.0.4

- [x] Add backward-compatible granular permissions, server-side capability checks, privacy-minimal audit events, search/filter/pagination, and schema version 3.
- [x] Add bounded versioned CSV/JSON backup and transactional import with formula protection, dry-run plans, explicit replacement, revalidation, and race checks.
- [x] Add generator-independent code contracts and a capability-detected, validated FieldtypeQRCode 1.1.4–2.x SVG/GIF adapter without bundling provider code.
- [x] Add reproducible release ZIPs, exact manifests, SHA-256 checksums, CycloneDX SBOMs, static security checks, Dependabot, and artifact-only automation.
- [x] Add CLI schema/config diagnostics plus explicit dry-run/force legacy import.

## P0 — Production Readiness

- [x] Move tests into the repository `tests/` directory.
- [x] Test a clean install on a real ProcessWire 3.0.200+ instance (3.0.255 validated on `lqrs.dev`).
- [ ] Test MySQL 5.7 and MariaDB 10.3+.
- [ ] Verify PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5 compatibility.
- [ ] Test update, disable, and uninstall flows without data loss.
- [ ] Test a production-derived ShortLinks database migration (both fixture schemas and rollback are automated).
- [ ] Back up legacy tables before the first production migration (procedure documented; production backup remains an operator action).
- [ ] Install on a staging copy of `modenza.org` and run smoke tests.
- [x] Test conflicts with real pages, URL segments, PagePathHistory, and other module routes on `lqrs.dev`.
- [ ] Configure exclusions in CDN, reverse proxy, nginx, and/or ProCache.
- [ ] Verify granular permissions with dedicated real non-superuser roles (policy and synthetic-user integration tests pass).
- [ ] Test site timezone and daily/weekly boundary behavior.
- [x] Write a threat model for public redirects, admin, CLI, import, statistics, cron, and integrations.
- [x] Define secure defaults and a production checklist.
- [x] Add an emergency redirect-disable procedure that preserves links and statistics.
- [ ] Test database restoration on an isolated instance.
- [x] Add portable HTTP integration tests for redirect responses, request filtering, query policy, and counters; route/cache integration expands with the registry adapters.
- [x] Add a read-only route-claim registry so admin/CLI diagnostics can identify links shadowed by other redirect modules before save.
- [x] Add explicit CloudCache bypass and purge integration before enabling any Cockpit path in L1, L2, or edge cache.

## P1 — Security and Privacy

- [x] Prevent open redirects by default through an explicit destination-host policy.
- [x] Normalize paths and block encoding, double-encoding, Unicode, slash, dot-segment, case, and trailing-slash bypasses.
- [x] Reject dangerous schemes, CRLF, and `Location` header manipulation.
- [x] Protect current forms/import plans from CSRF, XSS, SQL injection, and parameter tampering; future bulk/admin import UI requires the same boundary.
- [x] Separate permissions for statistics, links, import/export, settings, and audit while keeping the legacy umbrella.
- [ ] Require confirmation for deletion, bulk changes, replacement imports, and statistics cleanup.
- [x] Make import transactional with dry run, row validation, limits, error reports, and rollback.
- [x] Protect CSV output from spreadsheet formula injection.
- [ ] Never check a destination URL from the public redirect request.
- [ ] Protect manual URL checks from SSRF, localhost/private/link-local access, metadata endpoints, DNS rebinding, and unexpected chains.
- [ ] Add timeouts, response-size limits, and redirect-count limits to network checks.
- [ ] Trust `X-Forwarded-For` only from configured proxies.
- [ ] For private uniqueness, use rotating HMAC, never store raw IPs, and allow identification to be disabled.
- [ ] Add rate limits for public traffic, admin actions, API calls, and CLI jobs.
- [ ] Test concurrency, unique paths, and create/update race conditions.
- [x] Exclude secrets, tokens, sensitive query strings, and personal data from Cockpit logs and document the rule for new diagnostics.
- [ ] Add retention, deletion, export, and anonymization controls for GDPR/CCPA and local rules.
- [ ] Respect DNT/Global Privacy Control for optional advanced analytics.
- [x] Add a security policy, private reporting channel, and initial-response target.
- [ ] Configure dependency updates, vulnerability scanning, and release SBOMs (Dependabot, static scan, and deterministic SBOM implemented; third-party scanner remains).
- [ ] Sign release tags/artifacts and publish ZIP checksums.
- [ ] Complete an independent security review before public release.

## P1 — Release Quality and Automation

- [x] Add GitHub Actions for portable PHP lint and test entry points; fixture-backed CLI/settings/HTTP jobs remain part of the database matrix.
- [ ] Add a CI matrix for supported PHP, ProcessWire, MySQL, and MariaDB versions.
- [x] Build reproducible installable ZIP files automatically without `.git`, CI, tests, scripts, agent files, or temporary files.
- [ ] Create a `v1.0.0` tag and private GitHub Release with a verified ZIP.
- [ ] Add automated install/update/uninstall smoke tests.
- [x] Test both ShortLinks schemas: `code` and `path`.
- [ ] Review redirects, headers, CSRF, and CLI security independently.
- [x] Publish an English README.
- [ ] Add Russian admin-interface localization through ProcessWire translations.
- [x] Document statistics privacy and data that Cockpit never stores.
- [x] Add an admin audit log for create, update, enable, disable, and delete actions.
- [x] Add CSV/JSON link import and export services for backups; admin upload/download controls remain separate.
- [ ] Add PHPStan or Psalm and an enforced code style.
- [ ] Add property/fuzz tests for path normalization and URL validation.
- [ ] Test IDN, IPv6, internationalized paths, and multibyte input.
- [ ] Test minimum and maximum supported ProcessWire versions in CI.
- [x] Write upgrade guidance, downgrade limitations, and a deprecation policy.
- [x] Track database schema versions with idempotent migrations.
- [ ] Test keyboard and screen-reader accessibility.
- [ ] Remove hard-coded UI strings and use ProcessWire translation APIs.

## P1 — Code Generation Integrations

- [ ] Complete the independent [Kuar-inspired feature plan](docs/KUAR-FEATURES.md).
- [ ] Complete the [FieldtypeQRCode integration plan](docs/FIELDTYPE-QRCODE-INTEGRATION.md).
- [ ] Contact EPRC/Romain Cazier about the recommended API, neutral wording, and attribution.
- [x] Add `CodeProviderInterface` so Cockpit is generator-independent.
- [x] Prefer FieldtypeQRCode for QR when installed and compatible.
- [x] Never bundle FieldtypeQRCode; call only documented public methods.
- [x] Link to installation and settings without installing anything automatically.
- [x] Do not ship a built-in QR engine by default; Cockpit manages the URL while FieldtypeQRCode generates QR.
- [x] Support standard QR through FieldtypeQRCode; Micro QR remains a separate future provider.
- [ ] Add Aztec, Data Matrix, and PDF417 through a maintained compatible library.
- [ ] Research MaxiCode and rMQR only for validated use cases.
- [ ] Research Code 128, EAN-13, and UPC separately from URL-code workflows.
- [x] Export verified SVG on demand without storing generated copies; PNG/WebP remain future formats.
- [ ] Add transparency, colors, and safe logo placement with readability warnings.
- [ ] Recommend density from URL length and suggest a shorter Cockpit path for complex payloads.
- [ ] Add captions, alt text, original-link copying, and print templates.
- [ ] Add bounded batch generation and safe ZIP exports.
- [ ] Add URL, Wi-Fi, vCard, email, phone, and plain-text payloads where they fit Cockpit's scope.
- [ ] Add iCalendar, geolocation, and safe vCard/iCal import.
- [ ] Add Kuar-inspired scale presets, four core color styles, clipboard, and browser drag-and-drop.
- [ ] Research bookmarklet, browser extension, and Web Share Target equivalents.
- [ ] Test current iOS/Android devices, sizes, screens, and printed material.
- [ ] Decode every supported format in automated round-trip tests.
- [ ] Store design settings separately so codes can be regenerated without changing URLs.
- [ ] Count statistics only after a request reaches a Cockpit URL, not when a code is generated.
- [ ] Add campaign UTM templates without embedding confidential data.
- [ ] Document patent, license, and trademark constraints for formats and libraries.

## P1 — Ichiban Integration

- [ ] Complete the [Ichiban integration plan](docs/ICHIBAN-INTEGRATION.md).
- [x] Add optional integration without a hard dependency.
- [x] Remove route ownership dependence on `ProcessPageView::execute` hook order.
- [x] Add read-only Cockpit-side inspection of Ichiban rules without hit-counter writes; upstream Ichiban API remains separate.
- [ ] Block exact/regex conflicts, loops, and chains before publication.
- [ ] Keep SEO redirects and `410`/`451` in Ichiban; campaign/QR links and analytics in Cockpit.
- [ ] Exclude Cockpit campaign paths from Ichiban sitemap and IndexNow.
- [ ] Add a diagnostic screen (JSON CLI resolve now includes the full conflict scan).
- [ ] Add explicit confirmed transfer without background bidirectional synchronization.
- [ ] Test both module load orders and prevent duplicate redirects or hits.
- [ ] Keep the integration experimental while Ichiban is alpha.

## P2 — Large Link Collections

- [x] Add search and filters for path, target, status, and activity.
- [x] Paginate the link list.
- [ ] Add bulk enable, disable, status change, and deletion.
- [ ] Add safe bulk creation UI from CSV/JSON (bounded transactional service implemented).
- [ ] Scan all links for new site-route conflicts.
- [ ] Show conflicting links on a dashboard card.
- [ ] Add manually triggered destination availability checks.
- [ ] Archive links without deleting statistics.
- [ ] Add tags, campaigns, owners, and internal notes.
- [ ] Add drafts and two-step approval for critical links.
- [ ] Detect duplicate targets and suggest existing links.
- [ ] Add link revisions and safe restoration.
- [ ] Detect or lock conflicting concurrent edits.

## P2 — Analytics and Performance

- [x] Align Dashboard, Links, Analytics, and Audit with the Tickets workspace structure and `pw-design-system` tokens across light, dark, and mobile layouts.
- [x] Add bounded custom date ranges with quick periods and automatic/day/week/month grouping.
- [x] Compare the selected period with the immediately preceding equivalent period.
- [ ] Export analytics reports.
- [ ] Optionally collect referrer and UTM without personal data.
- [ ] Research private unique-click estimation without raw IP storage.
- [ ] Load-test statistics writes at high traffic.
- [ ] Optimize admin lists and aggregate queries for large tables.
- [ ] Show LazyCron cleanup state and last-run time.
- [ ] Add CLI diagnostics for configuration, tables, routes, and permissions (schema/config/routes implemented; permission report remains).
- [ ] Add campaign sources and opt-in UTM reports.
- [ ] Add a created → published → clicked funnel without off-site tracking.
- [ ] Detect traffic spikes, bots, path enumeration, and suspicious targets.
- [ ] Add campaign goals and timeline notes without vanity metrics.
- [ ] Plan old-data aggregation, partitioning, and non-blocking deletion.
- [ ] Add optional queued/buffered writes for high-traffic sites.
- [ ] Add Prometheus/OpenTelemetry hooks without a required external dependency.

## P2 — API and Ecosystem

- [ ] Add ProcessWire hooks/events for create, update, redirect, and threshold events.
- [ ] Stabilize the PHP API and provider interfaces before designing REST.
- [ ] Add scoped, expiring, rotating tokens, rate limits, and usage logs to REST.
- [ ] Add signed webhooks with replay protection, retries, and dead-letter logging.
- [ ] Publish integration examples without requiring a specific CRM or analytics vendor.

## Marketing and Community

- [ ] Position Cockpit as “redirects, routes, and private analytics for ProcessWire,” never as a module killer.
- [ ] Publish an Integrations page presenting FieldtypeQRCode as recommended compatibility without implying partnership.
- [ ] Credit EPRC/Romain Cazier and obtain approval before using the FieldtypeQRCode name in advertising.
- [ ] Do not buy ads for another module's name or publish misleading comparison SEO pages.
- [ ] Publish an honest capability table showing when to use Cockpit, FieldtypeQRCode, or both.
- [ ] Produce a short demo covering creation, conflict checks, statistics, and provider-based code generation.
- [ ] Add screenshots, GIFs, a sample campaign, a routing diagram, and a privacy-safe public demo.
- [ ] Publish a landing page, documentation, FAQ, troubleshooting, and installation guides in English and Russian.
- [ ] Create SEO pages only for real use cases: ProcessWire short links, campaign links, redirect analytics, and QR integration.
- [ ] Announce a beta on the ProcessWire Forum before directory submission.
- [ ] Offer EPRC/Romain Cazier a reciprocal link or joint example while leaving the decision to the maintainer.
- [ ] Recruit 5–10 beta users with defined pilot scenarios and structured feedback.
- [ ] Consider anonymous opt-in install counts only after a separate decision; collect no telemetry by default.
- [ ] Publish roadmap, changelog, known issues, and security advisories transparently.
- [ ] Adopt semantic versioning and a release cadence; promise LTS only if it can be maintained.
- [ ] Define support through GitHub Issues, ProcessWire Forum, paid setup/customization, and response expectations.
- [ ] Add CONTRIBUTING, Code of Conduct, issue templates, and contribution-credit rules.
- [ ] Measure useful project outcomes without personal tracking.
- [ ] Optimize marketing for reliable links and owner value, not generated-code volume.
- [ ] Describe Ichiban + Cockpit as complementary SEO and campaign-link control centers.

## Possible Public Release

- [ ] Decide whether to keep the Cockpit name given Cockpit CMS and the Cockpit server panel.
- [ ] Create a unique icon and visual identity.
- [ ] Add admin and statistics screenshots.
- [ ] Document versioning, support, and update procedures.
- [x] Prepare public English documentation.
- [ ] Review ProcessWire module-directory requirements.
- [ ] Change repository visibility only after a separate decision.
- [ ] Publish in the ProcessWire Modules directory.
- [ ] Check package, namespace, CSS class, route, and CLI-command naming conflicts.
- [ ] Verify redistribution licenses and include required third-party notices.
- [ ] Define a minimal stable public API and backward-compatibility commitments.
- [ ] Run a closed beta and release candidate before stable publication.
- [ ] Announce FieldtypeQRCode support only after current-version tests and, where possible, maintainer coordination.

## Ideas Outside the Current Scope

- [ ] Link expiration, activation schedules, and click limits.
- [ ] A/B destination distribution.
- [ ] Geographic, language, and device-aware redirects after privacy/security review.
- [ ] Signed one-time expiring links as a separate security-sensitive mode.
- [ ] Branded domains and multiple domains per ProcessWire installation.
