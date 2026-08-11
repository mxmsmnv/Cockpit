# Contributing

## Before opening a change

Use an issue to describe substantial API, schema, route-ownership, analytics, privacy, or provider changes before implementation. Security reports belong in the private channel described in `SECURITY.md`.

Keep Cockpit focused on campaign and memorable redirects with aggregate private analytics. SEO redirect ownership belongs in Ichiban or another SEO redirect manager. QR generation should use provider interfaces and FieldtypeQRCode when available; do not copy third-party implementations or branding.

## Development checks

Use PHP 7.4-compatible syntax and preserve ProcessWire 3.0.200 compatibility.

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/path-policy.php
php tests/integration-smoke.php --site-root=/path/to/processwire
```

Runtime changes need a changelog entry, a module version decision, focused tests, and browser/HTTP validation where applicable. Do not include development-only files in an installable release ZIP.

## Pull requests

Keep changes focused, document schema and public API effects, include rollback instructions for migrations, and state which database/PHP/ProcessWire combinations were actually tested. Contributions are credited through Git history and release notes. By contributing, you agree that your contribution is distributed under the repository license.
