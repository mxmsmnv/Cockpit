#!/usr/bin/env php
<?php namespace ProcessWire;

/**
 * Self-cleaning HTTP regression test for an installed Cockpit module.
 *
 * This test temporarily changes Cockpit's destination/statistics settings and
 * creates uniquely named links. Always run it against a disposable local site.
 *
 * php tests/http-regression.php \
 *   --site-root=/path/to/processwire \
 *   --base-url=https://processwire.test \
 *   --insecure
 */

function cockpitHttpAssert(bool $condition, string $message): void {
	if (!$condition) throw new \RuntimeException($message);
}

/** @return array{status:int,headers:array<string,array<int,string>>,body:string} */
function cockpitHttpRequest(string $url, string $method, string $userAgent, bool $insecure): array {
	$options = [
		'http' => [
			'method' => $method,
			'ignore_errors' => true,
			'follow_location' => 0,
			'max_redirects' => 0,
			'timeout' => 15,
			'header' => "User-Agent: {$userAgent}\r\nConnection: close\r\n",
		],
	];
	if ($insecure) {
		$options['ssl'] = [
			'verify_peer' => false,
			'verify_peer_name' => false,
		];
	}

	$context = stream_context_create($options);
	$body = @file_get_contents($url, false, $context);
	if (function_exists('http_get_last_response_headers')) {
		$responseHeaders = http_get_last_response_headers();
	} else {
		// PHP <8.4 exposes these only as a predefined local variable. Reading it
		// through get_defined_vars() avoids PHP 8.5's direct-access deprecation.
		$definedVariables = get_defined_vars();
		$responseHeaders = $definedVariables['http_response_header'] ?? [];
	}
	if (!is_array($responseHeaders)) $responseHeaders = [];
	if (!$responseHeaders) {
		$error = error_get_last();
		throw new \RuntimeException('HTTP request failed for ' . $url . ': ' . (string)($error['message'] ?? 'no response headers'));
	}

	$status = 0;
	$headers = [];
	foreach ($responseHeaders as $index => $line) {
		if ($index === 0 && preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $matches)) {
			$status = (int)$matches[1];
			continue;
		}
		$separator = strpos($line, ':');
		if ($separator === false) continue;
		$name = strtolower(trim(substr($line, 0, $separator)));
		$headers[$name][] = trim(substr($line, $separator + 1));
	}
	if ($status === 0) throw new \RuntimeException('Unable to parse HTTP status for ' . $url);

	return [
		'status' => $status,
		'headers' => $headers,
		'body' => is_string($body) ? $body : '',
	];
}

/** @param array<string,array<int,string>> $headers */
function cockpitHttpHeader(array $headers, string $name): string {
	$values = $headers[strtolower($name)] ?? [];
	return $values ? (string)$values[count($values) - 1] : '';
}

function cockpitHttpUrl(string $baseUrl, string $path, string $query = ''): string {
	$encoded = implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
	return rtrim($baseUrl, '/') . '/' . $encoded . ($query !== '' ? '?' . $query : '');
}

$options = getopt('', ['site-root:', 'base-url:', 'insecure']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
$baseUrl = rtrim((string)($options['base-url'] ?? getenv('COCKPIT_BASE_URL') ?: ''), '/');
$insecure = array_key_exists('insecure', $options)
	|| in_array(strtolower((string)getenv('COCKPIT_HTTP_INSECURE')), ['1', 'true', 'yes'], true);

if ($siteRoot === '' || !is_file($siteRoot . '/index.php') || $baseUrl === '') {
	fwrite(STDERR, "Usage: php tests/http-regression.php --site-root=/path/to/processwire --base-url=https://processwire.test [--insecure]\n");
	exit(2);
}
if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
	fwrite(STDERR, "FAIL: --base-url must be an absolute HTTP(S) URL.\n");
	exit(2);
}

chdir($siteRoot);
require $siteRoot . '/index.php';

if (!$wire->modules->isInstalled('Cockpit')) {
	fwrite(STDERR, "FAIL: Cockpit is not installed in the target site.\n");
	exit(1);
}

/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$originalConfig = (array)$wire->modules->getModuleConfigData('Cockpit');
$testConfig = $originalConfig;
$testConfig['allow_any_public_target_host'] = 1;
$testConfig['allowed_target_hosts'] = '';
$testConfig['blocked_target_hosts'] = '';
$testConfig['statistics_enabled'] = 1;
$testConfig['ignore_known_bots'] = 1;
$testConfig['count_head_requests'] = 0;
$testConfig['forward_query_string'] = 0;
$testConfig['send_no_cache_headers'] = 1;

$suffix = strtolower(bin2hex(random_bytes(6)));
$prefix = 'cockpit-http-test-' . $suffix;
$createdIds = [];
$results = [];
$failure = null;

