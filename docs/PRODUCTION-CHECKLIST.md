# Production Checklist

## Before deployment

- Back up the ProcessWire database and verify the backup can be restored to an isolated database.
- Record Cockpit, ProcessWire, PHP, database, Ichiban/redirect, firewall, and cache versions.
- Use a dedicated path prefix and scan it against pages, URL segments, PagePathHistory, API hooks, Ichiban, ProcessRedirects, and custom routers.
- Configure explicit reserved namespaces and an allowlist of destination hosts. Avoid the arbitrary-public-host option in production.
- Set the canonical public base URL and confirm HTTPS output from CLI.
- Keep Delete data on uninstall disabled.
- Grant Cockpit permissions only to the roles that need them; test with a non-superuser account.
- Exclude the full Cockpit namespace from browser/page caches, ProCache, nginx/FastCGI cache, reverse proxy, and CDN/edge rules.

## Validation

Run PHP lint, unit tests, ProcessWire integration smoke, and HTTP tests. Verify all four statuses without following redirects, disabled links returning the normal 404, GET/HEAD, bot filtering, query forwarding policy, counter behavior, page/history/third-party precedence, timezone boundaries, admin CRUD, CSRF failure, permission denial, and browser console state.

After deployment, create one temporary allowlisted link, test it externally, confirm exactly one expected counter increment, disable it, confirm 404, and delete it. Inspect ProcessWire and Cockpit logs for new errors.

## Emergency disable

To stop redirects while preserving links and statistics, disable the Cockpit module in ProcessWire or disable affected rows. Do not uninstall with Delete data on uninstall enabled. If the admin is unavailable, use the trusted local CLI to list and disable the affected link IDs. Purge any upstream cached redirect after disabling it.

## Rollback

1. Disable affected routes and purge upstream caches.
2. Preserve `cockpit_links`, `cockpit_daily_stats`, and Cockpit schema metadata.
3. Restore the previous released module files.
4. Refresh ProcessWire module metadata; do not run a destructive downgrade migration.
5. Verify table compatibility and run read-only diagnostics and HTTP precedence checks.
6. Restore the database backup only on an isolated copy first; compare counts and checksums before a production restore.

See `UPGRADING.md` for downgrade limitations.
