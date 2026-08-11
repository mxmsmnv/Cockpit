#!/usr/bin/env php
<?php namespace ProcessWire;

/**
 * Self-cleaning permission-policy and privacy-safe audit integration test.
 * Audit fixture rows are inserted inside a transaction and rolled back.
 */

function cockpitAccessFail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function cockpitAccessAssert(bool $condition, string $message): void {
	if (!$condition) cockpitAccessFail($message);
}

final class CockpitFakeUser {
	/** @var array<string,bool> */
	private $permissions;
	/** @var bool */
	private $superuser;

	public function __construct(array $permissions = [], bool $superuser = false) {
		$this->permissions = array_fill_keys($permissions, true);
		$this->superuser = $superuser;
	}

	public function hasPermission(string $permission): bool { return isset($this->permissions[$permission]); }
	public function isSuperuser(): bool { return $this->superuser; }
}

$options = getopt('', ['site-root:']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
if ($siteRoot === '' || !is_file($siteRoot . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/permissions-audit.php --site-root=/path/to/processwire\n");
	exit(2);
}

require_once dirname(__DIR__) . '/src/Access/CockpitPermissionPolicy.php';

$none = new CockpitPermissionPolicy(new CockpitFakeUser());
cockpitAccessAssert(!$none->allowsAny(), 'A user without Cockpit permissions received access.');

$legacy = new CockpitPermissionPolicy(new CockpitFakeUser([CockpitPermissionPolicy::LEGACY]));
foreach (CockpitPermissionPolicy::GRANULAR as $permission) {
	cockpitAccessAssert($legacy->allows($permission), 'Legacy umbrella did not grant ' . $permission . '.');
}
cockpitAccessAssert($legacy->allowsDelete(), 'Legacy umbrella did not preserve delete access.');

$stats = new CockpitPermissionPolicy(new CockpitFakeUser([CockpitPermissionPolicy::VIEW_STATS]));
cockpitAccessAssert($stats->allowsAny() && $stats->allows(CockpitPermissionPolicy::VIEW_STATS), 'Stats-only access failed.');
cockpitAccessAssert(!$stats->allows(CockpitPermissionPolicy::MANAGE_LINKS), 'Stats-only user received link mutation access.');

$deleteOnly = new CockpitPermissionPolicy(new CockpitFakeUser([CockpitPermissionPolicy::DELETE_LINKS]));
cockpitAccessAssert(!$deleteOnly->allowsDelete(), 'Delete permission bypassed ordinary link-management permission.');
$managerDelete = new CockpitPermissionPolicy(new CockpitFakeUser([
	CockpitPermissionPolicy::MANAGE_LINKS,
	CockpitPermissionPolicy::DELETE_LINKS,
]));
cockpitAccessAssert($managerDelete->allowsDelete(), 'Combined manage/delete permissions did not allow deletion.');

$superuser = new CockpitPermissionPolicy(new CockpitFakeUser([], true));
cockpitAccessAssert($superuser->allows(CockpitPermissionPolicy::VIEW_AUDIT) && $superuser->allowsDelete(), 'Superuser compatibility failed.');

chdir($siteRoot);
require $siteRoot . '/index.php';
if (!$wire->modules->isInstalled('Cockpit')) cockpitAccessFail('Cockpit is not installed.');
require_once dirname(__DIR__) . '/src/Audit/CockpitAuditLog.php';
/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$audit = new CockpitAuditLog($cockpit);
$audit->ensureSchema();
$database = $wire->database;
if ($database->inTransaction()) cockpitAccessFail('Unexpected existing database transaction.');

$base = [
	'id' => 987654321,
	'path' => 'cockpit-audit-fixture',
	'target_url' => 'https://example.com/private?token=DO_NOT_STORE#fragment',
	'redirect_status' => 302,
	'enabled' => 0,
];

$database->beginTransaction();
try {
	$ids = [];
	$ids[] = $audit->recordLinkChange(null, $base, 41);
	$targetChanged = $base;
	$targetChanged['target_url'] = 'https://other.example/secret?api_key=NEVER_STORE';
	$ids[] = $audit->recordLinkChange($base, $targetChanged, 41);
	$enabled = $targetChanged;
	$enabled['enabled'] = 1;
	$ids[] = $audit->recordLinkChange($targetChanged, $enabled, 41);
	$disabled = $enabled;
	$disabled['enabled'] = 0;
	$ids[] = $audit->recordLinkChange($enabled, $disabled, 41);
	$ids[] = $audit->recordLinkChange($disabled, null, 41);

	$placeholders = implode(',', array_fill(0, count($ids), '?'));
	$stmt = $database->prepare('SELECT * FROM `' . (string)preg_replace('/[^A-Za-z0-9_]/', '', (string)$wire->config->dbPrefix) . 'cockpit_audit_log` WHERE `id` IN (' . $placeholders . ') ORDER BY `id`');
	$stmt->execute($ids);
	$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
	cockpitAccessAssert(count($rows) === 5, 'Expected five audit fixture events.');
	cockpitAccessAssert(array_column($rows, 'action') === ['create', 'update', 'enable', 'disable', 'delete'], 'Audit action classification is incorrect.');
	cockpitAccessAssert((string)$rows[1]['changed_fields'] === 'target', 'Target change metadata is incorrect.');
	$serialized = json_encode($rows, JSON_UNESCAPED_SLASHES);
	cockpitAccessAssert(strpos((string)$serialized, 'DO_NOT_STORE') === false, 'Audit persisted the original target secret.');
	cockpitAccessAssert(strpos((string)$serialized, 'NEVER_STORE') === false, 'Audit persisted the updated target secret.');
	cockpitAccessAssert(strpos((string)$serialized, 'example.com') === false, 'Audit persisted a destination hostname.');
} finally {
	if ($database->inTransaction()) $database->rollBack();
}

echo json_encode([
	'ok' => true,
	'tests' => [
		'legacy_umbrella',
		'granular_isolation',
		'delete_requires_manage',
		'superuser_compatibility',
		'audit_action_classification',
		'audit_transaction_rollback',
		'target_and_query_secret_exclusion',
	],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
