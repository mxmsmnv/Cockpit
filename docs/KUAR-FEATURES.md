# Kuar-Inspired Feature Plan

Research source: [Kuar — QR code generator on the App Store](https://apps.apple.com/us/app/kuar-qr-code-generator/id1553562554?mt=12), reviewed August 11, 2026.

This document captures useful Kuar workflows that Cockpit may support. It does not claim compatibility, partnership, or affiliation with Kuar. Cockpit must not copy its code, design, text, name, or product identity. The goal is to implement comparable user capabilities with an independent ProcessWire web interface.

QR rendering is provided through the [FieldtypeQRCode adapter](FIELDTYPE-QRCODE-INTEGRATION.md). Kuar is a workflow reference, not a Cockpit dependency or generator.

## Verified Kuar Capabilities

The App Store description lists:

- QR generation at 1×, 16×, and 32× scales;
- black-on-white, white-on-black, black-on-transparent, and white-on-transparent styles;
- L, M, Q, and H error-correction levels;
- simultaneous work on multiple codes;
- drag-and-drop, PNG/SVG export, and clipboard copying;
- dedicated inputs for email, calls, SMS, Wi-Fi, geolocation, vCard, and iCal events;
- a Safari extension that encodes the current page;
- a Share Extension for selected text and vCards.

## P1 — Core Workflow Parity

- [ ] Add FieldtypeQRCode-powered generation to a Cockpit link editor and a dedicated generator screen.
- [ ] Add 1×, 16×, and 32× presets plus an exact custom size in pixels or millimeters.
- [ ] Add dark-on-light, light-on-dark, and both transparent-background variants.
- [ ] Permit custom colors only after automatic contrast validation.
- [ ] Support L/M/Q/H QR recovery levels with a clear density-versus-resilience explanation.
- [ ] Increase recovery automatically for logo overlays and warn when readability becomes unreliable.
- [ ] Export PNG and SVG, with PDF considered for print workflows.
- [ ] Copy PNG and SVG to the clipboard with a download fallback.
- [ ] Make previews draggable into applications that support browser drag-and-drop.
- [ ] Provide safe filenames, scale selection, and final-dimension previews.
- [ ] Allow multiple independent generator tabs without losing entered data.
- [ ] Store unfinished settings as local browser drafts and never send payloads to external services.

## P1 — Payload Types

- [ ] URL: Cockpit link, arbitrary URL, and destination preview.
- [ ] Text: plain text with payload-size and expected-density indicators.
- [ ] Email: address, subject, and body with correct percent encoding.
- [ ] Phone: normalized `tel:` URI without damaging extension numbers.
- [ ] SMS: number and body with device-aware `sms:`/`smsto:` behavior.
- [ ] Wi-Fi: SSID, security type, password, and hidden flag with correct escaping.
- [ ] Never store Wi-Fi passwords in analytics, audit logs, URLs, browser history, or server logs.
- [ ] Geolocation: latitude, longitude, and optional label using a standard `geo:` payload.
- [ ] Contact: vCard 3.0 and 4.0 with name, company, phone, email, address, and website.
- [ ] Calendar: iCalendar event with title, dates, timezone, location, description, and URL.
- [ ] Import `.vcf` and `.ics` locally with validation, size limits, and a preview.
- [ ] Show the final payload and a copy button for every content type.
- [ ] Add encode/decode round-trip tests with Unicode and special-character fixtures.

## P2 — Web Equivalents for Kuar Extensions

Native macOS extensions cannot be reproduced literally in a ProcessWire module. Cockpit should provide safe web equivalents instead.

- [ ] Add a “Create code for this page” bookmarklet that opens Cockpit with a prefilled URL.
- [ ] Protect bookmarklet input from XSS, open redirects, and unauthorized admin access.
- [ ] Research a minimal Safari/Chrome/Firefox extension only after the internal API is stable.
- [ ] Never require a browser extension for core Cockpit functionality.
- [ ] Add an authenticated “New code from shared content” admin endpoint with CSRF protection and a signed one-time token flow.
- [ ] Research PWA/Web Share Target support where the browser and ProcessWire deployment permit it.
- [ ] Add CLI generation from URL, text, vCard, or iCal payloads.
- [ ] Document that native Safari and macOS Share Extensions are outside the ProcessWire module.

## P2 — Beyond Kuar

- [ ] Support Micro QR, Aztec, Data Matrix, and PDF417 through `CodeProviderInterface`.
- [ ] Show supported payloads, length limits, and correction settings for each format.
- [ ] Recommend a format automatically without switching it without confirmation.
- [ ] Generate codes in batches from selected Cockpit links and export a ZIP.
- [ ] Add printable sheets with grids, margins, captions, and crop marks.
- [ ] Add named design presets at site, role, and campaign scope.
- [ ] Add dark/light previews, low-contrast simulation, and approximate print-size validation.
- [ ] Decode generated output before delivery and reject files that fail round-trip validation.
- [ ] Add a local camera test page that never uploads an image to an external service.
- [ ] Encode a stable Cockpit URL so destinations can change without reprinting material.

## Security and Privacy

- [ ] Generate locally on the server or in the browser; do not use an external generation API by default.
- [ ] Warn that QR, Aztec, and Data Matrix payloads may expose embedded secrets in plain text.
- [ ] Reject dangerous URI schemes and HTML/JavaScript payloads in URL mode.
- [ ] Sanitize SVG and send safe headers; reject scripts, external references, and event handlers.
- [ ] Limit payload size, complexity, batch count, runtime, and memory.
- [ ] Verify uploaded logo MIME signatures and dimensions, then decode/re-encode images before use.
- [ ] Never put payloads or secrets in filenames, error logs, or click statistics.
- [ ] Separate permissions for ordinary URLs, Wi-Fi, contacts, calendars, and batch exports.
- [ ] Record only non-secret audit metadata: actor, time, code type, and related Cockpit link.

## Acceptance Criteria

- [ ] Every verified Kuar workflow is mapped to a Cockpit feature or explicitly marked inapplicable to a web module.
- [ ] PNG and SVG output decodes with at least two independent decoder libraries.
- [ ] Current iOS and Android devices pass screen and print test matrices.
- [ ] Documentation includes examples and security warnings for Wi-Fi, vCard, and iCal.
- [ ] UI and marketing never imply an official relationship with Kuar.
- [ ] Generator, decoder, format, and patent licenses are documented in third-party notices.
