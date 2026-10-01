<?php namespace ProcessWire;

require_once __DIR__ . '/Audit/CockpitAuditLog.php';

/**
 * Idempotent database lifecycle and legacy-import service for Cockpit.
 *
 * MySQL/MariaDB implicitly commit DDL. Each schema migration is therefore
 * individually idempotent and its version is recorded only after the step has
 * completed. Legacy data import is DML-only and is strictly transactional.
 *
 * @internal Use through Cockpit's install/upgrade/diagnostic wrappers.
 */
final class CockpitSchemaManager {

	public const CURRENT_VERSION = 3;

	/** @var object */
	private $module;

	/** @var \PDO */
	private $database;

	/** @var string */
	private $prefix;

	/** @var string */
	private $linksName;

	/** @var string */
	private $statsName;

	/** @var string */
	private $schemaName;

	/** @var string */
	private $auditName;

	public function __construct(object $module) {
		$this->module = $module;
		$this->database = $module->wire('database');
		$this->prefix = (string)preg_replace('/[^A-Za-z0-9_]/', '', (string)$module->wire('config')->dbPrefix);
		$this->linksName = $this->prefix . 'cockpit_links';
		$this->statsName = $this->prefix . 'cockpit_daily_stats';
		$this->schemaName = $this->prefix . 'cockpit_schema';
		$this->auditName = $this->prefix . 'cockpit_audit_log';
	}

	/**
	 * Create or upgrade the schema without dropping tables, columns, or rows.
	 */
	public function installOrUpgrade(): array {
		return $this->withAdvisoryLock(function(): array {
			$before = $this->diagnose();
			$hadDataTables = $before['tables']['links'] || $before['tables']['stats'];
			$this->ensureSchemaTable();
			$version = $this->schemaVersion();

			if ($version < 1) {
				$this->ensureBaseTables();
				$this->assertRequiredColumns();
				$this->setSchemaVersion(1);
				$version = 1;
			}

			if ($version < 2) {
				$this->migrateToVersion2();
				$this->setSchemaVersion(2);
				$version = 2;
			}

			if ($version < 3) {
				$this->migrateToVersion3();
				$this->setSchemaVersion(3);
				$version = 3;
			}

			if ($version > self::CURRENT_VERSION) {
				throw new WireException(sprintf(
					'Cockpit database schema %d is newer than supported schema %d.',
					$version,
					self::CURRENT_VERSION
				));
			}

			$this->repairUnlocked();
			$after = $this->diagnose();
			$after['previous_version'] = (int)$before['schema_version'];
			$after['adopted_existing_tables'] = $hadDataTables && !$before['tables']['schema'];
			return $after;
		});
	}

	/**
	 * Add only missing safe structures. Existing data and column definitions are
	 * never rewritten. Unsafe drift is reported as an exception.
	 */
	public function repair(): array {
		return $this->withAdvisoryLock(function(): array {
			return $this->repairUnlocked();
		});
	}

	private function repairUnlocked(): array {
		$this->ensureBaseTables();
		$this->assertRequiredColumns();
		$this->ensureIndex($this->linksName, 'path', ['path'], true);
		$this->ensureIndex($this->linksName, 'enabled', ['enabled']);
		$this->ensureIndex($this->linksName, 'created_at', ['created_at']);
		$this->ensureIndex($this->statsName, 'click_date', ['click_date']);
		$this->ensureStatsForeignKey();
		(new CockpitAuditLog($this->module))->ensureSchema();
		return $this->diagnose();
	}

