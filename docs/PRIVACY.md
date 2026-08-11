# Statistics Privacy

Cockpit's default analytics store a lifetime counter on each link and one aggregate click count per link per calendar day in the site's configured timezone.

Cockpit does not store raw IP addresses, user agents, cookies, device identifiers, referrers, destination responses, geographic data, or cross-site identifiers. Excluded IPs are compared during the request and are not written to the Cockpit tables. Known-bot and HEAD filtering use request data transiently.

Administrators can disable statistics without disabling redirects and can set daily-bucket retention. Retention removes daily buckets but preserves the lifetime total. Deleting a link removes its Cockpit daily buckets. Database backups and external logs have their own retention policies and must be handled separately.

Any future referrer, UTM, uniqueness, geography, or device analytics must be opt-in, data-minimized, documented, exportable/deletable, and reviewed for DNT/GPC and applicable law before release. Cockpit provides no legal compliance guarantee; the site operator remains responsible for notices, lawful basis, access, deletion, and backup retention.
