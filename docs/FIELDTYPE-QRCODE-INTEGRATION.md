# FieldtypeQRCode Integration

## Verified Module

- Module: [QR Code (FieldtypeQRCode)](https://processwire.com/modules/fieldtype-qrcode/)
- Author: EPRC / Romain Cazier
- Repository: [eprcstudio/FieldtypeQRCode](https://github.com/eprcstudio/FieldtypeQRCode)
- License: MIT
- Verified versions: 1.1.4 on the live ProcessWire fixture; 2.0.1 public API and adapter contract
- Generator library: [QR Code Generator by Kazuhiko Arase](https://github.com/kazuhikoarase/qrcode-generator/)
- Research date: August 11, 2026

FieldtypeQRCode already provides SVG/GIF output, module sizing, foreground and background colors, transparency, L/M/Q/H recovery levels, multiple sources, multilingual output, hooks, and the public static methods `generateQRCode()` and `generateRawQRCode()`.

## Product Boundary

Cockpit does not replace FieldtypeQRCode and does not bundle a copy of its generator.

| Capability | FieldtypeQRCode | Cockpit |
| --- | --- | --- |
| QR generation | Primary recommended provider | Sends payload through an adapter |
| ProcessWire QR fields | Owns the capability | Does not duplicate it |
| Field sources and multilingual output | Owns the capability | Links to its documentation |
| Custom and short redirect paths | Not required | Owns the capability |
| Campaign and click statistics | Not required | Owns the capability |
| Aztec, Data Matrix, and PDF417 | Not advertised | Separate providers outside this adapter |

The recommended setup uses FieldtypeQRCode to render a QR code and Cockpit to provide a stable short URL, editable destination, and aggregate click statistics.

## P1 — Provider Adapter

- [x] Add `FieldtypeQRCodeProvider` implementing `CodeProviderInterface` for QR only.
- [x] Detect the module through the ProcessWire Modules API without declaring a hard dependency.
- [x] Show the module name, EPRC attribution, directory/repository link, and provider settings action in Cockpit settings and QR output.
- [x] Offer FieldtypeQRCode as the recommended QR provider when installed and compatible, with an admin preview/download action on each link.
- [x] When missing, show installation guidance without downloading or installing anything automatically.
- [x] Never create or modify `FieldtypeQRCode` fields without an explicit administrator action.
- [x] Keep provider generation independent from destinations and encode the canonical Cockpit URL in the link QR action.
- [x] Use the documented `FieldtypeQRCode::generateRawQRCode()` method: positional arguments on 1.1.4 and an options array on 2.0.1+.
- [x] Map `svg`, `markup`, and `recoveryLevel` on all supported versions; map `size`, `foreground`, `background`, and `transparent` when the 2.0.1+ API advertises them.
- [x] Check installation, class, method, and tested major-version compatibility before calling the provider.
- [x] Never run QR generation during a public redirect request.
- [ ] Build cache keys from payload, format, design, provider version, and options; invalidate them when the link or preset changes.
- [x] Do not store generated code data in the primary click-statistics table.
- [x] Do not load FieldtypeQRCode CSS or JavaScript when only its static API is used.
- [x] Do not call internal or protected FieldtypeQRCode methods.
- [ ] Add a Cockpit hook for extending provider options without modifying the third-party module.

## Version Compatibility

- [x] Test the 2.0.1 options contract and validate the real installed 1.1.4 module through a live contract test.
- [x] Set the minimum adapter version to FieldtypeQRCode 1.1.4 and fail closed below it and on untested 3.x.
- [x] Keep 2.x-only syntax outside Cockpit so Cockpit still loads on PHP 7.4 when the provider is absent or incompatible.
- [x] Use capability detection in addition to the version range.
- [x] Add a read-only contract test against the real installed module in addition to dependency-free adapter tests.
- [ ] Test Cockpit without FieldtypeQRCode and with the minimum/current supported versions in CI.
- [x] Disable the integration safely for an unknown new major version until compatibility tests pass.
- [x] Include Cockpit, ProcessWire, PHP, and FieldtypeQRCode status/version data in existing module and CLI diagnostics.

## Integration Security

- [x] Validate payloads and enforce length limits before invoking the provider.
- [x] Validate CSS hex colors and accept only L/M/Q/H recovery levels.
- [x] Use raw output and return a typed Cockpit result for a future safe preview/download response.
- [x] Never insert provider SVG as raw HTML; previews use an escaped base64 data URI and escaped contextual text.
- [x] Inspect returned SVG for scripts, event handlers, `foreignObject`, active embeds, and external references.
- [x] Verify the expected SVG/GIF type, signature, base64 validity, and decoded-size limit.
- [ ] Limit batch size, execution time, memory, and concurrent generation.
- [ ] Never expose Wi-Fi passwords, vCard, or iCal contents in statistics, URLs, audit details, or error logs.
- [ ] Decode every generated result in a round-trip validation test.
- [ ] Never publish a partial or unverified file after a provider error.

## Maintainer Relationship and Licensing

- [ ] Before a public announcement, tell EPRC/Romain Cazier about the proposed integration and share this plan.
- [ ] Ask the maintainer to confirm the recommended API and supported version range.
- [ ] Do not require endorsement; respect any response, including no response.
- [x] Credit FieldtypeQRCode in documentation and the Cockpit integration panel.
- [ ] Do not use its name or logo in a way that implies an official partnership.
- [x] Do not bundle FieldtypeQRCode or QR Code Generator source files in the Cockpit ZIP.
- [ ] If bundling is ever considered, review MIT notices and discuss it with the maintainer first; the current plan does not bundle code.
- [ ] Direct QR-field questions to FieldtypeQRCode support and redirect/analytics questions to Cockpit support.
- [ ] Offer a reciprocal integration example while leaving the decision and wording to the maintainer.

## Acceptance Criteria

- [x] Cockpit validates all four recovery levels and generates a real QR for a short link through the installed provider.
- [x] SVG/GIF and documented provider options are mapped and output-validated; 1.1.4 correctly reports its reduced appearance capability.
- [x] The QR action encodes the canonical Cockpit URL, so changing a destination does not change the printed QR URL.
- [x] Provider absence/incompatibility degrades only QR generation; Cockpit links, CLI diagnostics, and statistics remain independent.
- [x] A missing or incompatible provider produces a diagnosable state rather than a PHP fatal error.
- [x] The Cockpit distribution contains no copied FieldtypeQRCode source code.
- [x] Documentation recommends FieldtypeQRCode for ProcessWire page-field QR functionality.
