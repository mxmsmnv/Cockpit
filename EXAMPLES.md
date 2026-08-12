# Cockpit Examples

All examples assume Cockpit is installed. See [DOCUMENTATION.md](DOCUMENTATION.md) for setup and [API.md](API.md) for the trust boundary and full method behavior.

## Read A Link Safely

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('Cockpit')) {
	/** @var Cockpit $cockpit */
	$cockpit = $modules->get('Cockpit');
	$link = $cockpit->findLinkByPath('r/summer-campaign');
	if ($link && !empty($link['enabled'])) {
		echo $sanitizer->entities($cockpit->shortUrl($link['path']));
	}
}
```

## Admin-Only Create Action

```php
<?php namespace ProcessWire;

if (!$user->hasPermission('cockpit-manage')) throw new WirePermissionException();
$session->CSRF->validate();

/** @var Cockpit $cockpit */
$cockpit = $modules->get('Cockpit');
$id = $cockpit->saveLink([
	'path' => 'r/summer-campaign',
	'target_url' => 'https://example.com/landing',
	'redirect_status' => 302,
	'enabled' => true,
]);
```

Keep this logic in an authenticated ProcessWire action. Do not expose the raw service method to an anonymous template or API endpoint.

## Generated Path

```php
$id = $cockpit->saveLink([
	'path' => '',
	'target_url' => 'https://example.com/landing',
	'redirect_status' => 302,
	'enabled' => true,
]);
```

An empty path uses the configured default prefix and code length. Choose and verify a dedicated prefix before relying on generation.

## Read Statistics

```php
$daily = $cockpit->getStatistics('day');
$forOneLink = $cockpit->getStatistics('month', $id);
$totals = $cockpit->getDashboardTotals();
```

Statistics can reveal campaign activity. Apply authorization before rendering them outside the Cockpit admin page.

## Read Filtered Analytics

```php
$report = $cockpit->getAnalytics([
	'preset' => 'custom',
	'date_from' => '2026-08-01',
	'date_to' => '2026-08-31',
	'group' => 'day',
	'link_id' => $id,
	'state' => 'active',
	'status' => 302,
]);

$clicks = (int)$report['summary']['clicks'];
$buckets = $report['buckets'];
$previousBuckets = $report['previous_buckets'];
```

Custom ranges are inclusive and bounded. Treat analytics as permission-protected operational data.

## Inspect Route Ownership

```php
$inspection = $cockpit->inspectRoute('r/summer-campaign');
if (!$inspection->isConclusive() || $inspection->hasClaims()) {
	throw new WireException('The requested short-link path is not safely available.');
}
```

Do not use route inspection to force Cockpit ahead of an existing owner. It is a read-only decision aid.

## Export And Dry-Run An Import

```php
$json = $cockpit->exportLinks('json');
$plan = $cockpit->planLinkImport($json, 'json', false);

if (!$plan->isExecutable()) {
	$issues = $plan->issues();
}
```

Apply is a separate, explicitly authorized operation:

```php
$report = $cockpit->importLinks($json, 'json', true, false);
if (!$report->succeeded()) {
	throw new WireException('Cockpit import did not commit.');
}
```

Keep transfer operations in a trusted CLI or maintenance boundary. The ProcessCockpit admin does not currently expose import/export controls.

## Generate A QR Through The Optional Provider

```php
$provider = $cockpit->getCodeProvider('fieldtype-qrcode');
if ($provider && $provider->isAvailable()) {
	$result = $provider->generate($cockpit->shortUrl($link['path']), [
		'format' => 'svg',
		'recoveryLevel' => 'M',
	]);
	$svg = $result->content();
}
```

Generate codes only in an authorized admin or CLI workflow. Never invoke a code provider during a public redirect request.

## Hook Without A Hard Dependency

```php
<?php namespace ProcessWire;

$wire->addHookAfter('Pages::saved', function(HookEvent $event) {
	$page = $event->arguments(0);
	$modules = $event->wire('modules');
	if (!$modules->isInstalled('Cockpit')) return;

	/** @var Cockpit $cockpit */
	$cockpit = $modules->get('Cockpit');
	$link = $cockpit->findLinkByPath('r/page-' . (int)$page->id);
	// Read or deliberately mutate through the documented API and an authorized boundary.
});
```

## CLI Smoke Checks

```bash
php index.php --cockpit-list --cockpit-format=json
php index.php --cockpit-resolve --cockpit-path=r/example --cockpit-format=json
php index.php --cockpit-stats --cockpit-group=day --cockpit-format=json
php index.php --cockpit-help
```

Deletion always requires `--cockpit-force`.
