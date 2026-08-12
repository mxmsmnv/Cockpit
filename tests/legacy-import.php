#!/usr/bin/env php
<?php namespace ProcessWire;

/**
 * Self-cleaning transactional legacy-import test.
 *
 * It refuses to run when real ShortLinks tables exist. The explicit fixture
 * flag is required because the test creates and drops test-owned legacy tables.
 * Cockpit target rows are always rolled back by importLegacy(false).
 */

function cockpitLegacyFail(string $message): void {
	throw new \RuntimeException($message);
}

function cockpitLegacyAssert(bool $condition, string $message): void {
	if (!$condition) cockpitLegacyFail($message);
}

$options = getopt('', ['site-root:', 'create-fixtures']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
if ($siteRoot === '' || !is_file($siteRoot . '/index.php') || !array_key_exists('create-fixtures', $options)) {
	fwrite(STDERR, "Usage: php tests/legacy-import.php --site-root=/path/to/processwire --create-fixtures\n");
	exit(2);
}

chdir($siteRoot);
require $siteRoot . '/index.php';
if (!$wire->modules->isInstalled('Cockpit')) cockpitLegacyFail('Cockpit is not installed.');
require_once dirname(__DIR__) . '/src/CockpitSchemaManager.php';

/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$database = $wire->database;
$prefix = (string)preg_replace('/[^A-Za-z0-9_]/', '', (string)$wire->config->dbPrefix);
$legacyLinks = $prefix . 'short_links';
$legacyStats = $prefix . 'short_link_daily_stats';
$cockpitLinks = $prefix . 'cockpit_links';
$cockpitStats = $prefix . 'cockpit_daily_stats';

$quote = static function(string $name): string {
	if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) cockpitLegacyFail('Unsafe fixture table name.');
	return '`' . $name . '`';
};
$exists = static function(string $name) use ($database): bool {
	$stmt = $database->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
	$stmt->execute([':table' => $name]);
	return (bool)$stmt->fetchColumn();
};
$count = static function(string $name) use ($database, $quote): int {
	return (int)$database->query('SELECT COUNT(*) FROM ' . $quote($name))->fetchColumn();
};

if ($exists($legacyLinks) || $exists($legacyStats)) cockpitLegacyFail('Real legacy tables exist; refusing to replace them with fixtures.');
if ($count($cockpitLinks) !== 0 || $count($cockpitStats) !== 0) cockpitLegacyFail('Cockpit target tables must be empty for this fixture test.');

$originalAllowAny = $cockpit->get('allow_any_public_target_host');
$originalAllowed = $cockpit->get('allowed_target_hosts');
$originalBlocked = $cockpit->get('blocked_target_hosts');
$created = [];

$createLinks = static function(string $pathColumn) use ($database, $quote, $legacyLinks, &$created): void {
	if (!in_array($pathColumn, ['path', 'code'], true)) cockpitLegacyFail('Invalid fixture path column.');
	$database->exec('CREATE TABLE ' . $quote($legacyLinks) . ' (
		`id` INT UNSIGNED NOT NULL,
		`' . $pathColumn . '` VARCHAR(191) NOT NULL,
		`target_url` VARCHAR(2048) NOT NULL,
		`redirect_status` SMALLINT UNSIGNED NOT NULL,
		`enabled` TINYINT(1) NOT NULL,
		`hits` BIGINT UNSIGNED NOT NULL,
		`last_hit_at` DATETIME NULL,
		`created_at` DATETIME NOT NULL,
		`updated_at` DATETIME NOT NULL,
		PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	$created[$legacyLinks] = true;
};
$createStats = static function() use ($database, $quote, $legacyStats, &$created): void {
	$database->exec('CREATE TABLE ' . $quote($legacyStats) . ' (
		`link_id` INT UNSIGNED NOT NULL,
		`click_date` DATE NOT NULL,
		`clicks` BIGINT UNSIGNED NOT NULL,
		PRIMARY KEY (`link_id`,`click_date`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	$created[$legacyStats] = true;
};
$dropFixtures = static function() use ($database, $quote, &$created): void {
	foreach (array_reverse(array_keys($created)) as $table) $database->exec('DROP TABLE ' . $quote($table));
	$created = [];
};

try {
	$cockpit->set('allow_any_public_target_host', true);
	$cockpit->set('allowed_target_hosts', '');
	$cockpit->set('blocked_target_hosts', '');
	$schema = new CockpitSchemaManager($cockpit);

	// Legacy code schema: one valid link and one valid statistic.
	$createLinks('code');
	$createStats();
	$database->exec('INSERT INTO ' . $quote($legacyLinks) . " VALUES
		(101, 'abc123', 'https://example.com/landing', 302, 1, 7, '2026-08-10 12:00:00', '2026-08-01 10:00:00', '2026-08-10 12:00:00')");
	$database->exec('INSERT INTO ' . $quote($legacyStats) . " VALUES (101, '2026-08-10', 7)");
	$codeReport = $schema->importLegacy(false, 10000, 100, 'cockpit-legacy-fixture');
	cockpitLegacyAssert($codeReport['error_count'] === 0, 'Valid code-schema dry-run reported errors: ' . json_encode($codeReport['errors']));
	cockpitLegacyAssert($codeReport['links_valid'] === 1 && $codeReport['stats_valid'] === 1, 'Valid code-schema rows were not mapped.');
	cockpitLegacyAssert(!$codeReport['applied'] && $count($cockpitLinks) === 0 && $count($cockpitStats) === 0, 'Dry-run persisted Cockpit rows.');
	$dropFixtures();

	// Legacy path schema: a non-canonical path must reject the entire import.
	$createLinks('path');
	$database->exec('INSERT INTO ' . $quote($legacyLinks) . " VALUES
		(202, 'unsafe/../path', 'https://example.com/landing', 302, 1, 0, NULL, '2026-08-01 10:00:00', '2026-08-01 10:00:00')");
	$pathReport = $schema->importLegacy(false);
	cockpitLegacyAssert($pathReport['error_count'] > 0, 'Non-canonical path schema was accepted.');
	cockpitLegacyAssert(!$pathReport['applied'] && $count($cockpitLinks) === 0, 'Rejected dry-run persisted a Cockpit link.');

	echo json_encode([
		'ok' => true,
		'tests' => [
			'code_schema_link_and_stats_mapping',
			'dry_run_rollback',
			'path_schema_validation',
			'all_or_nothing_rejection',
		],
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
	$dropFixtures();
	$cockpit->set('allow_any_public_target_host', $originalAllowAny);
	$cockpit->set('allowed_target_hosts', $originalAllowed);
	$cockpit->set('blocked_target_hosts', $originalBlocked);
}
