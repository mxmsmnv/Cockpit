# Security Policy

## Supported versions

Security fixes are provided for the latest released Cockpit minor version. Run the newest patch release before reporting a defect.

## Private reporting

Do not publish exploitable redirect, authorization, SSRF, injection, privacy, or data-loss issues in a public issue. Use GitHub private vulnerability reporting when it is enabled for the repository. Otherwise contact the repository owner privately through the address shown on the owner's GitHub profile and include `Cockpit security` in the subject.

Include the Cockpit, ProcessWire, PHP, and database versions; relevant configuration with secrets removed; reproduction steps; impact; and whether the issue is already being exploited. Do not include access tokens, cookies, private URLs, personal data, or a production database dump.

The initial response target is three business days. This is a response target, not a resolution guarantee. Coordinated disclosure timing depends on severity, reproducibility, and deployment impact.

## Security boundary

Cockpit validates public redirect targets but never fetches them during a redirect. Admin and CLI callers remain responsible for authorization and trusted execution. Static caches and reverse proxies can answer before ProcessWire; exclude the Cockpit namespace at every cache layer.

See [docs/THREAT-MODEL.md](docs/THREAT-MODEL.md) and [docs/PRODUCTION-CHECKLIST.md](docs/PRODUCTION-CHECKLIST.md).
