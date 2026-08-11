#!/usr/bin/env php
<?php namespace ProcessWire;

/**
 * Cockpit schema lifecycle smoke test.
 *
 * The default mode is read-only. Schema repair and legacy-import dry-run each
 * require an explicit flag because they may respectively add safe structures
 * or advance AUTO_INCREMENT while rolling back validation inserts.
 *
 * php tests/schema-lifecycle.php --site-root=/path/to/processwire
 * php tests/schema-lifecycle.php --site-root=/path/to/processwire --apply-schema
 * php tests/schema-lifecycle.php --site-root=/path/to/processwire --legacy-dry-run
 */

function cockpitSchemaFail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function cockpitSchemaAssert(bool $condition, string $message): void {
	if (!$condition) cockpitSchemaFail($message);
}

$options = getopt('', ['site-root:', 'apply-schema', 'legacy-dry-run']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
if ($siteRoot === '' || !is_file($siteRoot . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/schema-lifecycle.php --site-root=/path/to/processwire [--apply-schema] [--legacy-dry-run]\n");
	exit(2);
}

chdir($siteRoot);
require $siteRoot . '/index.php';
if (!$wire->modules->isInstalled('Cockpit')) cockpitSchemaFail('Cockpit is not installed in the target site.');

$moduleDirectory = dirname(__DIR__);
require_once $moduleDirectory . '/src/CockpitSchemaManager.php';
/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$schema = new CockpitSchemaManager($cockpit);

$before = $schema->diagnose();
cockpitSchemaAssert($before['current_version'] === CockpitSchemaManager::CURRENT_VERSION, 'Current schema constant is inconsistent.');
cockpitSchemaAssert(is_array($before['tables']), 'Schema table report is missing.');
cockpitSchemaAssert(is_int($before['orphan_stat_buckets']), 'Orphan count is not an integer.');

$result = [
	'ok' => true,
	'mode' => 'diagnose',
	'before' => $before,
];

if (array_key_exists('apply-schema', $options)) {
	$after = $schema->installOrUpgrade();
	cockpitSchemaAssert($after['schema_version'] === CockpitSchemaManager::CURRENT_VERSION, 'Schema did not reach the current version.');
	cockpitSchemaAssert($after['tables']['links'] && $after['tables']['stats'] && $after['tables']['schema'], 'Required Cockpit tables are missing.');
	cockpitSchemaAssert($after['orphan_stat_buckets'] === 0, 'Schema contains orphan statistic buckets.');
	cockpitSchemaAssert($after['stats_foreign_key'], 'Statistics foreign key is missing.');
	$result['mode'] = 'apply-schema';
	$result['after'] = $after;
}

if (array_key_exists('legacy-dry-run', $options)) {
	$legacy = $schema->importLegacy(false);
	cockpitSchemaAssert($legacy['dry_run'] === true && $legacy['applied'] === false, 'Legacy dry-run unexpectedly applied changes.');
	$result['legacy'] = $legacy;
	$result['mode'] = $result['mode'] === 'apply-schema' ? 'apply-schema+legacy-dry-run' : 'legacy-dry-run';
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
