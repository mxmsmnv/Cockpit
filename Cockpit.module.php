<?php namespace ProcessWire;

require_once __DIR__ . '/src/CockpitCli.php';
require_once __DIR__ . '/src/CockpitPathPolicy.php';
require_once __DIR__ . '/src/CockpitSchemaManager.php';
require_once __DIR__ . '/src/Routing/bootstrap.php';
require_once __DIR__ . '/src/Cache/bootstrap.php';
require_once __DIR__ . '/src/Code/bootstrap.php';
require_once __DIR__ . '/src/Transfer/bootstrap.php';

/**
 * Cockpit — managed short URLs with click statistics for ProcessWire.
 *
 * Public requests such as /go/124 or /instagram are intercepted before ProcessWire renders
 * a page. Links and compact daily click totals live in dedicated tables, so
 * the module does not require templates, fields, or URL segments to be added.
 */
class Cockpit extends WireData implements Module, ConfigurableModule {

	/** @var \CockpitCli|null */
	protected $_cli;

	/** @var CockpitSchemaManager|null */
	protected $_schemaManager;

	/** @var \Cockpit\Routing\RouteInspector|null */
	protected $_routeInspector;

	/** @var \Cockpit\Cache\RouteCacheIntegration|null */
	protected $_routeCacheIntegration;

	/** @var array<string,\Cockpit\Code\CodeProviderInterface>|null */
	protected $_codeProviders;

