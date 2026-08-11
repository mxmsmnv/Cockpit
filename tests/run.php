#!/usr/bin/env php
<?php

/**
 * Portable Cockpit test entry point.
 *
 * Static PHP lint always runs. ProcessWire and HTTP tests run only when their
 * explicit fixture inputs are available; otherwise they report SKIP and the
 * command exits successfully.
 */

function cockpitRunCommand(array $arguments): int {
	$command = implode(' ', array_map('escapeshellarg', $arguments));
	passthru($command, $exitCode);
	return (int)$exitCode;
}

function cockpitPhpFiles(string $root): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		/** @var SplFileInfo $file */
		$path = $file->getPathname();
		if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
		if (strpos($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) !== false) continue;
		$files[] = $path;
	}
	sort($files);
	return $files;
}

$options = getopt('', ['site-root:', 'base-url:', 'insecure', 'require-fixture']);
$root = dirname(__DIR__);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
$baseUrl = rtrim((string)($options['base-url'] ?? getenv('COCKPIT_BASE_URL') ?: ''), '/');
$insecure = array_key_exists('insecure', $options)
	|| in_array(strtolower((string)getenv('COCKPIT_HTTP_INSECURE')), ['1', 'true', 'yes'], true);
$requireFixture = array_key_exists('require-fixture', $options);

$phpFiles = cockpitPhpFiles($root);
if (!$phpFiles) {
	fwrite(STDERR, "FAIL static-lint: no PHP files found.\n");
	exit(1);
}
foreach ($phpFiles as $file) {
	if (cockpitRunCommand([PHP_BINARY, '-l', $file]) !== 0) {
		fwrite(STDERR, "FAIL static-lint: {$file}\n");
		exit(1);
	}
}
echo 'PASS static-lint (' . count($phpFiles) . " files)\n";

foreach (['path-policy.php', 'route-inspection.php', 'code-provider.php', 'transfer-service.php', 'admin-ui.php'] as $standaloneTest) {
	if (cockpitRunCommand([PHP_BINARY, $root . '/tests/' . $standaloneTest]) !== 0) {
		fwrite(STDERR, "FAIL {$standaloneTest}\n");
		exit(1);
	}
	echo "PASS {$standaloneTest}\n";
}

$hasSiteFixture = $siteRoot !== '' && is_file($siteRoot . '/index.php');
if (!$hasSiteFixture) {
	$message = 'SKIP integration-smoke: set --site-root or COCKPIT_PROCESSWIRE_ROOT to an installed disposable ProcessWire site.';
	if ($requireFixture) {
		fwrite(STDERR, str_replace('SKIP', 'FAIL', $message) . "\n");
		exit(1);
	}
	echo $message . "\n";
	echo "SKIP http-regression: ProcessWire fixture is unavailable.\n";
	exit(0);
}

if (cockpitRunCommand([PHP_BINARY, $root . '/tests/integration-smoke.php', '--site-root=' . $siteRoot]) !== 0) {
	fwrite(STDERR, "FAIL integration-smoke\n");
	exit(1);
}
echo "PASS integration-smoke\n";

if (cockpitRunCommand([PHP_BINARY, $root . '/tests/schema-lifecycle.php', '--site-root=' . $siteRoot]) !== 0) {
	fwrite(STDERR, "FAIL schema-lifecycle\n");
	exit(1);
}
echo "PASS schema-lifecycle\n";

if (cockpitRunCommand([PHP_BINARY, $root . '/tests/seed-demo.php', '--site-root=' . $siteRoot]) !== 0) {
	fwrite(STDERR, "FAIL seed-demo preview\n");
	exit(1);
}
echo "PASS seed-demo preview\n";

if (cockpitRunCommand([PHP_BINARY, $root . '/tests/config-ui.php', '--site-root=' . $siteRoot]) !== 0) {
	fwrite(STDERR, "FAIL config-ui\n");
	exit(1);
}
echo "PASS config-ui\n";

if (cockpitRunCommand([PHP_BINARY, $root . '/tests/analytics-filters.php', '--site-root=' . $siteRoot]) !== 0) {
	fwrite(STDERR, "FAIL analytics-filters\n");
	exit(1);
}
echo "PASS analytics-filters\n";

if (cockpitRunCommand([PHP_BINARY, $root . '/tests/code-provider-live.php', '--site-root=' . $siteRoot]) !== 0) {
	fwrite(STDERR, "FAIL code-provider-live\n");
	exit(1);
}
echo "PASS code-provider-live\n";

if (cockpitRunCommand([PHP_BINARY, $root . '/tests/permissions-audit.php', '--site-root=' . $siteRoot]) !== 0) {
	fwrite(STDERR, "FAIL permissions-audit\n");
	exit(1);
}
echo "PASS permissions-audit\n";

if ($baseUrl === '') {
	$message = 'SKIP http-regression: set --base-url or COCKPIT_BASE_URL to the HTTP origin serving the fixture.';
	if ($requireFixture) {
		fwrite(STDERR, str_replace('SKIP', 'FAIL', $message) . "\n");
		exit(1);
	}
	echo $message . "\n";
	exit(0);
}

$httpArguments = [
	PHP_BINARY,
	$root . '/tests/http-regression.php',
	'--site-root=' . $siteRoot,
	'--base-url=' . $baseUrl,
];
if ($insecure) $httpArguments[] = '--insecure';
if (cockpitRunCommand($httpArguments) !== 0) {
	fwrite(STDERR, "FAIL http-regression\n");
	exit(1);
}
echo "PASS http-regression\n";
