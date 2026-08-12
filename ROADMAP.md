# Cockpit Roadmap

Cockpit 1.0.0 is the first release. This roadmap separates the stable release surface from possible future work; it is not proof that a feature is installed or configured on a consuming site.

## Included In 1.0.0

- [x] Custom and generated single-level or nested redirect paths.
- [x] `301`, `302`, `307`, and `308` redirects.
- [x] Dashboard, Links, Analytics, Audit, and per-link management workspaces.
- [x] Canonical fail-closed path and destination validation.
- [x] Route inspection for ProcessWire pages/history, Ichiban, ProcessRedirects, and common hook namespaces.
- [x] Destination allowlist, denylist, private-address rejection, and explicit public-host mode.
- [x] Redirect cycle detection and late not-found precedence.
- [x] Aggregate lifetime and daily analytics with bounded filters and previous-period comparison.
- [x] Bot, preview, HEAD, and exact-IP exclusion policy.
- [x] UTM Campaign URL Builder.
- [x] Granular permissions and privacy-minimal admin audit events.
- [x] Optional FieldtypeQRCode integration through its documented public API.
- [x] Bounded JSON/CSV transfer with dry-run, explicit replacement, transactional apply, and formula protection.
- [x] Explicit transactional ShortLinks migration.
- [x] CLI CRUD, statistics, route inspection, diagnostics, transfer, migration, and retention.
- [x] Schema lifecycle, referential integrity, table prefixes, and preserve-by-default uninstall.
- [x] CloudCache-compatible bypass and optional purge boundaries.
- [x] Portable tests, live fixture tests, PHP 7.4–8.5 CI, deterministic release artifacts, checksums, SBOM, and static security checks.
- [x] README, complete documentation, API, examples, Olivia/agent guidance, privacy, threat model, production checklist, upgrade policy, security policy, and MIT license.

## Production Validation

- [ ] Add automated MySQL 5.7 and MariaDB 10.3+ service matrices.
- [ ] Add automated ProcessWire minimum/current-version install, upgrade, and uninstall matrices.
- [ ] Validate dedicated non-superuser roles on production-derived staging.
- [ ] Load-test counter writes and large link collections.
- [ ] Test timezone and daylight-saving boundaries across representative configurations.
- [ ] Complete an independent security and accessibility review.
- [ ] Sign release tags and publish verified checksums with releases.

## Link Operations

- [ ] Add safe bulk enable, disable, status change, and delete workflows.
- [ ] Add a bounded admin import/export interface on top of the existing service.
- [ ] Add archive state without deleting statistics.
- [ ] Add tags, campaign ownership, internal notes, and approval workflow.
- [ ] Add link revision history and safe restoration.
- [ ] Add full-collection route conflict scans and dashboard reporting.
- [ ] Add destination availability checks only after SSRF, DNS-rebinding, timeout, redirect, and response-size policy is complete.

## Analytics

- [ ] Export aggregate analytics reports.
- [ ] Add opt-in aggregate UTM/source reports without visitor identity.
- [ ] Add high-volume aggregation and buffered-write strategies.
- [ ] Add optional Prometheus/OpenTelemetry hooks without a required dependency.
- [ ] Research privacy-preserving unique estimation without raw IP storage.

## Code And Print Workflows

- [ ] Add print templates, captions, alt text, and bounded batch export.
- [ ] Add provider-independent PNG/WebP output when supported safely.
- [ ] Research additional maintained providers for Data Matrix, PDF417, and Aztec.
- [ ] Keep FieldtypeQRCode as the recommended ProcessWire QR provider; never bundle or silently fork it.

## API And Ecosystem

- [ ] Add documented ProcessWire hooks for link create, update, delete, redirect, and thresholds.
- [ ] Stabilize hook contracts before considering REST.
- [ ] Require scoped expiring credentials, rate limits, audit, and replay protection for any future remote API or webhook.
- [ ] Publish Blueprint examples for campaign, print, social, and QR sites.
- [ ] Add Russian ProcessWire admin translations.

## Release

- [ ] Run a closed beta on production-derived staging.
- [ ] Publish a verified `v1.0.0` release artifact and checksum.
- [ ] Review ProcessWire Modules Directory requirements.
- [ ] Decide repository visibility and directory publication separately.
- [ ] Announce FieldtypeQRCode compatibility with neutral wording and maintainer attribution.
