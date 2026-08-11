#!/usr/bin/env php
<?php namespace ProcessWire;

/**
 * Managed Cockpit demo data for an authorized local/disposable ProcessWire site.
 *
 * Preview (default): php tests/seed-demo.php --site-root=/path/to/processwire
 * Apply:             php tests/seed-demo.php --site-root=/path/to/processwire --apply
 * Remove:            php tests/seed-demo.php --site-root=/path/to/processwire --remove
 */

function cockpitDemoFail(string $message, int $exitCode = 1): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit($exitCode);
}

function cockpitDemoDefinitions(): array {
	return [
		['path' => 'cockpit-demo/summer-sale', 'target_url' => 'https://example.com/cockpit-demo/summer-sale', 'redirect_status' => 301, 'enabled' => true],
		['path' => 'cockpit-demo/qr/menu', 'target_url' => 'https://example.com/cockpit-demo/qr-menu', 'redirect_status' => 302, 'enabled' => true],
		['path' => 'cockpit-demo/social/instagram', 'target_url' => 'https://example.com/cockpit-demo/social-instagram', 'redirect_status' => 307, 'enabled' => true],
		['path' => 'cockpit-demo/events/wine-night', 'target_url' => 'https://example.com/cockpit-demo/wine-night', 'redirect_status' => 308, 'enabled' => true],
		['path' => 'cockpit-demo/email/august', 'target_url' => 'https://example.com/cockpit-demo/email-august', 'redirect_status' => 302, 'enabled' => true],
		['path' => 'cockpit-demo/print/catalog', 'target_url' => 'https://example.com/cockpit-demo/print-catalog', 'redirect_status' => 301, 'enabled' => true],
		['path' => 'cockpit-demo/paused-campaign', 'target_url' => 'https://example.com/cockpit-demo/paused-campaign', 'redirect_status' => 302, 'enabled' => false],
		['path' => 'cockpit-demo/expired-poster', 'target_url' => 'https://example.com/cockpit-demo/expired-poster', 'redirect_status' => 308, 'enabled' => false],
	];
}

/** @return array<string,int> ISO date => clicks */
function cockpitDemoHistory(int $linkIndex, int $days, \DateTimeImmutable $today): array {
	$history = [];
	for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
		$date = $today->modify("-{$daysAgo} days");
		$ageIndex = $days - $daysAgo;
		// Stable, varied campaigns: weekly troughs, recent lift, and intentional zero days.
		if ((($ageIndex + $linkIndex * 2) % 11) === 0) continue;
		$clicks = (($ageIndex * ($linkIndex + 3) + $linkIndex * 7) % 19) + 1;
		if ((int)$date->format('N') >= 6) $clicks = max(1, (int)floor($clicks * 0.55));
		if ($daysAgo < 14) $clicks += ($linkIndex + 1) * 2;
		if ($linkIndex >= 6 && $daysAgo < 45) continue; // paused/expired campaigns retain older history.
		$history[$date->format('Y-m-d')] = $clicks;
	}
	return $history;
}

function cockpitDemoJson(array $value): void {
	echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

$options = getopt('', ['site-root:', 'days:', 'apply', 'remove']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
$apply = array_key_exists('apply', $options);
$remove = array_key_exists('remove', $options);
if ($apply && $remove) cockpitDemoFail('Choose either --apply or --remove, not both.', 2);
if ($siteRoot === '' || !is_file($siteRoot . '/index.php')) {
	cockpitDemoFail('Usage: php tests/seed-demo.php --site-root=/path/to/processwire [--days=120] [--apply|--remove]', 2);
}
$days = isset($options['days']) ? (int)$options['days'] : 120;
if ($days < 30 || $days > 730) cockpitDemoFail('--days must be between 30 and 730.', 2);

chdir($siteRoot);
require $siteRoot . '/index.php';
if (!$wire->modules->isInstalled('Cockpit')) cockpitDemoFail('Cockpit is not installed in the target site.');
/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$database = $wire->database;
$prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string)$wire->config->dbPrefix);
$linksTable = '`' . $prefix . 'cockpit_links`';
$statsTable = '`' . $prefix . 'cockpit_daily_stats`';
$auditTable = '`' . $prefix . 'cockpit_audit_log`';
$auditLog = new CockpitAuditLog($cockpit);
$definitions = cockpitDemoDefinitions();
$timezone = new \DateTimeZone((string)$wire->config->timezone ?: date_default_timezone_get());
$today = new \DateTimeImmutable('today', $timezone);
$existing = [];
$conflicts = [];
$expectedBuckets = 0;
$expectedClicks = 0;

foreach ($definitions as $index => $definition) {
	$row = $cockpit->findLinkByPath($definition['path']);
	if ($row) {
		$existing[$definition['path']] = $row;
		if (!hash_equals($definition['target_url'], (string)$row['target_url'])) {
			$conflicts[] = [
				'path' => $definition['path'],
				'reason' => 'The exact path exists with a non-demo target and will not be overwritten.',
			];
		}
	}
	$history = cockpitDemoHistory($index, $days, $today);
	$expectedBuckets += count($history);
	$expectedClicks += array_sum($history);
}

