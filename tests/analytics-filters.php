#!/usr/bin/env php
<?php namespace ProcessWire;

/** Read-only runtime coverage for bounded analytics filters and comparisons. */

function cockpitAnalyticsFail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function cockpitAnalyticsAssert(bool $condition, string $message): void {
	if (!$condition) cockpitAnalyticsFail($message);
}

$options = getopt('', ['site-root:']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
if ($siteRoot === '' || !is_file($siteRoot . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/analytics-filters.php --site-root=/path/to/processwire\n");
	exit(2);
}

chdir($siteRoot);
require $siteRoot . '/index.php';
if (!$wire->modules->isInstalled('Cockpit')) cockpitAnalyticsFail('Cockpit is not installed.');
/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');

$sevenDays = $cockpit->getAnalytics(['preset' => '7d']);
cockpitAnalyticsAssert($sevenDays['filters']['resolved_group'] === 'day', 'Seven-day automatic grouping is not daily.');
cockpitAnalyticsAssert(count($sevenDays['buckets']) === 7, 'Seven-day preset is not continuous.');
cockpitAnalyticsAssert((int)$sevenDays['summary']['days'] === 7, 'Seven-day summary has the wrong duration.');
cockpitAnalyticsAssert(array_key_exists('previous_clicks', $sevenDays['summary']), 'Previous-period comparison is missing.');

$weekly = $cockpit->getAnalytics(['preset' => '90d', 'group' => 'week']);
cockpitAnalyticsAssert($weekly['filters']['resolved_group'] === 'week', 'Explicit weekly grouping was not preserved.');
cockpitAnalyticsAssert(count($weekly['buckets']) >= 13 && count($weekly['buckets']) <= 14, 'Ninety-day weekly buckets are not bounded.');

$bounded = $cockpit->getAnalytics([
	'preset' => 'custom',
	'date_from' => '2020-01-01',
	'date_to' => date('Y-m-d'),
	'state' => 'invalid',
	'status' => 999,
]);
cockpitAnalyticsAssert((int)$bounded['summary']['days'] === 366, 'Custom analytics range was not capped at 366 days.');
cockpitAnalyticsAssert($bounded['filters']['state'] === 'all' && (int)$bounded['filters']['status'] === 0, 'Invalid link filters did not fail safely.');
$future = $cockpit->getAnalytics(['preset' => 'custom', 'date_from' => '2099-02-01', 'date_to' => '2099-01-01']);
cockpitAnalyticsAssert($future['filters']['date_from'] === date('Y-m-d') && $future['filters']['date_to'] === date('Y-m-d'), 'Future custom range was not clamped to today.');

$links = $cockpit->findLinks(['limit' => 1]);
if ($links) {
	$linkId = (int)$links[0]['id'];
	$selected = $cockpit->getAnalytics(['preset' => '30d', 'link_id' => $linkId]);
	cockpitAnalyticsAssert((int)$selected['filters']['link_id'] === $linkId, 'Existing link filter was not retained.');
}

echo json_encode([
	'ok' => true,
	'tests' => ['quick_period', 'continuous_buckets', 'previous_period', 'weekly_grouping', 'custom_range_cap', 'future_range_clamp', 'safe_filter_normalization', 'link_filter'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