	/**
	 * Read-only schema report. No table is created by this method.
	 */
	public function diagnose(): array {
		$linksExists = $this->tableExists($this->linksName);
		$statsExists = $this->tableExists($this->statsName);
		$schemaExists = $this->tableExists($this->schemaName);
		$auditExists = $this->tableExists($this->auditName);
		return [
			'schema_version' => $schemaExists ? $this->schemaVersion() : 0,
			'current_version' => self::CURRENT_VERSION,
			'tables' => [
				'links' => $linksExists,
				'stats' => $statsExists,
				'schema' => $schemaExists,
				'audit' => $auditExists,
			],
			'links' => $linksExists ? $this->countRows($this->linksName) : 0,
			'stat_buckets' => $statsExists ? $this->countRows($this->statsName) : 0,
			'orphan_stat_buckets' => $linksExists && $statsExists ? $this->countOrphanStats() : 0,
			'stats_foreign_key' => $linksExists && $statsExists ? $this->hasStatsForeignKey() : false,
			'audit_events' => $auditExists ? $this->countRows($this->auditName) : 0,
		];
	}

	/**
	 * Validate and optionally import preserved ShortLinks rows.
	 *
	 * Dry-run uses the real Cockpit validator and rolls back every inserted row.
	 * MySQL may still advance AUTO_INCREMENT values; no user row is changed or
	 * deleted. Apply mode commits only when every link and statistic is valid.
	 */
	public function importLegacy(bool $apply = false, int $rowLimit = 10000, int $errorLimit = 100, ?string $legacyPrefixOverride = null): array {
		$rowLimit = max(1, min(100000, $rowLimit));
		$errorLimit = max(1, min(1000, $errorLimit));
		return $this->withAdvisoryLock(function() use ($apply, $rowLimit, $errorLimit, $legacyPrefixOverride): array {
			$this->assertRequiredColumns();
			$report = $this->newImportReport($apply);
			$legacyLinksName = $this->prefix . 'short_links';
			$legacyStatsName = $this->prefix . 'short_link_daily_stats';
			if (!$this->tableExists($legacyLinksName)) {
				$report['available'] = false;
				return $report;
			}
			$report['available'] = true;

			if ($this->countRows($this->linksName) > 0 || $this->countRows($this->statsName) > 0) {
				$this->addImportError($report, 'target_not_empty', 'Cockpit already contains links or statistics; legacy import was not attempted.', null, $errorLimit);
				return $report;
			}

			$columns = $this->columns($legacyLinksName);
			$required = ['id', 'target_url', 'redirect_status', 'enabled', 'hits', 'last_hit_at', 'created_at', 'updated_at'];
			foreach ($required as $column) {
				if (!isset($columns[$column])) {
					$this->addImportError($report, 'missing_column', "Legacy links table is missing column: {$column}.", null, $errorLimit);
				}
			}
			if (!isset($columns['path']) && !isset($columns['code'])) {
				$this->addImportError($report, 'missing_path_column', 'Legacy links table has neither path nor code.', null, $errorLimit);
			}
			if ($report['error_count'] > 0) return $report;

			$linkCount = $this->countRows($legacyLinksName);
			$statsCount = $this->tableExists($legacyStatsName) ? $this->countRows($legacyStatsName) : 0;
			$report['links_seen'] = $linkCount;
			$report['stats_seen'] = $statsCount;
			if ($linkCount > $rowLimit || $statsCount > $rowLimit) {
				$this->addImportError($report, 'row_limit', 'Legacy import exceeds the configured per-table row limit.', null, $errorLimit);
				return $report;
			}

			if ($this->database->inTransaction()) {
				throw new WireException('Cockpit legacy import cannot run inside another database transaction.');
			}

			$this->database->beginTransaction();
			try {
				$idMap = $this->importLegacyLinks($legacyLinksName, isset($columns['path']), $report, $errorLimit, $legacyPrefixOverride);
				if ($this->tableExists($legacyStatsName)) {
					$this->importLegacyStats($legacyStatsName, $idMap, $report, $errorLimit);
				}

				if ($apply && $report['error_count'] === 0) {
					$this->database->commit();
					$report['applied'] = true;
				} else {
					$this->database->rollBack();
				}
			} catch (\Throwable $exception) {
				if ($this->database->inTransaction()) $this->database->rollBack();
				$this->addImportError($report, 'import_exception', $exception->getMessage(), null, $errorLimit);
			}

			return $report;
		});
	}