$summary = [
	'mode' => $remove ? 'remove' : ($apply ? 'apply' : 'preview'),
	'site_root' => $siteRoot,
	'date_range' => [$today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $today->format('Y-m-d')],
	'demo_links' => count($definitions),
	'active_links' => count(array_filter($definitions, static fn(array $row): bool => $row['enabled'])),
	'existing_demo_links' => count($existing),
	'expected_stat_buckets' => $expectedBuckets,
	'expected_total_clicks' => $expectedClicks,
	'expected_audit_events' => count($definitions),
	'conflicts' => $conflicts,
];
if ($conflicts) {
	cockpitDemoJson($summary);
	cockpitDemoFail('Demo paths conflict with existing non-demo rows. No changes were made.');
}
if (!$apply && !$remove) {
	$summary['links'] = $definitions;
	cockpitDemoJson($summary);
	exit(0);
}

if ($remove) {
	$deleteAudit = $database->prepare('DELETE FROM ' . $auditTable . ' WHERE `path` LIKE :path');
	$deleteAudit->execute([':path' => 'cockpit-demo/%']);
	$removed = 0;
	foreach ($definitions as $definition) {
		$row = $cockpit->findLinkByPath($definition['path']);
		if (!$row) continue;
		if (!hash_equals($definition['target_url'], (string)$row['target_url'])) {
			cockpitDemoFail('Safety check failed while removing ' . $definition['path'] . '.');
		}
		$cockpit->deleteLink((int)$row['id']);
		$removed++;
	}
	$summary['removed_links'] = $removed;
	$summary['removed_audit_events'] = $deleteAudit->rowCount();
	$summary['remaining_demo_links'] = array_sum(array_map(
		static fn(array $definition): int => $cockpit->findLinkByPath($definition['path']) ? 1 : 0,
		$definitions
	));
	cockpitDemoJson($summary);
	exit(0);
}

if ($database->inTransaction()) cockpitDemoFail('Cannot seed demo data inside an existing database transaction.');
$database->beginTransaction();
try {
	$deleteAudit = $database->prepare('DELETE FROM ' . $auditTable . ' WHERE `path` LIKE :path');
	$deleteAudit->execute([':path' => 'cockpit-demo/%']);
	$linkIds = [];
	$actualBuckets = 0;
	$actualClicks = 0;
	foreach ($definitions as $index => $definition) {
		$current = $cockpit->findLinkByPath($definition['path']);
		$payload = $definition;
		if ($current) $payload['id'] = (int)$current['id'];
		$id = $cockpit->saveLink($payload, true);
		$linkIds[$definition['path']] = $id;

		$delete = $database->prepare('DELETE FROM ' . $statsTable . ' WHERE `link_id`=:link_id');
		$delete->execute([':link_id' => $id]);
		$insert = $database->prepare(
			'INSERT INTO ' . $statsTable . ' (`link_id`,`click_date`,`clicks`) VALUES (:link_id,:click_date,:clicks)'
		);
		$history = cockpitDemoHistory($index, $days, $today);
		foreach ($history as $date => $clicks) {
			$insert->execute([':link_id' => $id, ':click_date' => $date, ':clicks' => $clicks]);
		}
		$hits = array_sum($history);
		$lastDate = $history ? array_key_last($history) : null;
		$update = $database->prepare(
			'UPDATE ' . $linksTable . ' SET `hits`=:hits, `last_hit_at`=:last_hit_at WHERE `id`=:id'
		);
		$update->execute([
			':hits' => $hits,
			':last_hit_at' => $lastDate ? $lastDate . ' ' . sprintf('%02d:15:00', 9 + ($index % 8)) : null,
			':id' => $id,
		]);
		$audited = $cockpit->findLinkByPath($definition['path']);
		if (!$audited) throw new WireException('Unable to load seeded link for audit history.');
		$auditLog->recordLinkChange(null, $audited, 0);
		$actualBuckets += count($history);
		$actualClicks += $hits;
	}
	$database->commit();
	$summary['link_ids'] = $linkIds;
	$summary['actual_stat_buckets'] = $actualBuckets;
	$summary['actual_total_clicks'] = $actualClicks;
	$countAudit = $database->prepare('SELECT COUNT(*) FROM ' . $auditTable . ' WHERE `path` LIKE :path');
	$countAudit->execute([':path' => 'cockpit-demo/%']);
	$summary['actual_audit_events'] = (int)$countAudit->fetchColumn();
	$summary['dashboard'] = $cockpit->getDashboardTotals();
	$summary['filters'] = [
		'demo' => $cockpit->countLinks(['query' => 'cockpit-demo/']),
		'enabled' => $cockpit->countLinks(['query' => 'cockpit-demo/', 'enabled' => 1]),
		'disabled' => $cockpit->countLinks(['query' => 'cockpit-demo/', 'enabled' => 0]),
		'301' => $cockpit->countLinks(['query' => 'cockpit-demo/', 'redirect_status' => 301]),
		'302' => $cockpit->countLinks(['query' => 'cockpit-demo/', 'redirect_status' => 302]),
		'307' => $cockpit->countLinks(['query' => 'cockpit-demo/', 'redirect_status' => 307]),
		'308' => $cockpit->countLinks(['query' => 'cockpit-demo/', 'redirect_status' => 308]),
	];
	cockpitDemoJson($summary);
} catch (\Throwable $exception) {
	if ($database->inTransaction()) $database->rollBack();
	cockpitDemoFail($exception->getMessage());
}
