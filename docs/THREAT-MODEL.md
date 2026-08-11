# Threat Model

## Assets and trust boundaries

Cockpit protects route ownership, redirect destinations, link availability, aggregate counters, administrative actions, migration data, and configuration. Trust boundaries are the anonymous HTTP request, ProcessWire admin session, local CLI, database, legacy tables, optional modules, and infrastructure caches.

The public redirect handler accepts only GET and HEAD requests that reached ProcessWire's not-found pipeline. It does not fetch a destination, resolve remote DNS, store raw client IPs, or execute destination content.

## Principal threats and controls

| Threat | Control | Residual risk / operation |
| --- | --- | --- |
| Open redirect abuse | Fail-closed host allowlist unless arbitrary public hosts are explicitly enabled; HTTP(S) only | Review allowlist and existing links regularly |
| Header injection | Control-byte rejection, URL parsing, ProcessWire redirect lifecycle | Keep ProcessWire patched |
| SSRF/local-network targets | Private/reserved IP, numeric alias, localhost, `.local`, and userinfo rejection | Any future manual URL checker needs DNS pinning/revalidation and strict limits |
| Route takeover | Late not-found hook, page/history check, reserved namespaces | Third-party routers without inspection APIs can shadow a saved link; verify real HTTP ownership |
| Encoding ambiguity | One ASCII canonical path policy; reject percent encoding, dot segments, duplicate slash, backslash, Unicode | Proxy normalization must not rewrite rejected forms into valid routes |
| Redirect loops | Self and active Cockpit-chain detection | Cross-module chains require the route-claim registry |
| CSRF/parameter tampering | Process admin validates ProcessWire CSRF and typed inputs | Every future HTTP mutation must repeat authorization and CSRF checks |
| Stored/reflected XSS | ProcessWire entity escaping for admin output | Import/error reports must remain escaped |
| SQL injection | Prepared statements and fixed table identifiers | Schema/migration code must quote identifiers derived only from known module names |
| Statistics privacy | Aggregate daily counters; raw IPs are never stored | Optional advanced analytics require a separate opt-in privacy review |
| Cache bypass/staleness | ProcessWire redirect lifecycle and no-store for temporary responses | Static/CDN caching before PHP bypasses counters; exclude the namespace |
| Unauthorized local automation | CLI runs only in an authorized local execution context | Protect shell access and deployment credentials |
| Migration/data loss | Transaction, dry run, validation, backup, preserved legacy tables | Restore must be tested on an isolated copy |
| Resource exhaustion | Bounded path and URL sizes; aggregate writes | Rate limiting and high-volume load tests remain required before hostile public exposure |

## Logging rules

Cockpit logs operational errors and migration summaries only. Never log cookies, authorization headers, tokens, complete sensitive query strings, raw IP histories, personal data, or legacy row payloads. Redact user-supplied URLs in new diagnostic logging unless the administrator explicitly requested a protected export.

## Review triggers

Repeat the threat review before adding network URL checks, REST, webhooks, unique-visitor estimation, referrers/UTM, public write APIs, third-party code generators, cache acceleration, or new migration sources.