	/** @var \Cockpit\Transfer\LinkTransferService|null */
	protected $_linkTransferService;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Cockpit',
			'summary' => 'Manage custom-path redirects and click statistics from the ProcessWire admin or CLI.',
			'author' => 'Maxim Semenov',
			'href' => 'https://github.com/mxmsmnv/Cockpit',
			'version' => 100,
			'requires' => 'ProcessWire>=3.0.200',
			'singular' => true,
			'autoload' => true,
			'installs' => ['ProcessCockpit'],
			'icon' => 'link',
		];
	}

	public function init(): void {
		$this->getCli()->dispatch();
		// Resolve Cockpit links only after ProcessWire pages, security modules, API/path hooks,
		// SEO redirects, PagePathHistory, and legacy 404 redirects had a chance to own the route.
		$this->addHookAfter('ProcessPageView::pageNotFound', $this, 'handlePublicRequest', ['priority' => 200]);
		$this->addHook('LazyCron::everyDay', $this, 'hookPruneStatistics');
	}

	public function getCli(): \CockpitCli {
		if (!$this->_cli) $this->_cli = new \CockpitCli($this);
		return $this->_cli;
	}

	/** @internal Schema mutations require an authorized maintenance context. */
	public function getSchemaManager(): CockpitSchemaManager {
		if (!$this->_schemaManager) $this->_schemaManager = new CockpitSchemaManager($this);
		return $this->_schemaManager;
	}

	public function diagnoseSchema(): array {
		return $this->getSchemaManager()->diagnose();
	}

	public function importLegacyShortLinks(bool $apply = false, int $rowLimit = 10000, int $errorLimit = 100): array {
		return $this->getSchemaManager()->importLegacy($apply, $rowLimit, $errorLimit);
	}

	public function getRouteInspector(): \Cockpit\Routing\RouteInspector {
		if (!$this->_routeInspector) {
			$this->_routeInspector = \Cockpit\Routing\CockpitRouteInspectorFactory::forProcessWire($this->wire());
		}
		return $this->_routeInspector;
	}

	public function inspectRoute(string $path): \Cockpit\Routing\RouteInspection {
		return $this->getRouteInspector()->inspect('/' . trim($path, '/'));
	}

	protected function getRouteCacheIntegration(): \Cockpit\Cache\RouteCacheIntegration {
		if (!$this->_routeCacheIntegration) {
			$modules = $this->wire('modules');
			$cockpit = $this;
			$this->_routeCacheIntegration = new \Cockpit\Cache\CloudCacheRouteIntegration(
				static function(string $name) use ($modules): bool {
					return (bool)$modules->isInstalled($name);
				},
				static function(string $path) use ($modules, $cockpit): bool {
					$cloudCache = $modules->get('CloudCache');
					if ($cloudCache->staticEnabled) $cloudCache->staticCache()->clearUrl($path);
					if ($cloudCache->cacheEnabled && $cloudCache->purge()->isConfigured()) {
						return (bool)$cloudCache->purge()->purgeUrls([$cockpit->shortUrl(trim($path, '/'))]);
					}
					return true;
				}
			);
		}
		return $this->_routeCacheIntegration;
	}

	/** @return array<string,\Cockpit\Code\CodeProviderInterface> */
	public function getCodeProviders(): array {
		if ($this->_codeProviders === null) {
			$modules = $this->wire('modules');
			$provider = new \Cockpit\Code\FieldtypeQRCodeProvider(
				static function(string $name) use ($modules): bool { return (bool)$modules->isInstalled($name); },
				static function(string $name) use ($modules): array {
					$info = $modules->getModuleInfo($name);
					return is_array($info) ? $info : [];
				},
				static function(string $payload, array $options) use ($modules): string {
					// Load only inside an authorized generation request; status discovery
					// remains side-effect free and never instantiates the optional module.
					$modules->get('FieldtypeQRCode');
					$info = $modules->getModuleInfo('FieldtypeQRCode');
					$version = is_array($info) ? ($info['version'] ?? 0) : 0;
					$isV2 = is_string($version) && strpos($version, '.') !== false
						? version_compare($version, '2.0.1', '>=')
						: (int)$version >= 201;
					if ($isV2) return (string)FieldtypeQRCode::generateRawQRCode($payload, $options);
					return (string)FieldtypeQRCode::generateRawQRCode(
						$payload,
						(bool)$options['svg'],
						(bool)$options['markup'],
						(string)$options['recoveryLevel']
					);
				},
				static function(): bool {
					return class_exists('ProcessWire\\FieldtypeQRCode')
						&& is_callable(['ProcessWire\\FieldtypeQRCode', 'generateRawQRCode']);
				}
			);
			$this->_codeProviders = [$provider->id() => $provider];
		}
		return $this->_codeProviders;
	}

	public function getCodeProvider(string $id): ?\Cockpit\Code\CodeProviderInterface {
		$providers = $this->getCodeProviders();
		return $providers[$id] ?? null;
	}

	/** @return array<string,array<string,mixed>> */
	public function getCodeProviderStatus(): array {
		$status = [];
		foreach ($this->getCodeProviders() as $id => $provider) {
			try {
				$status[$id] = array_merge([
					'id' => $id,
					'label' => $provider->label(),
				], $provider->capabilities());
			} catch (\Throwable $exception) {
				$status[$id] = ['id' => $id, 'label' => $provider->label(), 'available' => false, 'error' => 'Provider status is unavailable.'];
			}
		}
		return $status;
	}

	public function getLinkTransferService(): \Cockpit\Transfer\LinkTransferService {
		if (!$this->_linkTransferService) $this->_linkTransferService = new \Cockpit\Transfer\LinkTransferService();
		return $this->_linkTransferService;
	}

	public function exportLinks(string $format = 'json'): string {
		$format = strtolower($format);
		$links = $this->iterateLinks($this->getLinkTransferService()->limits()->maxRows() + 1);
		if ($format === 'json') return $this->getLinkTransferService()->exportJson($links);
		if ($format === 'csv') return $this->getLinkTransferService()->exportCsv($links);
		throw new WireException($this->_('Transfer format must be csv or json.'));
	}

	public function planLinkImport(string $payload, string $format, bool $replaceExisting = false): \Cockpit\Transfer\TransferPlan {
		$callbacks = $this->linkTransferCallbacks($replaceExisting);
		return $this->getLinkTransferService()->planImport(
			$payload,
			$format,
			new \Cockpit\Transfer\TransferOptions(true, $replaceExisting),
			$callbacks['validate'],
			$callbacks['find']
		);
	}

	public function importLinks(string $payload, string $format, bool $apply = false, bool $replaceExisting = false): \Cockpit\Transfer\TransferReport {
		$callbacks = $this->linkTransferCallbacks($replaceExisting);
		$report = $this->getLinkTransferService()->import(
			$payload,
			$format,
			new \Cockpit\Transfer\TransferOptions(!$apply, $replaceExisting),
			$callbacks['validate'],
			$callbacks['find'],
			$callbacks['write'],
			$callbacks['transaction']
		);
		if ($report->committed()) {
			$this->purgeRouteCaches(array_column($report->written(), 'path'));
		}
		return $report;
	}

	/**
	 * Resolve GET/HEAD requests that reached ProcessWire's not-found pipeline.
	 */
	public function handlePublicRequest(HookEvent $event): void {
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		if (!in_array($method, ['GET', 'HEAD'], true)) return;

		$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
		if (!is_string($path)) return;
		$rootPath = trim((string)$this->wire('config')->urls->root, '/');
		if ($rootPath !== '') {
			$rootPrefix = '/' . $rootPath;
			if ($path === $rootPrefix) {
				$path = '/';
			} elseif (strpos($path, $rootPrefix . '/') === 0) {
				$path = substr($path, strlen($rootPrefix));
			} else {
				return;
			}
		}

		$requestedPath = \CockpitPathPolicy::fromRequestPath($path);
		if ($requestedPath === null) return;

		$link = $this->findLinkByPath($requestedPath);
		if (!$link) return;
		// Existing ProcessWire pages and their URL segments always take priority.
		if ($this->pathMatchesReservedPattern($requestedPath)) return;
		try {
			if ($this->findConflictingPage($requestedPath)) return;
		} catch (\Throwable $exception) {
			// Fail closed: never redirect when ProcessWire page ownership is uncertain.
			return;
		}
		if (empty($link['enabled'])) {
			return;
		}
		if (!$this->isAllowedTargetUrl((string)$link['target_url'])) {
			return;
		}

		if ($this->shouldRecordRequest($method)) {
			try {
				$this->recordClick((int)$link['id']);
			} catch (\Throwable $exception) {
				// A statistics failure must never break a valid public redirect.
				$this->wire('log')->save('cockpit', 'Unable to record click: ' . $exception->getMessage());
			}
		}

		$status = in_array((int)$link['redirect_status'], [301, 302, 307, 308], true)
			? (int)$link['redirect_status']
			: $this->getDefaultRedirectStatus();
		$target = $this->redirectTarget((string)$link['target_url']);
		if ($this->settingBool('send_no_cache_headers', true)) {
			foreach ($this->getRouteCacheIntegration()->bypassHeaders($status) as $name => $value) {
				header($name . ': ' . $value, true);
			}
		}
		$this->wire('session')->redirect($target, $status);
	}

	public function findLinkByPath(string $path): ?array {
		$stmt = $this->wire('database')->prepare(
			'SELECT * FROM ' . $this->linksTable() . ' WHERE `path`=:path LIMIT 1'
		);
		$stmt->execute([':path' => $path]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function findLinkById(int $id): ?array {
		$stmt = $this->wire('database')->prepare(
			'SELECT * FROM ' . $this->linksTable() . ' WHERE `id`=:id LIMIT 1'
		);
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function findLinks(array $filters = []): array {
		[$where, $params] = $this->linkFilterQuery($filters);
		$limit = isset($filters['limit']) ? max(1, min(500, (int)$filters['limit'])) : 0;
		$offset = $limit > 0 ? max(0, (int)($filters['offset'] ?? 0)) : 0;
		$sql = 'SELECT * FROM ' . $this->linksTable() . $where . ' ORDER BY `created_at` DESC, `id` DESC';
		if ($limit > 0) $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
		$stmt = $this->wire('database')->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function countLinks(array $filters = []): int {
		[$where, $params] = $this->linkFilterQuery($filters);
		$stmt = $this->wire('database')->prepare('SELECT COUNT(*) FROM ' . $this->linksTable() . $where);
		$stmt->execute($params);
		return (int)$stmt->fetchColumn();
	}

	protected function linkFilterQuery(array $filters): array {
		$conditions = [];
		$params = [];
		$query = trim((string)($filters['query'] ?? ''));
		if ($query !== '') {
			$query = substr($query, 0, 200);
			$escaped = strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
			$conditions[] = '(`path` LIKE :query ESCAPE \'\\\\\' OR `target_url` LIKE :query ESCAPE \'\\\\\')';
			$params[':query'] = '%' . $escaped . '%';
		}
		if (array_key_exists('enabled', $filters) && $filters['enabled'] !== '' && $filters['enabled'] !== null) {
			$conditions[] = '`enabled`=:enabled';
			$params[':enabled'] = !empty($filters['enabled']) ? 1 : 0;
		}
		$status = (int)($filters['redirect_status'] ?? 0);
		if (in_array($status, [301, 302, 307, 308], true)) {
			$conditions[] = '`redirect_status`=:redirect_status';
			$params[':redirect_status'] = $status;
		}
		if (!empty($filters['active_only'])) $conditions[] = '`hits`>0';
		return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
	}

	/** @return \Generator<int,array> */
	protected function iterateLinks(int $limit): \Generator {
		$limit = max(1, min(100001, $limit));
		$stmt = $this->wire('database')->query(
			'SELECT * FROM ' . $this->linksTable() . ' ORDER BY `created_at` DESC, `id` DESC LIMIT ' . $limit
		);
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) yield $row;
	}

	protected function linkTransferCallbacks(bool $replaceExisting): array {
		$module = $this;
		$validate = static function(array $candidate, array $context) use ($module, $replaceExisting): array {
			$path = $module->normalizePath((string)($candidate['path'] ?? ''));
			$existing = $path !== '' ? $module->findLinkByPath($path) : null;
			$candidate['path'] = $path;
			$candidate['id'] = $replaceExisting && $existing ? (int)$existing['id'] : 0;
			$validated = $module->validateLinkCandidate($candidate);
			unset($validated['id']);
			return $validated;
		};
		$find = static function(string $path) use ($module): ?array {
			return $module->findLinkByPath($path);
		};
		$write = static function(array $candidate, \Cockpit\Transfer\TransferAction $action, ?array $existing) use ($module): int {
			if ($action->type() === \Cockpit\Transfer\TransferAction::UPDATE) {
				if (!$existing) throw new WireException('The planned replacement no longer exists.');
				$candidate['id'] = (int)$existing['id'];
			}
			return $module->saveLink($candidate, true);
		};
		$transaction = static function(callable $operation) use ($module) {
			$db = $module->wire('database');
			if ($db->inTransaction()) throw new WireException('Cockpit transfer cannot run inside another transaction.');
			$db->beginTransaction();
			try {
				$result = $operation();
				$db->commit();
				return $result;
			} catch (\Throwable $exception) {
				if ($db->inTransaction()) $db->rollBack();
				throw $exception;
			}
		};
		return ['validate' => $validate, 'find' => $find, 'write' => $write, 'transaction' => $transaction];
	}

	public function getPageConflict(string $path): ?array {
		$path = $this->normalizePath($path);
		if ($path === '') return null;
		$page = $this->findConflictingPage($path);
		if (!$page) return null;
		return [
			'id' => (int)$page->id,
			'path' => (string)$page->path,
			'title' => (string)$page->title,
		];
	}

	/**
	 * Create or update a link. Returns the link ID.
	 *
	 * @throws WireException when validation fails.
	 */
	public function saveLink(array $data, bool $deferCachePurge = false): int {
		$id = (int)($data['id'] ?? 0);
		$oldLink = $id > 0 ? $this->findLinkById($id) : null;
		$rawPath = (string)($data['path'] ?? '');
		$path = trim($rawPath, '/ ') === '' ? $this->generatePath() : $this->normalizePath($rawPath);
		$data['id'] = $id;
		$data['path'] = $path;
		$candidate = $this->validateLinkCandidate($data);
		$path = $candidate['path'];
		$target = $candidate['target_url'];
		$status = $candidate['redirect_status'];
		$enabled = $candidate['enabled'] ? 1 : 0;
		$now = date('Y-m-d H:i:s');
		$db = $this->wire('database');

		if ($id > 0) {
			$stmt = $db->prepare(
				'UPDATE ' . $this->linksTable() . ' SET `path`=:path, `target_url`=:target, '
				. '`redirect_status`=:status, `enabled`=:enabled, `updated_at`=:updated WHERE `id`=:id'
			);
			$stmt->execute([
				':path' => $path,
				':target' => $target,
				':status' => $status,
				':enabled' => $enabled,
				':updated' => $now,
				':id' => $id,
			]);
			if (!$this->findLinkById($id)) throw new WireException($this->_('Short link not found.'));
			if (!$deferCachePurge) $this->purgeRouteCaches(array_filter([(string)($oldLink['path'] ?? ''), $path]));
			return $id;
		}

		$stmt = $db->prepare(
			'INSERT INTO ' . $this->linksTable()
			. ' (`path`,`target_url`,`redirect_status`,`enabled`,`created_at`,`updated_at`) '
			. 'VALUES (:path,:target,:status,:enabled,:created,:updated)'
		);
		$stmt->execute([
			':path' => $path,
			':target' => $target,
			':status' => $status,
			':enabled' => $enabled,
			':created' => $now,
			':updated' => $now,
		]);
		$id = (int)$db->lastInsertId();
		if (!$deferCachePurge) $this->purgeRouteCaches([$path]);
		return $id;
	}

	/** Validate without writing; used by reviewable imports and API callers. */
	public function validateLinkCandidate(array $data): array {
		$id = (int)($data['id'] ?? 0);
		$path = $this->normalizePath((string)($data['path'] ?? ''));
		if (strlen($path) > 191 || !preg_match('#^[a-z0-9._~-]+(?:/[a-z0-9._~-]+)*$#', $path)) {
			throw new WireException($this->_('Use a relative path made from letters, numbers, dots, hyphens, underscores, and slashes.'));
		}
		$enabled = !empty($data['enabled']) ? 1 : 0;
		if ($enabled) $this->assertPathIsAvailableForRedirect($path);

		$target = trim((string)($data['target_url'] ?? ''));
		if (!$this->isAllowedTargetUrl($target)) {
			throw new WireException($this->_('Enter a valid absolute http:// or https:// target URL.'));
		}
		if ($enabled) $this->assertNoActiveRedirectCycle($path, $target, $id);

		$status = (int)($data['redirect_status'] ?? $this->getDefaultRedirectStatus());
		if (!in_array($status, [301, 302, 307, 308], true)) $status = $this->getDefaultRedirectStatus();
		$db = $this->wire('database');

		$duplicate = $db->prepare(
			'SELECT `id` FROM ' . $this->linksTable() . ' WHERE `path`=:path AND `id`<>:id LIMIT 1'
		);
		$duplicate->execute([':path' => $path, ':id' => $id]);
		if ($duplicate->fetchColumn()) {
			throw new WireException(sprintf($this->_('The path “/%s” is already in use.'), $path));
		}
		return ['id' => $id, 'path' => $path, 'target_url' => $target, 'redirect_status' => $status, 'enabled' => (bool)$enabled];
	}

	public function deleteLink(int $id): void {
		if ($id < 1) return;
		$link = $this->findLinkById($id);
		$db = $this->wire('database');
		$db->beginTransaction();
		try {
			$stmt = $db->prepare('DELETE FROM ' . $this->statsTable() . ' WHERE `link_id`=:id');
			$stmt->execute([':id' => $id]);
			$stmt = $db->prepare('DELETE FROM ' . $this->linksTable() . ' WHERE `id`=:id');
			$stmt->execute([':id' => $id]);
			$db->commit();
			if ($link) $this->purgeRouteCaches([(string)$link['path']]);
		} catch (\Throwable $exception) {
			if ($db->inTransaction()) $db->rollBack();
			throw $exception;
		}
	}

	public function getDashboardTotals(): array {
		$db = $this->wire('database');
		$linkTotals = $db->query(
			'SELECT COUNT(*) AS links, COALESCE(SUM(`enabled`=1),0) AS active_links, '
			. 'COALESCE(SUM(`enabled`=0),0) AS disabled_links, COALESCE(SUM(`hits`),0) AS total '
			. 'FROM ' . $this->linksTable()
		)->fetch(\PDO::FETCH_ASSOC) ?: [];
		$today = date('Y-m-d 00:00:00');
		$sevenDays = date('Y-m-d 00:00:00', strtotime('-6 days'));
		$thirtyDays = date('Y-m-d 00:00:00', strtotime('-29 days'));

		$stmt = $db->prepare(
			'SELECT '
			. 'SUM(CASE WHEN `click_date`>=:today THEN `clicks` ELSE 0 END) AS today, '
			. 'SUM(CASE WHEN `click_date`>=:seven THEN `clicks` ELSE 0 END) AS seven_days, '
			. 'SUM(CASE WHEN `click_date`>=:thirty THEN `clicks` ELSE 0 END) AS thirty_days '
			. 'FROM ' . $this->statsTable()
		);
		$stmt->execute([
			':today' => substr($today, 0, 10),
			':seven' => substr($sevenDays, 0, 10),
			':thirty' => substr($thirtyDays, 0, 10),
		]);
		$periods = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

		return [
			'links' => (int)($linkTotals['links'] ?? 0),
			'active_links' => (int)($linkTotals['active_links'] ?? 0),
			'disabled_links' => (int)($linkTotals['disabled_links'] ?? 0),
			'total' => (int)($linkTotals['total'] ?? 0),
			'today' => (int)($periods['today'] ?? 0),
			'seven_days' => (int)($periods['seven_days'] ?? 0),
			'thirty_days' => (int)($periods['thirty_days'] ?? 0),
		];
	}

	/**
	 * Return chronological click buckets for the requested grouping.
	 */
	public function getStatistics(string $group, int $linkId = 0): array {
		switch ($group) {
			case 'week':
				$from = (new \DateTimeImmutable('monday this week -11 weeks'))->format('Y-m-d');
				break;
			case 'month':
				$from = (new \DateTimeImmutable('first day of this month -11 months'))->format('Y-m-d');
				break;
			case 'day':
			default:
				$group = 'day';
				$from = (new \DateTimeImmutable('today -29 days'))->format('Y-m-d');
				break;
		}
		$result = $this->getAnalytics([
			'preset' => 'custom',
			'date_from' => $from,
			'date_to' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
			'group' => $group,
			'link_id' => $linkId,
		]);
		return $result['buckets'];
	}

	/**
	 * Return bounded, privacy-minimal analytics for the admin and trusted API.
	 *
	 * @return array<string,mixed>
	 */
	public function getAnalytics(array $filters = []): array {
		$normalized = $this->normalizeAnalyticsFilters($filters);
		$from = new \DateTimeImmutable($normalized['date_from']);
		$to = new \DateTimeImmutable($normalized['date_to']);
		$dayCount = (int)$from->diff($to)->days + 1;
		$group = $normalized['group'];
		if ($group === 'auto') {
			$group = $dayCount <= 45 ? 'day' : ($dayCount <= 180 ? 'week' : 'month');
		}
		$normalized['resolved_group'] = $group;

		$dailyRows = $this->queryAnalyticsDaily($normalized, $normalized['date_from'], $normalized['date_to']);
		$buckets = $this->aggregateAnalyticsBuckets($dailyRows, $group, $from, $to);
		$total = array_sum(array_column($buckets, 'clicks'));
		$peak = ['bucket' => '', 'clicks' => 0];
		foreach ($buckets as $bucket) {
			if ((int)$bucket['clicks'] > (int)$peak['clicks']) $peak = $bucket;
		}

		$previousTo = $from->modify('-1 day');
		$previousFrom = $previousTo->modify('-' . ($dayCount - 1) . ' days');
		$previousRows = $this->queryAnalyticsDaily($normalized, $previousFrom->format('Y-m-d'), $previousTo->format('Y-m-d'));
		$previousBuckets = $this->aggregateAnalyticsBuckets($previousRows, $group, $previousFrom, $previousTo);
		$previousTotal = array_sum(array_map(static function(array $row): int { return (int)$row['clicks']; }, $previousRows));
		$changePercent = $previousTotal > 0 ? round((($total - $previousTotal) / $previousTotal) * 100, 1) : null;

		return [
			'filters' => $normalized,
			'buckets' => $buckets,
			'previous_buckets' => $previousBuckets,
			'shares' => $this->queryAnalyticsShares($normalized),
			'summary' => [
				'clicks' => $total,
				'previous_clicks' => $previousTotal,
				'previous_date_from' => $previousFrom->format('Y-m-d'),
				'previous_date_to' => $previousTo->format('Y-m-d'),
				'change_percent' => $changePercent,
				'average_per_day' => $dayCount > 0 ? round($total / $dayCount, 1) : 0.0,
				'peak_bucket' => (string)$peak['bucket'],
				'peak_clicks' => (int)$peak['clicks'],
				'days' => $dayCount,
			],
		];
	}

	public function shortUrl(string $path): string {
		$root = trim((string)$this->get('public_base_url'));
		$scheme = strtolower((string)parse_url($root, PHP_URL_SCHEME));
		if (!filter_var($root, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
			$root = (string)$this->wire('config')->urls->httpRoot;
		}
		$root = rtrim($root, '/');
		$encoded = implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
		$url = $root . '/' . $encoded;
		return $this->settingBool('append_trailing_slash', false) ? $url . '/' : $url;
	}

	public function getBasePath(): string {
		$value = strtolower(trim((string)$this->get('base_path'), '/ '));
		$value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
		return $value !== '' ? $value : 'go';
	}

	public function getDefaultRedirectStatus(): int {
		$status = (int)$this->get('default_redirect_status');
		return in_array($status, [301, 302, 307, 308], true) ? $status : 302;
	}

	public function getGeneratedCodeLength(): int {
		$length = (int)$this->get('generated_code_length');
		return max(3, min(24, $length ?: 5));
	}

	public function newLinksEnabledByDefault(): bool {
		return $this->settingBool('new_links_enabled', true);
	}

	public function normalizePath(string $path): string {
		return \CockpitPathPolicy::canonicalize($path) ?? '';
	}

	public function generatePath(int $length = 0): string {
		if ($length < 1) $length = $this->getGeneratedCodeLength();
		$alphabet = '23456789abcdefghjkmnpqrstuvwxyz';
		$last = strlen($alphabet) - 1;
		for ($attempt = 0; $attempt < 30; $attempt++) {
			$code = '';
			for ($i = 0; $i < $length; $i++) $code .= $alphabet[random_int(0, $last)];
			$path = $this->getBasePath() . '/' . $code;
			if (!$this->findLinkByPath($path)) return $path;
		}
		throw new WireException($this->_('Unable to generate a unique short path.'));
	}

	public function ___install(): void {
		$this->getSchemaManager()->installOrUpgrade();
	}

	public function ___upgrade($fromVersion, $toVersion): void {
		$this->getSchemaManager()->installOrUpgrade();
	}

	public function ___uninstall(): void {
		if (!$this->get('delete_data_on_uninstall')) return;
		$db = $this->wire('database');
		$db->exec('DROP TABLE IF EXISTS `' . $this->tablePrefix() . 'cockpit_audit_log`');
		$db->exec('DROP TABLE IF EXISTS ' . $this->statsTable());
		$db->exec('DROP TABLE IF EXISTS ' . $this->linksTable());
		$db->exec('DROP TABLE IF EXISTS `' . $this->tablePrefix() . 'cockpit_schema`');
	}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper {
		$this->configureSettingsPageChrome();
		$modules = $this->wire('modules');
		$checkbox = function(string $name, string $label, string $description, bool $default = false, int $width = 100) use ($modules) {
			$field = $modules->get('InputfieldCheckbox');
			$field->attr('name', $name);
			$field->attr('value', 1);
			$field->attr('checked', $this->settingBool($name, $default) ? 'checked' : '');
			$field->label = $label;
			$field->description = $description;
			$field->columnWidth = $width;
			return $field;
		};
		$section = function(string $label, string $icon) use ($modules) {
			$field = $modules->get('InputfieldFieldset');
			$field->label = $label;
			$field->icon = $icon;
			$field->collapsed = Inputfield::collapsedNo;
			return $field;
		};
		$notice = function(InputfieldWrapper $target, string $title, string $text, string $type = 'primary', string $action = '', string $actionLabel = '') use ($modules): void {
			$field = $modules->get('InputfieldMarkup');
			$field->label = $title;
			$field->skipLabel = Inputfield::skipLabelHeader;
			$field->value = '<div class="uk-alert-' . $type . ' uk-margin-small" uk-alert><strong>' . $this->wire('sanitizer')->entities($title)
				. '</strong><p>' . $this->wire('sanitizer')->entities($text) . '</p>';
			if ($action !== '' && $actionLabel !== '') {
				$field->value .= '<a class="uk-button uk-button-default uk-button-small" href="'
					. $this->wire('sanitizer')->entities($action) . '">' . $this->wire('sanitizer')->entities($actionLabel) . '</a>';
			}
			$field->value .= '</div>';
			$field->columnWidth = 100;
			$target->add($field);
		};

		$links = $section($this->_('Links'), 'link');
		$inputfields->add($links);
		$workspaceUrl = rtrim((string)$this->wire('config')->urls->admin, '/') . '/cockpit/';
		$notice(
			$links,
			$this->_('Link workspace'),
			$this->_('Create and edit short links in Cockpit. These settings define how new links and copied URLs behave.'),
			'primary',
			$workspaceUrl,
			$this->_('Open Cockpit')
		);

		$publicBaseUrl = $modules->get('InputfieldURL');
		$publicBaseUrl->attr('name', 'public_base_url');
		$publicBaseUrl->attr('value', (string)$this->get('public_base_url'));
		$publicBaseUrl->label = $this->_('Public base URL');
		$publicBaseUrl->description = $this->_('Canonical site origin used by CLI output and copied short URLs when no web request is available.');
		$publicBaseUrl->notes = $this->_('Example: https://example.com');
		$publicBaseUrl->icon = 'globe';
		$publicBaseUrl->columnWidth = 50;
		$links->add($publicBaseUrl);

		$path = $modules->get('InputfieldText');
		$path->attr('name', 'base_path');
		$path->attr('value', $this->getBasePath());
		$path->label = $this->_('Default prefix for generated paths');
		$path->description = $this->_('Used only when a link path is left empty. Custom paths are unaffected.');
		$path->notes = $this->_('Example: r creates generated paths such as /r/abc42.');
		$path->icon = 'folder-open';
		$path->required = true;
		$path->columnWidth = 30;
		$links->add($path);

		$length = $modules->get('InputfieldInteger');
		$length->attr('name', 'generated_code_length');
		$length->attr('value', $this->getGeneratedCodeLength());
		$length->attr('min', 3);
		$length->attr('max', 24);
		$length->label = $this->_('Generated code length');
		$length->description = $this->_('Characters generated after the prefix.');
		$length->notes = $this->_('Allowed range: 3–24.');
		$length->icon = 'arrows-h';
		$length->columnWidth = 20;
		$links->add($length);

		$status = $modules->get('InputfieldSelect');
		$status->attr('name', 'default_redirect_status');
		$status->attr('value', $this->getDefaultRedirectStatus());
		$status->label = $this->_('Default redirect status');
		$status->description = $this->_('Applied to new links; each link can override it.');
		$status->icon = 'exchange';
		$status->addOptions([
			302 => '302 — Temporary',
			301 => '301 — Permanent',
			307 => '307 — Temporary',
			308 => '308 — Permanent',
		]);
		$status->columnWidth = 33;
		$links->add($status);
		$links->add($checkbox('new_links_enabled', $this->_('Enable new links by default'), $this->_('New links created in the admin or CLI start active.'), true, 33));
		$links->add($checkbox('append_trailing_slash', $this->_('Show trailing slash in generated URLs'), $this->_('Both URL forms are accepted; this changes only displayed and copied URLs.'), false, 34));

		$routing = $section($this->_('Routing'), 'random');
		$inputfields->add($routing);
		$allowAny = $this->settingBool('allow_any_public_target_host', false);
		$allowedConfigured = trim((string)$this->get('allowed_target_hosts')) !== '';
		$notice(
			$routing,
			$this->_('Route ownership and destination policy'),
			$allowAny
				? $this->_('Any public HTTP(S) host is currently allowed. Cockpit still blocks private networks, localhost, userinfo, unsafe schemes, and the denylist.')
				: ($allowedConfigured
					? $this->_('Destinations are restricted to the configured allowlist. Cockpit also checks pages, history, API namespaces, and installed redirect modules before saving a route.')
					: $this->_('Destination policy is fail-closed: new targets are rejected until an allowlist is configured or public hosts are explicitly enabled.')),
			$allowAny ? 'warning' : 'primary'
		);

		$reserved = $modules->get('InputfieldTextarea');
		$reserved->attr('name', 'reserved_paths');
		$reserved->attr('value', (string)$this->get('reserved_paths'));
		$reserved->attr('rows', 4);
		$reserved->label = $this->_('Additional reserved paths');
		$reserved->description = $this->_('Protect project routes that cannot be discovered automatically. Enter one relative path or wildcard per line.');
		$reserved->notes = $this->_('Examples: api/*, checkout/*, webhooks/payment. ProcessWire pages, admin, /wire, and /site are always protected.');
		$reserved->icon = 'ban';
		$routing->add($reserved);

		$allowedHosts = $modules->get('InputfieldTextarea');
		$allowedHosts->attr('name', 'allowed_target_hosts');
		$allowedHosts->attr('value', (string)$this->get('allowed_target_hosts'));
		$allowedHosts->attr('rows', 3);
		$allowedHosts->label = $this->_('Allowed destination hosts');
		$allowedHosts->description = $this->_('Allowlist, one host per line. Wildcard subdomains are supported.');
		$allowedHosts->notes = $this->_('Examples: example.com or *.example.com. Leave empty only when public hosts are explicitly allowed below.');
		$allowedHosts->icon = 'check-circle';
		$allowedHosts->columnWidth = 50;
		$routing->add($allowedHosts);

		$blockedHosts = $modules->get('InputfieldTextarea');
		$blockedHosts->attr('name', 'blocked_target_hosts');
		$blockedHosts->attr('value', (string)$this->get('blocked_target_hosts'));
		$blockedHosts->attr('rows', 3);
		$blockedHosts->label = $this->_('Blocked destination hosts');
		$blockedHosts->description = $this->_('Optional denylist applied after the allowlist. Enter one host per line.');
		$blockedHosts->notes = $this->_('Use this to exclude a host or wildcard that would otherwise be permitted.');
		$blockedHosts->icon = 'minus-circle';
		$blockedHosts->columnWidth = 50;
		$routing->add($blockedHosts);
		$routing->add($checkbox('forward_query_string', $this->_('Forward query parameters'), $this->_('Append client parameters such as ?utm_source=qr to the destination.'), false, 33));
		$routing->add($checkbox('send_no_cache_headers', $this->_('Disable redirect caching'), $this->_('Send no-store and cache-bypass headers so requests reach Cockpit and counters remain accurate.'), true, 33));
		$allowAnyField = $checkbox(
			'allow_any_public_target_host',
			$this->_('Allow any public HTTP(S) host'),
			$this->_('High-trust mode. Private networks, localhost, unsafe schemes, userinfo, and blocked hosts remain rejected.'),
			false,
			34
		);
		$allowAnyField->notes = $this->_('Prefer an explicit allowlist for production sites.');
		$routing->add($allowAnyField);

		$statistics = $section($this->_('Analytics'), 'bar-chart');
		$inputfields->add($statistics);
		$notice(
			$statistics,
			$this->_('Aggregate, privacy-minimal analytics'),
			$this->_('Cockpit stores only per-link daily totals and lifetime counters. It does not store visitor IP addresses, cookies, or User-Agent strings.'),
			'primary',
			$workspaceUrl . '?view=analytics',
			$this->_('Open analytics')
		);
		$statistics->add($checkbox('statistics_enabled', $this->_('Record click statistics'), $this->_('Redirects continue to work when statistics are disabled.'), true, 33));
		$statistics->add($checkbox('ignore_known_bots', $this->_('Ignore bots and link previews'), $this->_('Exclude common crawlers, uptime monitors, and social preview requests.'), true, 33));
		$statistics->add($checkbox('count_head_requests', $this->_('Count HEAD requests'), $this->_('Leave disabled to count only real GET redirects.'), false, 34));

		$integrations = $section($this->_('Integrations'), 'plug');
		$inputfields->add($integrations);
		$providerStatus = $this->getCodeProviderStatus()['fieldtype-qrcode'] ?? ['available' => false];
		$providerVersion = (string)($providerStatus['provider_version_label'] ?? '');
		$fieldtypeInstalled = $this->wire('modules')->isInstalled('FieldtypeQRCode');
		if (!empty($providerStatus['available'])) {
			$integrationText = sprintf(
				$this->_('FieldtypeQRCode %s is active. Cockpit generates verified QR images for canonical short URLs while FieldtypeQRCode remains the QR engine.'),
				$providerVersion !== '' ? $providerVersion : $this->_('compatible')
			);
			$integrationType = 'success';
			$integrationAction = rtrim((string)$this->wire('config')->urls->admin, '/') . '/module/edit?name=FieldtypeQRCode&collapse_info=1';
			$integrationActionLabel = $this->_('Configure FieldtypeQRCode');
		} elseif ($fieldtypeInstalled) {
			$integrationText = sprintf(
				$this->_('FieldtypeQRCode %s is installed but is not compatible with the Cockpit adapter. Short links continue to work without QR generation.'),
				$providerVersion !== '' ? $providerVersion : $this->_('unknown')
			);
			$integrationType = 'warning';
			$integrationAction = 'https://github.com/eprcstudio/FieldtypeQRCode/releases';
			$integrationActionLabel = $this->_('Review provider releases');
		} else {
			$integrationText = $this->_('FieldtypeQRCode is not installed. Cockpit redirects and analytics work normally; install the provider only when QR generation is needed.');
			$integrationType = 'primary';
			$integrationAction = 'https://processwire.com/modules/fieldtype-qrcode/';
			$integrationActionLabel = $this->_('View FieldtypeQRCode');
		}
		$notice(
			$integrations,
			$this->_('QR codes via FieldtypeQRCode'),
			$integrationText . ' ' . $this->_('Provider credit: EPRC / Romain Cazier, MIT license.'),
			$integrationType,
			$integrationAction,
			$integrationActionLabel
		);

		$data = $section($this->_('Data & privacy'), 'shield');
		$inputfields->add($data);
		$notice(
			$data,
			$this->_('Retention and exclusions'),
			$this->_('Exclusions are evaluated during the request and are never written to Cockpit statistics. Lifetime totals remain after daily buckets are pruned.'),
			'primary'
		);
		$excludedIps = $modules->get('InputfieldTextarea');
		$excludedIps->attr('name', 'excluded_ips');
		$excludedIps->attr('value', (string)$this->get('excluded_ips'));
		$excludedIps->attr('rows', 3);
		$excludedIps->label = $this->_('IP addresses excluded from statistics');
		$excludedIps->description = $this->_('One exact IPv4 or IPv6 address per line. Addresses are compared during the request and are never stored.');
		$excludedIps->notes = $this->_('Useful for office, QA, and uptime-monitor traffic.');
		$excludedIps->icon = 'filter';
		$excludedIps->columnWidth = 65;
		$data->add($excludedIps);

		$retention = $modules->get('InputfieldInteger');
		$retention->attr('name', 'statistics_retention_days');
		$retention->attr('value', (int)$this->get('statistics_retention_days'));
		$retention->attr('min', 0);
		$retention->attr('max', 3650);
		$retention->label = $this->_('Daily statistics retention');
		$retention->description = $this->_('Days to keep daily buckets. Use 0 to keep them forever.');
		$retention->notes = $this->_('Cleanup runs daily with LazyCron or manually from CLI. Lifetime totals remain intact.');
		$retention->icon = 'calendar';
		$retention->columnWidth = 35;
		$data->add($retention);

		$removal = $modules->get('InputfieldFieldset');
		$removal->label = $this->_('Destructive uninstall option');
		$removal->icon = 'warning';
		$removal->collapsed = Inputfield::collapsedYes;
		$data->add($removal);
		$notice(
			$removal,
			$this->_('Permanent data deletion'),
			$this->_('Enabling the option below makes a future Cockpit uninstall permanently delete links, statistics, schema metadata, and audit events.'),
			'danger'
		);
		$remove = $modules->get('InputfieldCheckbox');
		$remove->attr('name', 'delete_data_on_uninstall');
		$remove->attr('value', 1);
		$remove->attr('checked', $this->get('delete_data_on_uninstall') ? 'checked' : '');
		$remove->label = $this->_('Delete links and statistics when uninstalling');
		$remove->description = $this->_('Leave disabled to preserve all Cockpit data during temporary removal or rollback.');
		$remove->notes = $this->_('This setting does not delete anything until the module is actually uninstalled.');
		$removal->add($remove);

		return $inputfields;
	}

	/** Configure ProcessModule chrome only when rendering Cockpit settings. */
	protected function configureSettingsPageChrome(): void {
		$process = $this->wire('process');
		if (!$process instanceof ProcessModule) return;
		if ((string)$this->wire('input')->get->name !== 'Cockpit') return;

		$adminUrl = rtrim((string)$this->wire('config')->urls->admin, '/') . '/';
		$workspaceUrl = $adminUrl . 'cockpit/';
		$settingsUrl = $adminUrl . 'module/edit?name=Cockpit&collapse_info=1';
		$process->headline($this->_('Cockpit settings'));
		$process->browserTitle($this->_('Cockpit settings'));
		$process->breadcrumb($workspaceUrl, $this->_('Cockpit'));
		$process->breadcrumb($settingsUrl, $this->_('Settings'));
	}

	protected function recordClick(int $linkId): void {
		$db = $this->wire('database');
		$now = date('Y-m-d H:i:s');
		$today = substr($now, 0, 10);
		$db->beginTransaction();
		try {
			$stmt = $db->prepare(
				'INSERT INTO ' . $this->statsTable() . ' (`link_id`,`click_date`,`clicks`) VALUES (:link_id,:click_date,1) '
				. 'ON DUPLICATE KEY UPDATE `clicks`=`clicks`+1'
			);
			$stmt->execute([':link_id' => $linkId, ':click_date' => $today]);
			$stmt = $db->prepare(
				'UPDATE ' . $this->linksTable() . ' SET `hits`=`hits`+1, `last_hit_at`=:hit_at WHERE `id`=:id'
			);
			$stmt->execute([':hit_at' => $now, ':id' => $linkId]);
			$db->commit();
		} catch (\Throwable $exception) {
			if ($db->inTransaction()) $db->rollBack();
			throw $exception;
		}
	}

	protected function isAllowedTargetUrl(string $url): bool {
		if (preg_match('/[\x00-\x1F\x7F]/', $url)) return false;
		if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
		$parts = parse_url($url);
		if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return false;
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if (!in_array($scheme, ['http', 'https'], true)) return false;

		$host = $this->canonicalHost((string)($parts['host'] ?? ''));
		if ($host === '') return false;
		$ip = filter_var($host, FILTER_VALIDATE_IP);
		if ($ip !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return false;
		}
		if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host)) return false;
		if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local') return false;
		$allowed = $this->settingLines('allowed_target_hosts');
		$blocked = $this->settingLines('blocked_target_hosts');
		if (!$allowed && !$this->settingBool('allow_any_public_target_host', false)) return false;
		if ($allowed && !$this->hostMatchesAny($host, $allowed)) return false;
		if ($blocked && $this->hostMatchesAny($host, $blocked)) return false;
		return true;
	}

	protected function assertNoActiveRedirectCycle(string $path, string $target, int $currentId = 0): void {
		$nextPath = $this->localPathFromUrl($target);
		if ($nextPath === null) return;

		$visited = [$path => true];
		for ($depth = 0; $depth < 32; $depth++) {
			if (isset($visited[$nextPath])) {
				throw new WireException($this->_('The target creates a redirect loop with this or another Cockpit link.'));
			}
			$visited[$nextPath] = true;
			$link = $this->findLinkByPath($nextPath);
			if (!$link || empty($link['enabled'])) return;
			if ($currentId > 0 && (int)$link['id'] === $currentId) return;
			$nextPath = $this->localPathFromUrl((string)$link['target_url']);
			if ($nextPath === null) return;
		}

		throw new WireException($this->_('The target creates a redirect chain that is too long to verify safely.'));
	}

	protected function localPathFromUrl(string $url): ?string {
		$parts = parse_url($url);
		if (!is_array($parts)) return null;
		$targetHost = $this->canonicalHost((string)($parts['host'] ?? ''));
		$configHost = (string)$this->wire('config')->httpHost;
		$parsedConfigHost = parse_url('http://' . ltrim($configHost, '/'), PHP_URL_HOST);
		$siteHost = $this->canonicalHost(is_string($parsedConfigHost) ? $parsedConfigHost : $configHost);
		if ($targetHost === '' || $targetHost !== $siteHost) return null;

		$path = (string)($parts['path'] ?? '/');
		$rootPath = trim((string)$this->wire('config')->urls->root, '/');
		if ($rootPath !== '') {
			$rootPrefix = '/' . $rootPath;
			if ($path === $rootPrefix) {
				$path = '/';
			} elseif (strpos($path, $rootPrefix . '/') === 0) {
				$path = substr($path, strlen($rootPrefix));
			} else {
				return null;
			}
		}

		return \CockpitPathPolicy::fromRequestPath($path);
	}

	protected function assertPathIsAvailableForRedirect(string $path): void {
		$firstSegment = explode('/', $path, 2)[0];
		if (in_array($firstSegment, ['wire', 'site'], true)) {
			throw new WireException($this->_('The /wire and /site paths are reserved by ProcessWire.'));
		}

		$adminPath = strtolower(trim((string)$this->wire('config')->urls->admin, '/'));
		if ($adminPath !== '' && ($path === $adminPath || strpos($path, $adminPath . '/') === 0)) {
			throw new WireException($this->_('The ProcessWire admin path cannot be used as a short link.'));
		}

		$matchedPattern = $this->pathMatchesReservedPattern($path);
		if ($matchedPattern !== null) {
			throw new WireException(sprintf($this->_('The path “/%s” matches reserved pattern “%s”.'), $path, $matchedPattern));
		}

		$inspection = $this->inspectRoute($path);
		if (!$inspection->isConclusive()) {
			$sources = array_column($inspection->errors(), 'source');
			throw new WireException(sprintf($this->_('Unable to verify route ownership: %s.'), implode(', ', $sources)));
		}
		if ($inspection->hasClaims()) {
			$claim = $inspection->owner();
			throw new WireException(sprintf(
				$this->_('The path “/%s” is owned by %s (%s: %s).'),
				$path,
				$claim ? $claim->owner() : 'unknown',
				$claim ? $claim->kind() : 'unknown',
				$claim ? $claim->pattern() : 'unknown'
			));
		}
	}

	protected function purgeRouteCaches(array $paths): void {
		try {
			$result = $this->getRouteCacheIntegration()->purgePaths($paths);
			foreach ($result->errors() as $error) {
				$this->wire('log')->save('cockpit', 'Cache purge failed for route: ' . (string)($error['error'] ?? 'unknown error'));
			}
		} catch (\Throwable $exception) {
			$this->wire('log')->save('cockpit', 'Cache purge integration failed: ' . $exception->getMessage());
		}
	}

	/**
	 * Return an exact page or a page that handles this path through URL segments.
	 */
	protected function findConflictingPage(string $path) {
		try {
			$page = $this->wire('pages')->getByPath('/' . trim($path, '/') . '/', [
				'allowUrl' => false,
				'allowPartial' => false,
				'allowUrlSegments' => true,
				'useLanguages' => true,
				'useHistory' => true,
			]);
			return $page && $page->id ? $page : null;
		} catch (\Throwable $exception) {
			$this->wire('log')->save('cockpit', 'Page conflict check failed: ' . $exception->getMessage());
			// Fail closed: an unavailable conflict check must not create a risky route.
			throw new WireException($this->_('Unable to verify that the path is free. Please try again.'));
		}
	}

	protected function pathMatchesReservedPattern(string $path): ?string {
		foreach ($this->settingLines('reserved_paths') as $pattern) {
			$pattern = strtolower(trim($pattern, '/ '));
			if ($pattern === '') continue;
			$regex = '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . '$~';
			if (preg_match($regex, $path)) return $pattern;
		}
		return null;
	}

	protected function shouldRecordRequest(string $method): bool {
		if (!$this->settingBool('statistics_enabled', true)) return false;
		if ($method === 'HEAD' && !$this->settingBool('count_head_requests', false)) return false;

		if ($this->settingBool('ignore_known_bots', true)) {
			$userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
			if ($userAgent !== '' && preg_match('/bot|crawler|spider|slurp|preview|facebookexternalhit|whatsapp|telegrambot|discordbot|linkedinbot|uptimerobot/i', $userAgent)) {
				return false;
			}
		}

		$excludedIps = $this->settingLines('excluded_ips');
		if ($excludedIps) {
			$ip = trim((string)$this->wire('session')->getIP());
			if (in_array($ip, $excludedIps, true)) return false;
		}
		return true;
	}

	protected function redirectTarget(string $target): string {
		if (!$this->settingBool('forward_query_string', false)) return $target;
		$query = $this->forwardableQueryString((string)($_SERVER['QUERY_STRING'] ?? ''));
		if ($query === '') return $target;

		$fragment = '';
		if (strpos($target, '#') !== false) {
			[$target, $fragment] = explode('#', $target, 2);
			$fragment = '#' . $fragment;
		}
		return $target . (strpos($target, '?') === false ? '?' : '&') . $query . $fragment;
	}

	/**
	 * Remove ProcessWire's internal Apache rewrite route from a client query.
	 * Preserve the original encoding, ordering, and duplicate client parameters.
	 */
	protected function forwardableQueryString(string $query): string {
		if ($query === '' || preg_match('/[\x00-\x1F\x7F]/', $query)) return '';
		$forward = [];
		foreach (explode('&', $query) as $part) {
			if ($part === '') continue;
			$key = explode('=', $part, 2)[0];
			if (strtolower(rawurldecode($key)) === 'it') continue;
			$forward[] = $part;
		}
		return implode('&', $forward);
	}

	public function hookPruneStatistics(HookEvent $event): void {
		try {
			$this->pruneOldStatistics();
		} catch (\Throwable $exception) {
			$this->wire('log')->save('cockpit', 'Statistics cleanup failed: ' . $exception->getMessage());
		}
	}

	public function pruneOldStatistics(int $days = 0): int {
		if ($days < 1) $days = (int)$this->get('statistics_retention_days');
		if ($days < 1) return 0;
		$days = max(1, min(3650, $days));
		$cutoff = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
		$stmt = $this->wire('database')->prepare('DELETE FROM ' . $this->statsTable() . ' WHERE `click_date`<:cutoff');
		$stmt->execute([':cutoff' => $cutoff]);
		return (int)$stmt->rowCount();
	}

	protected function settingBool(string $name, bool $default): bool {
		$value = $this->get($name);
		return $value === null || $value === '' ? $default : (bool)$value;
	}

	protected function settingLines(string $name): array {
		$value = trim((string)$this->get($name));
		if ($value === '') return [];
		$items = preg_split('/[\r\n,]+/', $value);
		return array_values(array_unique(array_filter(array_map('trim', $items), static function($item) {
			return $item !== '';
		})));
	}

	protected function hostMatchesAny(string $host, array $patterns): bool {
		$host = $this->canonicalHost($host);
		foreach ($patterns as $pattern) {
			$pattern = strtolower(trim((string)$pattern));
			if ($pattern === '') continue;
			$wildcard = strpos($pattern, '*.') === 0;
			$pattern = $this->canonicalHost($wildcard ? substr($pattern, 2) : $pattern);
			if ($pattern === '') continue;
			if ($pattern === $host) return true;
			if ($wildcard) {
				$suffix = '.' . $pattern;
				if (substr($host, -strlen($suffix)) === $suffix) return true;
			}
		}
		return false;
	}

	protected function canonicalHost(string $host): string {
		$host = strtolower(rtrim(trim($host, " []\t\n\r\0\x0B"), '.'));
		if ($host === '') return '';
		if (function_exists('idn_to_ascii') && !filter_var($host, FILTER_VALIDATE_IP)) {
			$flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
			$variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
			$ascii = idn_to_ascii($host, $flags, $variant);
			if (is_string($ascii) && $ascii !== '') $host = strtolower($ascii);
		}
		return $host;
	}

	/**
	 * Add zero-value periods so the chart always presents a continuous timeline.
	 */
	protected function fillStatisticsBuckets(array $rows, string $group): array {
		$counts = [];
		foreach ($rows as $row) $counts[(string)$row['bucket']] = (int)$row['clicks'];

		$result = [];
		if ($group === 'month') {
			$cursor = new \DateTimeImmutable('first day of this month -11 months');
			for ($i = 0; $i < 12; $i++) {
				$bucket = $cursor->modify('+' . $i . ' months')->format('Y-m');
				$result[] = ['bucket' => $bucket, 'clicks' => $counts[$bucket] ?? 0];
			}
			return $result;
		}

		if ($group === 'week') {
			$cursor = new \DateTimeImmutable('monday this week -11 weeks');
			for ($i = 0; $i < 12; $i++) {
				$bucket = $cursor->modify('+' . $i . ' weeks')->format('Y-m-d');
				$result[] = ['bucket' => $bucket, 'clicks' => $counts[$bucket] ?? 0];
			}
			return $result;
		}

		$cursor = new \DateTimeImmutable('today -29 days');
		for ($i = 0; $i < 30; $i++) {
			$bucket = $cursor->modify('+' . $i . ' days')->format('Y-m-d');
			$result[] = ['bucket' => $bucket, 'clicks' => $counts[$bucket] ?? 0];
		}
		return $result;
	}

	/** @return array<string,mixed> */
	protected function normalizeAnalyticsFilters(array $filters): array {
		$today = new \DateTimeImmutable('today');
		$preset = strtolower((string)($filters['preset'] ?? '30d'));
		if (!in_array($preset, ['today', '7d', '30d', '90d', '12m', 'custom'], true)) $preset = '30d';
		$presets = [
			'today' => [$today, $today],
			'7d' => [$today->modify('-6 days'), $today],
			'30d' => [$today->modify('-29 days'), $today],
			'90d' => [$today->modify('-89 days'), $today],
			'12m' => [$today->modify('first day of this month -11 months'), $today],
		];
		if ($preset === 'custom') {
			$from = $this->parseAnalyticsDate((string)($filters['date_from'] ?? '')) ?? $today->modify('-29 days');
			$to = $this->parseAnalyticsDate((string)($filters['date_to'] ?? '')) ?? $today;
			if ($from > $to) [$from, $to] = [$to, $from];
			if ($to > $today) $to = $today;
			if ($from > $to) $from = $to;
			if ((int)$from->diff($to)->days > 365) $from = $to->modify('-365 days');
		} else {
			[$from, $to] = $presets[$preset];
		}

		$group = strtolower((string)($filters['group'] ?? 'auto'));
		if (!in_array($group, ['auto', 'day', 'week', 'month'], true)) $group = 'auto';
		$state = strtolower((string)($filters['state'] ?? 'all'));
		if (!in_array($state, ['all', 'active', 'disabled'], true)) $state = 'all';
		$status = (int)($filters['status'] ?? 0);
		if (!in_array($status, [301, 302, 307, 308], true)) $status = 0;
		$linkId = max(0, (int)($filters['link_id'] ?? 0));
		if ($linkId > 0 && !$this->findLinkById($linkId)) $linkId = 0;

		return [
			'preset' => $preset,
			'date_from' => $from->format('Y-m-d'),
			'date_to' => $to->format('Y-m-d'),
			'group' => $group,
			'link_id' => $linkId,
			'state' => $state,
			'status' => $status,
		];
	}

	protected function parseAnalyticsDate(string $value): ?\DateTimeImmutable {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		$errors = \DateTimeImmutable::getLastErrors();
		if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) return null;
		return $date;
	}

	/** @return array{0:string,1:array<string,mixed>} */
	protected function analyticsWhere(array $filters, string $from, string $to): array {
		$where = ['s.`click_date`>=:date_from', 's.`click_date`<=:date_to'];
		$params = [':date_from' => $from, ':date_to' => $to];
		if ((int)$filters['link_id'] > 0) {
			$where[] = 's.`link_id`=:analytics_link_id';
			$params[':analytics_link_id'] = (int)$filters['link_id'];
		}
		if ($filters['state'] === 'active') $where[] = 'l.`enabled`=1';
		if ($filters['state'] === 'disabled') $where[] = 'l.`enabled`=0';
		if ((int)$filters['status'] > 0) {
			$where[] = 'l.`redirect_status`=:analytics_status';
			$params[':analytics_status'] = (int)$filters['status'];
		}
		return [implode(' AND ', $where), $params];
	}

	/** @return array<int,array{click_date:string,clicks:int}> */
	protected function queryAnalyticsDaily(array $filters, string $from, string $to): array {
		[$where, $params] = $this->analyticsWhere($filters, $from, $to);
		$sql = 'SELECT s.`click_date`, SUM(s.`clicks`) AS clicks FROM ' . $this->statsTable() . ' s '
			. 'INNER JOIN ' . $this->linksTable() . ' l ON l.`id`=s.`link_id` WHERE ' . $where
			. ' GROUP BY s.`click_date` ORDER BY s.`click_date` ASC';
		$stmt = $this->wire('database')->prepare($sql);
		$stmt->execute($params);
		return array_map(static function(array $row): array {
			return ['click_date' => (string)$row['click_date'], 'clicks' => (int)$row['clicks']];
		}, $stmt->fetchAll(\PDO::FETCH_ASSOC));
	}

	/** @return array<int,array{label:string,value:int}> */
	protected function queryAnalyticsShares(array $filters): array {
		[$where, $params] = $this->analyticsWhere($filters, $filters['date_from'], $filters['date_to']);
		$sql = 'SELECT l.`path`, SUM(s.`clicks`) AS clicks FROM ' . $this->statsTable() . ' s '
			. 'INNER JOIN ' . $this->linksTable() . ' l ON l.`id`=s.`link_id` WHERE ' . $where
			. ' GROUP BY l.`id`, l.`path` HAVING SUM(s.`clicks`)>0 ORDER BY clicks DESC LIMIT 6';
		$stmt = $this->wire('database')->prepare($sql);
		$stmt->execute($params);
		return array_map(static function(array $row): array {
			return ['label' => '/' . (string)$row['path'], 'value' => (int)$row['clicks']];
		}, $stmt->fetchAll(\PDO::FETCH_ASSOC));
	}

	/** @return array<int,array{bucket:string,clicks:int}> */
	protected function aggregateAnalyticsBuckets(array $rows, string $group, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
		$counts = [];
		foreach ($rows as $row) {
			$date = new \DateTimeImmutable((string)$row['click_date']);
			if ($group === 'month') $bucket = $date->format('Y-m');
			elseif ($group === 'week') $bucket = $date->modify('monday this week')->format('Y-m-d');
			else $bucket = $date->format('Y-m-d');
			$counts[$bucket] = ($counts[$bucket] ?? 0) + (int)$row['clicks'];
		}

		$result = [];
		if ($group === 'month') {
			$cursor = $from->modify('first day of this month');
			$last = $to->modify('first day of this month');
			while ($cursor <= $last) {
				$key = $cursor->format('Y-m');
				$result[] = ['bucket' => $key, 'clicks' => (int)($counts[$key] ?? 0)];
				$cursor = $cursor->modify('+1 month');
			}
			return $result;
		}
		if ($group === 'week') {
			$cursor = $from->modify('monday this week');
			$last = $to->modify('monday this week');
			while ($cursor <= $last) {
				$key = $cursor->format('Y-m-d');
				$result[] = ['bucket' => $key, 'clicks' => (int)($counts[$key] ?? 0)];
				$cursor = $cursor->modify('+1 week');
			}
			return $result;
		}
		for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
			$key = $cursor->format('Y-m-d');
			$result[] = ['bucket' => $key, 'clicks' => (int)($counts[$key] ?? 0)];
		}
		return $result;
	}

	protected function linksTable(): string {
		return '`' . $this->tablePrefix() . 'cockpit_links`';
	}

	protected function statsTable(): string {
		return '`' . $this->tablePrefix() . 'cockpit_daily_stats`';
	}

	protected function tablePrefix(): string {
		return preg_replace('/[^A-Za-z0-9_]/', '', (string)$this->wire('config')->dbPrefix);
	}
}
