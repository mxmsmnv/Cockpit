# Cockpit Examples

All examples assume Cockpit is installed. See `API.md` for the trust boundary and full method behavior.

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