try {
	$wire->modules->saveModuleConfigData('Cockpit', $testConfig);
	foreach ($testConfig as $name => $value) $cockpit->set((string)$name, $value);

	foreach ([301, 302, 307, 308] as $status) {
		$path = $prefix . '-' . $status;
		$id = $cockpit->saveLink([
			'path' => $path,
			'target_url' => 'https://example.com/cockpit-http?existing=1#frag',
			'redirect_status' => $status,
			'enabled' => true,
		]);
		$createdIds[] = $id;
		$response = cockpitHttpRequest(cockpitHttpUrl($baseUrl, $path), 'GET', 'Mozilla/5.0 CockpitRegression/1.0', $insecure);
		cockpitHttpAssert($response['status'] === $status, "Expected {$status} for /{$path}, got {$response['status']}.");
		$location = cockpitHttpHeader($response['headers'], 'location');
		cockpitHttpAssert(
			$location === 'https://example.com/cockpit-http?existing=1#frag',
			"Unexpected Location header for /{$path}: {$location}"
		);
		if (in_array($status, [302, 307], true)) {
			$cacheControl = strtolower(cockpitHttpHeader($response['headers'], 'cache-control'));
			cockpitHttpAssert(strpos($cacheControl, 'no-store') !== false, "Temporary /{$path} response was cacheable: {$cacheControl}");
		}
		cockpitHttpAssert(strtoupper(cockpitHttpHeader($response['headers'], 'x-cloudcache')) === 'BYPASS', "Missing CloudCache bypass for /{$path}.");
		cockpitHttpAssert(cockpitHttpHeader($response['headers'], 'x-cockpit-route') === 'redirect', "Missing Cockpit route marker for /{$path}.");
		$link = $cockpit->findLinkById($id);
		cockpitHttpAssert($link !== null && (int)$link['hits'] === 1, "GET did not increment /{$path} exactly once.");
		$results[] = "http_{$status}";
	}

	$disabledPath = $prefix . '-disabled';
	$disabledId = $cockpit->saveLink([
		'path' => $disabledPath,
		'target_url' => 'https://example.com/cockpit-disabled',
		'redirect_status' => 302,
		'enabled' => false,
	]);
	$createdIds[] = $disabledId;
	$response = cockpitHttpRequest(cockpitHttpUrl($baseUrl, $disabledPath), 'GET', 'Mozilla/5.0 CockpitRegression/1.0', $insecure);
	cockpitHttpAssert($response['status'] === 404, "Disabled link returned {$response['status']} instead of 404.");
	cockpitHttpAssert(cockpitHttpHeader($response['headers'], 'location') === '', 'Disabled link emitted a Location header.');
	$results[] = 'disabled_404';

	$probePath = $prefix . '-302';
	$probeId = $createdIds[1];
	$response = cockpitHttpRequest(cockpitHttpUrl($baseUrl, $probePath), 'HEAD', 'Mozilla/5.0 CockpitRegression/1.0', $insecure);
	cockpitHttpAssert($response['status'] === 302, "HEAD returned {$response['status']} instead of 302.");
	$link = $cockpit->findLinkById($probeId);
	cockpitHttpAssert($link !== null && (int)$link['hits'] === 1, 'HEAD request was counted despite count_head_requests=0.');
	$results[] = 'head_not_counted';

	$response = cockpitHttpRequest(cockpitHttpUrl($baseUrl, $probePath), 'GET', 'Mozilla/5.0 CockpitPreview/1.0', $insecure);
	cockpitHttpAssert($response['status'] === 302, "Bot/preview request returned {$response['status']} instead of 302.");
	$link = $cockpit->findLinkById($probeId);
	cockpitHttpAssert($link !== null && (int)$link['hits'] === 1, 'Bot/preview request was counted despite ignore_known_bots=1.');
	$results[] = 'bot_not_counted';

	$testConfig['forward_query_string'] = 1;
	$wire->modules->saveModuleConfigData('Cockpit', $testConfig);
	$cockpit->set('forward_query_string', 1);
	$query = 'utm_source=harness&value=two';
	$response = cockpitHttpRequest(cockpitHttpUrl($baseUrl, $probePath, $query), 'GET', 'Mozilla/5.0 CockpitRegression/1.0', $insecure);
	cockpitHttpAssert($response['status'] === 302, "Query-forwarding request returned {$response['status']} instead of 302.");
	$location = cockpitHttpHeader($response['headers'], 'location');
	cockpitHttpAssert(
		$location === 'https://example.com/cockpit-http?existing=1&' . $query . '#frag',
		'Query string was not forwarded before the target fragment: ' . $location
	);
	$link = $cockpit->findLinkById($probeId);
	cockpitHttpAssert($link !== null && (int)$link['hits'] === 2, 'Final GET did not increment the counter exactly once.');
	$results[] = 'query_forwarding';
} catch (\Throwable $exception) {
	$failure = $exception;
} finally {
	$cleanupErrors = [];
	foreach (array_reverse($createdIds) as $createdId) {
		try {
			if ($cockpit->findLinkById($createdId)) $cockpit->deleteLink($createdId);
		} catch (\Throwable $exception) {
			$cleanupErrors[] = 'link ' . $createdId . ': ' . $exception->getMessage();
		}
	}
	try {
		$wire->modules->saveModuleConfigData('Cockpit', $originalConfig);
		foreach ($testConfig as $name => $_value) {
			$cockpit->set((string)$name, array_key_exists($name, $originalConfig) ? $originalConfig[$name] : null);
		}
	} catch (\Throwable $exception) {
		$cleanupErrors[] = 'configuration: ' . $exception->getMessage();
	}
	if ($cleanupErrors) {
		$cleanupFailure = new \RuntimeException('Cleanup failed: ' . implode('; ', $cleanupErrors));
		if ($failure === null) $failure = $cleanupFailure;
		else fwrite(STDERR, "WARNING: {$cleanupFailure->getMessage()}\n");
	}
}

if ($failure !== null) {
	fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . PHP_EOL);
	exit(1);
}

echo json_encode([
	'ok' => true,
	'base_url' => $baseUrl,
	'tests' => $results,
	'fixtures_removed' => count($createdIds),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
