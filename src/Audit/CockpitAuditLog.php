<?php namespace ProcessWire;

/**
 * Privacy-minimal administrative audit log.
 *
 * Target URLs, query strings, fragments, request data, IP addresses and user
 * agents are never accepted by the persistence method. Only changed field
 * names and the public Cockpit path are stored.
 */
final class CockpitAuditLog {

	/** @var object */
	private $module;

	/** @var \PDO */
	private $database;

	/** @var string */
	private $tableName;

	public function __construct(object $module) {
		$this->module = $module;
		$this->database = $module->wire('database');
		$prefix = (string)preg_replace('/[^A-Za-z0-9_]/', '', (string)$module->wire('config')->dbPrefix);
		$this->tableName = $prefix . 'cockpit_audit_log';
	}

	/** Idempotent schema hook for CockpitSchemaManager install/upgrade/repair. */
	public function ensureSchema(): void {
		$this->database->exec('CREATE TABLE IF NOT EXISTS ' . $this->quotedTable() . ' (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`actor_id` INT UNSIGNED NOT NULL DEFAULT 0,
			`link_id` INT UNSIGNED NULL DEFAULT NULL,
			`action` VARCHAR(16) NOT NULL,
			`path` VARCHAR(191) NOT NULL DEFAULT \'\',
			`changed_fields` VARCHAR(191) NOT NULL DEFAULT \'\',
			`before_enabled` TINYINT(1) NULL DEFAULT NULL,
			`after_enabled` TINYINT(1) NULL DEFAULT NULL,
			`before_status` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`after_status` SMALLINT UNSIGNED NULL DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			KEY `actor_id` (`actor_id`),
			KEY `link_id` (`link_id`),
			KEY `created_at` (`created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	}

	public function isAvailable(): bool {
		return $this->database->tableExists($this->tableName);
	}

	/**
	 * Record create/update/enable/disable/delete and return the audit row ID.
	 */
	public function recordLinkChange(?array $before, ?array $after, int $actorId): int {
		if (!$before && !$after) throw new WireException('An audit event requires a before or after link state.');
		if (!$this->isAvailable()) throw new WireException('Cockpit audit schema is not installed.');

		$action = $this->action($before, $after);
		$changed = $this->changedFields($before, $after);
		$state = $after ?: $before;
		$path = $this->safePath((string)($state['path'] ?? ''));
		$linkId = (int)($state['id'] ?? 0);
		$stmt = $this->database->prepare(
			'INSERT INTO ' . $this->quotedTable() . ' (`actor_id`,`link_id`,`action`,`path`,`changed_fields`,'
			. '`before_enabled`,`after_enabled`,`before_status`,`after_status`,`created_at`) '
			. 'VALUES (:actor,:link,:action,:path,:changed,:before_enabled,:after_enabled,:before_status,:after_status,:created)'
		);
		$stmt->execute([
			':actor' => max(0, $actorId),
			':link' => $linkId > 0 ? $linkId : null,
			':action' => $action,
			':path' => $path,
			':changed' => implode(',', $changed),
			':before_enabled' => $before ? (!empty($before['enabled']) ? 1 : 0) : null,
			':after_enabled' => $after ? (!empty($after['enabled']) ? 1 : 0) : null,
			':before_status' => $before ? $this->status($before['redirect_status'] ?? null) : null,
			':after_status' => $after ? $this->status($after['redirect_status'] ?? null) : null,
			':created' => date('Y-m-d H:i:s'),
		]);
		return (int)$this->database->lastInsertId();
	}

	public function findRecent(int $limit = 100, int $offset = 0, array $filters = []): array {
		if (!$this->isAvailable()) return [];
		$limit = max(1, min(500, $limit));
		$offset = max(0, $offset);
		[$where, $params] = $this->filterSql($filters);
		$stmt = $this->database->prepare(
			'SELECT `id`,`actor_id`,`link_id`,`action`,`path`,`changed_fields`,`before_enabled`,`after_enabled`,'
			. '`before_status`,`after_status`,`created_at` FROM ' . $this->quotedTable()
			. $where . ' ORDER BY `id` DESC LIMIT ' . $limit . ' OFFSET ' . $offset
		);
		$stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function countRecent(array $filters = []): int {
		if (!$this->isAvailable()) return 0;
		[$where, $params] = $this->filterSql($filters);
		$stmt = $this->database->prepare('SELECT COUNT(*) FROM ' . $this->quotedTable() . $where);
		$stmt->execute($params);
		return (int)$stmt->fetchColumn();
	}

	/** @return int[] */
	public function findActorIds(): array {
		if (!$this->isAvailable()) return [];
		$rows = $this->database->query('SELECT DISTINCT `actor_id` FROM ' . $this->quotedTable() . ' ORDER BY `actor_id`')->fetchAll(\PDO::FETCH_COLUMN);
		return array_values(array_map('intval', $rows));
	}

	public function findForLink(int $linkId, int $limit = 100): array {
		if (!$this->isAvailable() || $linkId < 1) return [];
		$limit = max(1, min(500, $limit));
		$stmt = $this->database->prepare(
			'SELECT `id`,`actor_id`,`link_id`,`action`,`path`,`changed_fields`,`before_enabled`,`after_enabled`,'
			. '`before_status`,`after_status`,`created_at` FROM ' . $this->quotedTable()
			. ' WHERE `link_id`=:link_id ORDER BY `id` DESC LIMIT ' . $limit
		);
		$stmt->execute([':link_id' => $linkId]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function action(?array $before, ?array $after): string {
		if (!$before) return 'create';
		if (!$after) return 'delete';
		$changed = $this->changedFields($before, $after);
		if ($changed === ['enabled']) return !empty($after['enabled']) ? 'enable' : 'disable';
		return 'update';
	}

	private function changedFields(?array $before, ?array $after): array {
		if (!$before) return ['path', 'target', 'status', 'enabled'];
		if (!$after) return ['path', 'target', 'status', 'enabled'];
		$changed = [];
		if ((string)($before['path'] ?? '') !== (string)($after['path'] ?? '')) $changed[] = 'path';
		// Record only that the target changed, never the target value.
		if ((string)($before['target_url'] ?? '') !== (string)($after['target_url'] ?? '')) $changed[] = 'target';
		if ((int)($before['redirect_status'] ?? 0) !== (int)($after['redirect_status'] ?? 0)) $changed[] = 'status';
		if (!empty($before['enabled']) !== !empty($after['enabled'])) $changed[] = 'enabled';
		return $changed;
	}

	private function status($value): ?int {
		$status = (int)$value;
		return in_array($status, [301, 302, 307, 308], true) ? $status : null;
	}

	private function safePath(string $path): string {
		$path = strtolower(trim(str_replace('\\', '/', $path), '/ '));
		$path = (string)preg_replace('/[^a-z0-9._~\/-]+/', '-', $path);
		return substr($path, 0, 191);
	}

	/** @return array{0:string,1:array<string,mixed>} */
	private function filterSql(array $filters): array {
		$where = [];
		$params = [];
		$action = strtolower(trim((string)($filters['action'] ?? '')));
		if (in_array($action, ['create', 'update', 'enable', 'disable', 'delete'], true)) {
			$where[] = '`action`=:action';
			$params[':action'] = $action;
		}
		$requestedActorId = (int)($filters['actor_id'] ?? 0);
		$actorId = $requestedActorId === -1 ? 0 : $requestedActorId;
		if ($actorId > 0) {
			$where[] = '`actor_id`=:actor_id';
			$params[':actor_id'] = $actorId;
		} elseif ($requestedActorId === -1) {
			$where[] = '`actor_id`=0';
		}
		$query = substr(trim((string)($filters['query'] ?? '')), 0, 191);
		if ($query !== '') {
			$where[] = '`path` LIKE :query';
			$params[':query'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
		}
		$dateFrom = (string)($filters['date_from'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
			$where[] = '`created_at`>=:date_from';
			$params[':date_from'] = $dateFrom . ' 00:00:00';
		}
		$dateTo = (string)($filters['date_to'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
			$where[] = '`created_at`<=:date_to';
			$params[':date_to'] = $dateTo . ' 23:59:59';
		}
		return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
	}

	private function quotedTable(): string {
		if (!preg_match('/^[A-Za-z0-9_]+$/', $this->tableName)) throw new WireException('Unsafe Cockpit audit table name.');
		return '`' . $this->tableName . '`';
	}
}
