<?php

$schema = file_get_contents(__DIR__ . '/../src/CockpitSchemaManager.php');
$audit = file_get_contents(__DIR__ . '/../src/Audit/CockpitAuditLog.php');
$module = file_get_contents(__DIR__ . '/../Cockpit.module.php');

if ($schema === false || $audit === false) {
	fwrite(STDERR, "Unable to read Cockpit database services.\n");
	exit(1);
}

$checks = [
	'schema table checks use public introspection' => str_contains($schema, '->tableExists($table)'),
	'schema column checks use public introspection' => str_contains($schema, '->getColumns($table, true)'),
	'schema index checks use public introspection' => str_contains($schema, '->getIndexes($table, true)'),
	'schema dialect uses the PDO driver attribute' => str_contains($schema, '->getAttribute(\PDO::ATTR_DRIVER_NAME)')
		&& !str_contains($schema, '->dialect()'),
	'SQLite foreign keys are inspected with PRAGMA' => str_contains($schema, 'PRAGMA foreign_key_list('),
	'SQLite foreign key repair is transactional' => str_contains($schema, 'rebuildSQLiteStatsForeignKey'),
	'audit availability uses public introspection' => str_contains($audit, '->tableExists($this->tableName)'),
	'dashboard counts use portable conditional aggregates' => substr_count($module, 'SUM(CASE WHEN `enabled`=') === 2
		&& !str_contains($module, 'SUM(`enabled`='),
];

foreach ($checks as $label => $passed) {
	if ($passed) continue;
	fwrite(STDERR, "FAIL: {$label}\n");
	exit(1);
}

echo "Cockpit database portability checks passed\n";