	private function migrateToVersion2(): void {
		$this->assertRequiredColumns();
		$this->ensureStatsForeignKey();
	}

	private function migrateToVersion3(): void {
		(new CockpitAuditLog($this->module))->ensureSchema();
	}

	private function ensureBaseTables(): void {
		$constraint = 'fk_cockpit_' . substr(sha1($this->statsName . ':' . $this->linksName), 0, 16);
		$this->database->exec('CREATE TABLE IF NOT EXISTS ' . $this->quoteName($this->linksName) . ' (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`path` VARCHAR(191) NOT NULL,
			`target_url` VARCHAR(2048) NOT NULL,
			`redirect_status` SMALLINT UNSIGNED NOT NULL DEFAULT 302,
			`enabled` TINYINT(1) NOT NULL DEFAULT 1,
			`hits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`last_hit_at` DATETIME NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `path` (`path`),
			KEY `enabled` (`enabled`),
			KEY `created_at` (`created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

		$this->database->exec('CREATE TABLE IF NOT EXISTS ' . $this->quoteName($this->statsName) . ' (
			`link_id` INT UNSIGNED NOT NULL,
			`click_date` DATE NOT NULL,
			`clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`link_id`, `click_date`),
			KEY `click_date` (`click_date`),
			CONSTRAINT ' . $this->quoteName($constraint) . ' FOREIGN KEY (`link_id`) REFERENCES '
			. $this->quoteName($this->linksName) . ' (`id`) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	}

	private function ensureSchemaTable(): void {
		$this->database->exec('CREATE TABLE IF NOT EXISTS ' . $this->quoteName($this->schemaName) . ' (
			`name` VARCHAR(64) NOT NULL,
			`value` VARCHAR(255) NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`name`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	}

	private function schemaVersion(): int {
		if (!$this->tableExists($this->schemaName)) return 0;
		$stmt = $this->database->prepare('SELECT `value` FROM ' . $this->quoteName($this->schemaName) . ' WHERE `name`=:name');
		$stmt->execute([':name' => 'schema_version']);
		$value = $stmt->fetchColumn();
		return $value === false ? 0 : max(0, (int)$value);
	}

	private function setSchemaVersion(int $version): void {
		$stmt = $this->database->prepare(
			'INSERT INTO ' . $this->quoteName($this->schemaName) . ' (`name`,`value`,`updated_at`) VALUES (:name,:value,:updated) '
			. 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=VALUES(`updated_at`)'
		);
		$stmt->execute([
			':name' => 'schema_version',
			':value' => (string)$version,
			':updated' => date('Y-m-d H:i:s'),
		]);
	}

	private function assertRequiredColumns(): void {
		$requirements = [
			$this->linksName => ['id', 'path', 'target_url', 'redirect_status', 'enabled', 'hits', 'last_hit_at', 'created_at', 'updated_at'],
			$this->statsName => ['link_id', 'click_date', 'clicks'],
		];
		foreach ($requirements as $table => $required) {
			if (!$this->tableExists($table)) throw new WireException("Cockpit table is missing: {$table}.");
			$columns = $this->columns($table);
			$missing = array_values(array_diff($required, array_keys($columns)));
			if ($missing) {
				throw new WireException("Cockpit table {$table} is missing required columns: " . implode(', ', $missing) . '.');
			}
		}
	}

	private function ensureStatsForeignKey(): void {
		if ($this->hasStatsForeignKey()) return;
		$existingRules = $this->statsForeignKeyDeleteRules();
		if ($existingRules) {
			throw new WireException(
				'Cockpit statistics already have a non-cascading foreign key. No constraint was replaced: ' . implode(', ', $existingRules) . '.'
			);
		}
		$orphans = $this->countOrphanStats();
		if ($orphans > 0) {
			throw new WireException(
				"Cockpit has {$orphans} orphan statistic buckets. No rows were removed; repair those rows before adding referential integrity."
			);
		}
		$constraint = 'fk_cockpit_' . substr(sha1($this->statsName . ':' . $this->linksName), 0, 16);
		if ($this->dialectName() === 'sqlite') {
			$this->rebuildSQLiteStatsForeignKey($constraint);
			return;
		}
		$this->database->exec(
			'ALTER TABLE ' . $this->quoteName($this->statsName)
			. ' ADD CONSTRAINT ' . $this->quoteName($constraint)
			. ' FOREIGN KEY (`link_id`) REFERENCES ' . $this->quoteName($this->linksName) . ' (`id`) ON DELETE CASCADE'
		);
	}

	private function hasStatsForeignKey(): bool {
		return in_array('CASCADE', $this->statsForeignKeyDeleteRules(), true);
	}

	private function statsForeignKeyDeleteRules(): array {
		if ($this->dialectName() === 'sqlite') {
			$rows = $this->database->query('PRAGMA foreign_key_list(' . $this->quoteName($this->statsName) . ')')->fetchAll(\PDO::FETCH_ASSOC);
			$rules = [];
			foreach ($rows as $row) {
				if ((string)($row['table'] ?? '') !== $this->linksName || (string)($row['from'] ?? '') !== 'link_id') continue;
				$rules[] = strtoupper((string)($row['on_delete'] ?? ''));
			}
			return array_values(array_unique($rules));
		}
		if ($this->dialectName() === 'pgsql') {
			$stmt = $this->database->prepare(
				'SELECT DISTINCT rc.delete_rule FROM information_schema.referential_constraints rc '
				. 'INNER JOIN information_schema.table_constraints tc ON tc.constraint_catalog=rc.constraint_catalog '
				. 'AND tc.constraint_schema=rc.constraint_schema AND tc.constraint_name=rc.constraint_name '
				. 'INNER JOIN information_schema.key_column_usage k ON k.constraint_catalog=tc.constraint_catalog '
				. 'AND k.constraint_schema=tc.constraint_schema AND k.constraint_name=tc.constraint_name '
				. 'INNER JOIN information_schema.constraint_column_usage u ON u.constraint_catalog=rc.unique_constraint_catalog '
				. 'AND u.constraint_schema=rc.unique_constraint_schema AND u.constraint_name=rc.unique_constraint_name '
				. 'WHERE tc.table_schema=current_schema() AND tc.table_name=:stats AND k.column_name=:link_column '
				. 'AND u.table_name=:links AND u.column_name=:id_column'
			);
			$stmt->execute([
				':stats' => $this->statsName,
				':link_column' => 'link_id',
				':links' => $this->linksName,
				':id_column' => 'id',
			]);
			return array_values(array_unique(array_map('strtoupper', array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)))));
		}
		$stmt = $this->database->prepare(
			'SELECT DISTINCT r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k '
			. 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
			. 'ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME '
			. 'WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=:stats AND k.COLUMN_NAME=:link_column '
			. 'AND k.REFERENCED_TABLE_NAME=:links AND k.REFERENCED_COLUMN_NAME=:id_column'
		);
		$stmt->execute([
			':stats' => $this->statsName,
			':link_column' => 'link_id',
			':links' => $this->linksName,
			':id_column' => 'id',
		]);
		return array_values(array_unique(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
	}

	private function countOrphanStats(): int {
		$sql = 'SELECT COUNT(*) FROM ' . $this->quoteName($this->statsName) . ' s LEFT JOIN '
			. $this->quoteName($this->linksName) . ' l ON l.`id`=s.`link_id` WHERE l.`id` IS NULL';
		return (int)$this->database->query($sql)->fetchColumn();
	}

	private function ensureIndex(string $table, string $preferredName, array $columns, bool $unique = false): void {
		foreach ($this->database->getIndexes($table, true) as $index) {
			if (array_values($index['columns'] ?? []) === array_values($columns) && (!$unique || !empty($index['unique']))) return;
		}
		$this->database->exec(
			'ALTER TABLE ' . $this->quoteName($table) . ' ADD ' . ($unique ? 'UNIQUE ' : '')
			. 'INDEX ' . $this->quoteName($preferredName) . ' ('
			. implode(',', array_map([$this, 'quoteName'], $columns)) . ')'
		);
	}

	private function importLegacyLinks(string $table, bool $hasPath, array &$report, int $errorLimit, ?string $legacyPrefixOverride): array {
		$legacyConfig = $this->module->wire('modules')->getModuleConfigData('ShortLinks');
		$legacyPrefix = $legacyPrefixOverride !== null
			? $legacyPrefixOverride
			: (is_array($legacyConfig) ? (string)($legacyConfig['base_path'] ?? 'go') : 'go');
		$legacyPrefix = strtolower(trim((string)preg_replace('/[^a-z0-9_-]+/', '-', $legacyPrefix), '-')) ?: 'go';
		$idMap = [];
		$query = $this->database->query('SELECT * FROM ' . $this->quoteName($table) . ' ORDER BY `id` ASC');
		while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
			$legacyId = (int)$row['id'];
			$path = $hasPath ? (string)$row['path'] : $legacyPrefix . '/' . (string)$row['code'];
			try {
				$this->assertLegacyMetadata($row);
				$newId = $this->module->saveLink([
					'path' => $path,
					'target_url' => (string)$row['target_url'],
					'redirect_status' => (int)$row['redirect_status'],
					'enabled' => !empty($row['enabled']),
				]);
				$stored = $this->module->findLinkById($newId);
				if (!$stored || (string)$stored['path'] !== $path) {
					throw new WireException('Legacy path is not canonical and would be changed during import.');
				}
				$stmt = $this->database->prepare(
					'UPDATE ' . $this->quoteName($this->linksName) . ' SET `hits`=:hits, `last_hit_at`=:last_hit, '
					. '`created_at`=:created, `updated_at`=:updated WHERE `id`=:id'
				);
				$stmt->execute([
					':hits' => (int)$row['hits'],
					':last_hit' => $row['last_hit_at'] !== null && $row['last_hit_at'] !== '' ? (string)$row['last_hit_at'] : null,
					':created' => (string)$row['created_at'],
					':updated' => (string)$row['updated_at'],
					':id' => $newId,
				]);
				$idMap[$legacyId] = $newId;
				$report['links_valid']++;
			} catch (\Throwable $exception) {
				$this->addImportError($report, 'invalid_link', $exception->getMessage(), $legacyId, $errorLimit);
			}
		}
		return $idMap;
	}

	private function importLegacyStats(string $table, array $idMap, array &$report, int $errorLimit): void {
		$columns = $this->columns($table);
		foreach (['link_id', 'click_date', 'clicks'] as $required) {
			if (!isset($columns[$required])) {
				$this->addImportError($report, 'missing_stats_column', "Legacy statistics table is missing column: {$required}.", null, $errorLimit);
				return;
			}
		}
		$query = $this->database->query('SELECT `link_id`,`click_date`,`clicks` FROM ' . $this->quoteName($table));
		$stmt = $this->database->prepare(
			'INSERT INTO ' . $this->quoteName($this->statsName) . ' (`link_id`,`click_date`,`clicks`) '
			. 'VALUES (:link_id,:click_date,:clicks) ON DUPLICATE KEY UPDATE `clicks`=`clicks`+VALUES(`clicks`)'
		);
		while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
			$legacyId = (int)$row['link_id'];
			try {
				if (!isset($idMap[$legacyId])) throw new WireException('Statistic references a missing or rejected legacy link.');
				$date = (string)$row['click_date'];
				if (!$this->isDate($date)) throw new WireException('Statistic has an invalid click_date.');
				$clicks = filter_var($row['clicks'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
				if ($clicks === false) throw new WireException('Statistic has invalid clicks.');
				$stmt->execute([':link_id' => $idMap[$legacyId], ':click_date' => $date, ':clicks' => $clicks]);
				$report['stats_valid']++;
			} catch (\Throwable $exception) {
				$this->addImportError($report, 'invalid_stat', $exception->getMessage(), $legacyId, $errorLimit);
			}
		}
	}

	private function assertLegacyMetadata(array $row): void {
		$hits = filter_var($row['hits'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		if ($hits === false) throw new WireException('Legacy link has invalid hits.');
		foreach (['created_at', 'updated_at'] as $name) {
			if (!$this->isDateTime((string)$row[$name])) throw new WireException("Legacy link has invalid {$name}.");
		}
		if ($row['last_hit_at'] !== null && $row['last_hit_at'] !== '' && !$this->isDateTime((string)$row['last_hit_at'])) {
			throw new WireException('Legacy link has invalid last_hit_at.');
		}
	}

	private function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}

	private function isDateTime(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
		return $date !== false && $date->format('Y-m-d H:i:s') === $value;
	}

	private function newImportReport(bool $apply): array {
		return [
			'available' => false,
			'dry_run' => !$apply,
			'applied' => false,
			'links_seen' => 0,
			'links_valid' => 0,
			'stats_seen' => 0,
			'stats_valid' => 0,
			'error_count' => 0,
			'errors' => [],
		];
	}

	private function addImportError(array &$report, string $code, string $message, ?int $legacyId, int $limit): void {
		$report['error_count']++;
		if (count($report['errors']) >= $limit) return;
		$report['errors'][] = [
			'code' => $code,
			'message' => $message,
			'legacy_id' => $legacyId,
		];
	}

	private function columns(string $table): array {
		return $this->database->getColumns($table, true);
	}

	private function tableExists(string $table): bool {
		return $this->database->tableExists($table);
	}

	private function dialectName(): string {
		return strtolower((string)$this->database->getAttribute(\PDO::ATTR_DRIVER_NAME));
	}

	private function rebuildSQLiteStatsForeignKey(string $constraint): void {
		$temp = $this->statsName . '_fk_rebuild';
		$this->database->beginTransaction();
		try {
			$this->database->exec('DROP TABLE IF EXISTS ' . $this->quoteName($temp));
			$this->database->exec('CREATE TABLE ' . $this->quoteName($temp) . ' (
				`link_id` INT UNSIGNED NOT NULL,
				`click_date` DATE NOT NULL,
				`clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (`link_id`, `click_date`),
				KEY `click_date` (`click_date`),
				CONSTRAINT ' . $this->quoteName($constraint) . ' FOREIGN KEY (`link_id`) REFERENCES '
				. $this->quoteName($this->linksName) . ' (`id`) ON DELETE CASCADE
			)');
			$this->database->exec('INSERT INTO ' . $this->quoteName($temp) . ' (`link_id`,`click_date`,`clicks`) SELECT `link_id`,`click_date`,`clicks` FROM ' . $this->quoteName($this->statsName));
			$this->database->exec('DROP TABLE ' . $this->quoteName($this->statsName));
			$this->database->exec('ALTER TABLE ' . $this->quoteName($temp) . ' RENAME TO ' . $this->quoteName($this->statsName));
			$this->database->commit();
		} catch (\Throwable $exception) {
			if ($this->database->inTransaction()) $this->database->rollBack();
			throw $exception;
		}
	}

	private function countRows(string $table): int {
		return (int)$this->database->query('SELECT COUNT(*) FROM ' . $this->quoteName($table))->fetchColumn();
	}

	private function quoteName(string $name): string {
		if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new WireException('Unsafe Cockpit database identifier.');
		return '`' . $name . '`';
	}

	private function withAdvisoryLock(callable $operation) {
		$lockName = 'cockpit-schema-' . sha1($this->linksName);
		$stmt = $this->database->prepare('SELECT GET_LOCK(:name, 10)');
		$stmt->execute([':name' => $lockName]);
		if ((int)$stmt->fetchColumn() !== 1) throw new WireException('Unable to acquire the Cockpit schema lock.');
		try {
			return $operation();
		} finally {
			$release = $this->database->prepare('SELECT RELEASE_LOCK(:name)');
			$release->execute([':name' => $lockName]);
		}
	}
}
