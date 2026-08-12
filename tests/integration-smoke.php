#!/usr/bin/env php
<?php namespace ProcessWire;

/**
 * Destructive-but-self-cleaning Cockpit integration smoke test.
 *
 * Run against a disposable ProcessWire installation where Cockpit is installed:
 * php tests/integration-smoke.php --site-root=/path/to/processwire
 */

function cockpitTestFail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function cockpitTestAssert(bool $condition, string $message): void {
	if (!$condition) cockpitTestFail($message);
}

function cockpitTestRejects(callable $operation, string $message): void {
	try {
		$operation();
	} catch (\Throwable $exception) {
		return;
	}
	cockpitTestFail($message);
}

function cockpitTestCallProtected(object $object, string $method, array $arguments = []) {
	$reflection = new \ReflectionMethod($object, $method);
	$reflection->setAccessible(true);
	return $reflection->invokeArgs($object, $arguments);
}

$options = getopt('', ['site-root:']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
if ($siteRoot === '' || !is_file($siteRoot . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/integration-smoke.php --site-root=/path/to/processwire\n");
	exit(2);
}

chdir($siteRoot);
require $siteRoot . '/index.php';

if (!$wire->modules->isInstalled('Cockpit')) cockpitTestFail('Cockpit is not installed in the target site.');
/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$suffix = strtolower(bin2hex(random_bytes(6)));
$validPath = 'cockpit-test-' . $suffix . '/nested~path';
$cycleA = 'cockpit-test-' . $suffix . '-a';
$cycleB = 'cockpit-test-' . $suffix . '-b';
$createdIds = [];
$originalAllowAny = $cockpit->get('allow_any_public_target_host');
$originalAllowedHosts = $cockpit->get('allowed_target_hosts');
$originalBlockedHosts = $cockpit->get('blocked_target_hosts');
$cockpit->set('allow_any_public_target_host', true);
$cockpit->set('allowed_target_hosts', '');
$cockpit->set('blocked_target_hosts', '');

try {
	$id = $cockpit->saveLink([
		'path' => $validPath,
		'target_url' => 'https://example.com/cockpit-smoke?ref=print&utm_source=newsletter&utm_medium=email&utm_campaign=summer-sale#buy',
		'redirect_status' => 307,
		'enabled' => true,
	]);
	$createdIds[] = $id;
	$link = $cockpit->findLinkById($id);
	cockpitTestAssert($link !== null, 'Created link cannot be read back.');
	cockpitTestAssert($link['path'] === $validPath, 'Valid nested path was changed unexpectedly.');
	cockpitTestAssert(
		$link['target_url'] === 'https://example.com/cockpit-smoke?ref=print&utm_source=newsletter&utm_medium=email&utm_campaign=summer-sale#buy',
		'Campaign target query or fragment was changed unexpectedly.'
	);
	cockpitTestAssert((int)$link['redirect_status'] === 307, 'Redirect status was not preserved.');

	$updatedId = $cockpit->saveLink([
		'id' => $id,
		'path' => $validPath,
		'target_url' => 'https://example.org/cockpit-smoke-updated',
		'redirect_status' => 308,
		'enabled' => false,
	]);
	cockpitTestAssert($updatedId === $id, 'Update returned a different link ID.');
	$link = $cockpit->findLinkById($id);
	cockpitTestAssert($link !== null && empty($link['enabled']), 'Link was not disabled.');
	cockpitTestAssert((int)$link['redirect_status'] === 308, 'Updated redirect status was not preserved.');

	$cockpit->set('allow_any_public_target_host', false);
	cockpitTestRejects(function() use ($cockpit, $suffix): void {
		$cockpit->saveLink([
			'path' => 'cockpit-test-' . $suffix . '-fail-closed',
			'target_url' => 'https://example.com/rejected-without-policy',
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'Destination was accepted without an allowlist or explicit public-host opt-in.');
	$cockpit->set('allow_any_public_target_host', true);
	$cockpit->set('blocked_target_hosts', 'example.com');
	cockpitTestRejects(function() use ($cockpit, $suffix): void {
		$cockpit->saveLink([
			'path' => 'cockpit-test-' . $suffix . '-trailing-dot',
			'target_url' => 'https://example.com./blocked',
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'Trailing-dot host bypassed the destination denylist.');
	$cockpit->set('blocked_target_hosts', '');

	cockpitTestRejects(function() use ($cockpit, $suffix): void {
		$cockpit->saveLink([
			'path' => 'cockpit-test-' . $suffix . '-private',
			'target_url' => 'http://127.0.0.1/admin',
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'Private-network target was accepted.');

	cockpitTestRejects(function() use ($cockpit, $suffix): void {
		$cockpit->saveLink([
			'path' => 'cockpit-test-' . $suffix . '-userinfo',
			'target_url' => 'https://user:secret@example.com/path',
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'Target URL containing userinfo was accepted.');

	cockpitTestRejects(function() use ($cockpit, $suffix): void {
		$path = 'cockpit-test-' . $suffix . '-self';
		$cockpit->saveLink([
			'path' => $path,
			'target_url' => $cockpit->shortUrl($path) . '?loop=1',
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'Self redirect with a query string was accepted.');

	$cycleAId = $cockpit->saveLink([
		'path' => $cycleA,
		'target_url' => $cockpit->shortUrl($cycleB),
		'redirect_status' => 302,
		'enabled' => true,
	]);
	$createdIds[] = $cycleAId;
	cockpitTestRejects(function() use ($cockpit, $cycleA, $cycleB): void {
		$cockpit->saveLink([
			'path' => $cycleB,
			'target_url' => $cockpit->shortUrl($cycleA),
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'Two-link redirect cycle was accepted.');

	cockpitTestRejects(function() use ($cockpit, $suffix): void {
		$cockpit->saveLink([
			'path' => 'wire/cockpit-test-' . $suffix,
			'target_url' => 'https://example.com/',
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'ProcessWire core path was accepted.');
	cockpitTestRejects(function() use ($cockpit, $suffix): void {
		$cockpit->saveLink([
			'path' => 'invalid path ' . $suffix,
			'target_url' => 'https://example.com/',
			'redirect_status' => 302,
			'enabled' => true,
		]);
	}, 'Invalid non-empty path was replaced with an automatically generated path.');

	$query = cockpitTestCallProtected($cockpit, 'forwardableQueryString', [
		'it=cockpit-test&utm_source=qr&tag=one&tag=two',
	]);
	cockpitTestAssert($query === 'utm_source=qr&tag=one&tag=two', 'Internal ProcessWire rewrite query was forwarded.');

	$matchingCount = $cockpit->countLinks(['query' => $suffix]);
	$matchingPage = $cockpit->findLinks(['query' => $suffix, 'limit' => 1, 'offset' => 0]);
	$disabledMatches = $cockpit->findLinks(['query' => $suffix, 'enabled' => 0, 'limit' => 20]);
	cockpitTestAssert($matchingCount >= 2 && count($matchingPage) === 1, 'Search count or pagination failed.');
	cockpitTestAssert(count($disabledMatches) >= 1, 'Enabled-state filter failed.');

	$transferPath = 'cockpit-transfer-' . $suffix;
	$transferPayload = json_encode([
		'schema' => 'cockpit.links.transfer',
		'version' => 1,
		'links' => [[
			'path' => $transferPath,
			'target_url' => 'https://example.com/transfer-smoke',
			'redirect_status' => 307,
			'enabled' => true,
		]],
	]);
	cockpitTestAssert(is_string($transferPayload), 'Unable to create transfer fixture.');
	$transferPlan = $cockpit->planLinkImport($transferPayload, 'json');
	cockpitTestAssert($transferPlan->isExecutable() && !$cockpit->findLinkByPath($transferPath), 'Transfer dry-run changed data or failed validation.');
	$transferReport = $cockpit->importLinks($transferPayload, 'json', true, false);
	cockpitTestAssert($transferReport->committed(), 'Transactional transfer did not commit.');
	$transferLink = $cockpit->findLinkByPath($transferPath);
	cockpitTestAssert($transferLink !== null && strpos($cockpit->exportLinks('json'), $transferPath) !== false, 'Transfer export/import round trip failed.');
	$createdIds[] = (int)$transferLink['id'];
} finally {
	foreach (array_reverse($createdIds) as $createdId) {
		if ($cockpit->findLinkById($createdId)) $cockpit->deleteLink($createdId);
	}
	$cockpit->set('allow_any_public_target_host', $originalAllowAny);
	$cockpit->set('allowed_target_hosts', $originalAllowedHosts);
	$cockpit->set('blocked_target_hosts', $originalBlockedHosts);
}

echo json_encode([
	'ok' => true,
	'tests' => [
		'create_read_update_disable_delete',
		'nested_tilde_path',
		'utm_target_query_and_fragment',
		'fail_closed_destination_policy',
		'trailing_dot_denylist',
		'private_target_rejection',
		'userinfo_rejection',
		'self_loop_rejection',
		'two_link_cycle_rejection',
		'core_path_rejection',
		'invalid_path_not_auto_generated',
		'internal_rewrite_query_filter',
		'link_search_filter_pagination',
		'transfer_plan_apply_export',
	],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
