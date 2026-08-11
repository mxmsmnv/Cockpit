<?php

/**
 * Cockpit CLI — link management and statistics from ProcessWire's index.php.
 *
 * Kept outside the ProcessWire namespace so it can stay a small service class
 * with the installed Cockpit module injected as its only dependency.
 */
class CockpitCli {

	/** @var object */
	protected $module;

	public function __construct(object $module) {
		$this->module = $module;
	}

	public function dispatch(): void {
		if (PHP_SAPI !== 'cli' || empty($_SERVER['argv'])) return;
		$argv = array_map('strval', $_SERVER['argv']);
		if (!$this->hasCockpitArgument($argv)) return;

		$options = getopt('', [
			'cockpit-help::',
			'cockpit-format:',
			'cockpit-list',
			'cockpit-create',
			'cockpit-update',
			'cockpit-delete',
			'cockpit-enable',
			'cockpit-disable',
			'cockpit-stats',
			'cockpit-resolve',
			'cockpit-prune',
			'cockpit-diagnose',
			'cockpit-import-legacy',
			'cockpit-export-links',
			'cockpit-import-links:',
			'cockpit-id:',
			'cockpit-path:',
			'cockpit-target:',
			'cockpit-status:',
			'cockpit-group:',
			'cockpit-days:',
			'cockpit-row-limit:',
			'cockpit-error-limit:',
			'cockpit-transfer-format:',
			'cockpit-output:',
			'cockpit-replace',
			'cockpit-force',
		]);
		$options = is_array($options) ? $options : [];
		$format = 'text';

		try {
			$format = $this->format($options);
			$result = $this->run($options);
			$this->emit($result, $format);
			exit(0);
		} catch (\Throwable $exception) {
			$this->emit(['ok' => false, 'error' => $exception->getMessage()], $format, true);
			exit(1);
		}
	}

	public function run(array $options): array {
		if (array_key_exists('cockpit-help', $options)) {
			$value = $options['cockpit-help'];
			return $this->help(is_string($value) ? $value : null);
		}

		$commands = [
			'list', 'create', 'update', 'delete', 'enable', 'disable', 'stats', 'resolve', 'prune', 'diagnose', 'import-legacy', 'export-links', 'import-links',
		];
		$selected = [];
		foreach ($commands as $command) {
			if (array_key_exists('cockpit-' . $command, $options)) $selected[] = $command;
		}
		if (!$selected) return $this->help();
		if (count($selected) > 1) {
			throw new \InvalidArgumentException('Pass only one Cockpit command at a time.');
		}

		switch ($selected[0]) {
			case 'list': return $this->listLinks();
			case 'create': return $this->createLink($options);
			case 'update': return $this->updateLink($options);
			case 'delete': return $this->deleteLink($options);
			case 'enable': return $this->setEnabled($options, true);
			case 'disable': return $this->setEnabled($options, false);
			case 'stats': return $this->statistics($options);
			case 'resolve': return $this->resolvePath($options);
			case 'prune': return $this->pruneStatistics($options);
			case 'diagnose': return $this->diagnostics();
			case 'import-legacy': return $this->importLegacy($options);
			case 'export-links': return $this->exportLinks($options);
			case 'import-links': return $this->importLinks($options);
		}

		return $this->help();
	}

	public function commands(): array {
		$root = 'php index.php';
		return [
			'help' => [
				'title' => 'Show CLI help',
				'usage' => $root . ' --cockpit-help[=command]',
				'description' => 'Print all commands or detailed help for one command.',
				'examples' => [$root . ' --cockpit-help', $root . ' --cockpit-help=create'],
			],
			'list' => [
				'title' => 'List short links',
				'usage' => $root . ' --cockpit-list [--cockpit-format=json]',
				'description' => 'List IDs, paths, targets, status, enabled state, and click totals.',
				'examples' => [$root . ' --cockpit-list', $root . ' --cockpit-list --cockpit-format=json'],
			],
			'create' => [
				'title' => 'Create a short link',
				'usage' => $root . ' --cockpit-create --cockpit-target=URL [--cockpit-path=PATH] [--cockpit-status=302]',
				'description' => 'Create an active link. Omit the path to generate one under the default prefix.',
				'examples' => [$root . " --cockpit-create --cockpit-path=instagram --cockpit-target='https://instagram.com/modenza_cabinets'"],
			],
			'update' => [
				'title' => 'Update a short link',
				'usage' => $root . ' --cockpit-update --cockpit-id=ID [--cockpit-path=PATH] [--cockpit-target=URL] [--cockpit-status=302]',
				'description' => 'Update the provided values and preserve all omitted values.',
				'examples' => [$root . " --cockpit-update --cockpit-id=3 --cockpit-path=social/modenza --cockpit-status=301"],
			],
			'delete' => [
				'title' => 'Delete a short link',
				'usage' => $root . ' --cockpit-delete --cockpit-id=ID --cockpit-force',
				'description' => 'Permanently delete a link and its statistics. Requires the force flag.',
				'examples' => [$root . ' --cockpit-delete --cockpit-id=3 --cockpit-force'],
			],
			'enable' => [
				'title' => 'Enable a short link',
				'usage' => $root . ' --cockpit-enable --cockpit-id=ID',
				'description' => 'Enable a previously disabled link.',
				'examples' => [$root . ' --cockpit-enable --cockpit-id=3'],
			],
			'disable' => [
				'title' => 'Disable a short link',
				'usage' => $root . ' --cockpit-disable --cockpit-id=ID',
				'description' => 'Disable a link without deleting its settings or statistics.',
				'examples' => [$root . ' --cockpit-disable --cockpit-id=3'],
			],
			'stats' => [
				'title' => 'Show click statistics',
				'usage' => $root . ' --cockpit-stats [--cockpit-id=ID] [--cockpit-group=day|week|month]',
				'description' => 'Show daily, weekly, or monthly click buckets for all links or one link.',
				'examples' => [$root . ' --cockpit-stats --cockpit-group=month', $root . ' --cockpit-stats --cockpit-id=3 --cockpit-group=day'],
			],
			'resolve' => [
				'title' => 'Resolve a configured path',
				'usage' => $root . ' --cockpit-resolve --cockpit-path=PATH',
				'description' => 'Show the configured destination without recording a click or performing a redirect.',
				'examples' => [$root . ' --cockpit-resolve --cockpit-path=go/124'],
			],
			'prune' => [
				'title' => 'Prune old daily statistics',
				'usage' => $root . ' --cockpit-prune [--cockpit-days=N]',
				'description' => 'Delete old daily buckets using the configured retention or an explicit number of days. Lifetime link totals are preserved.',
				'examples' => [$root . ' --cockpit-prune', $root . ' --cockpit-prune --cockpit-days=365'],
			],
			'diagnose' => [
				'title' => 'Inspect schema and routing state',
				'usage' => $root . ' --cockpit-diagnose [--cockpit-format=json]',
				'description' => 'Read schema integrity and module settings without changing data.',
				'examples' => [$root . ' --cockpit-diagnose --cockpit-format=json'],
			],
			'import-legacy' => [
				'title' => 'Validate or import ShortLinks tables',
				'usage' => $root . ' --cockpit-import-legacy [--cockpit-row-limit=N] [--cockpit-error-limit=N] [--cockpit-force]',
				'description' => 'Dry-run by default. Apply all valid legacy rows transactionally only with --cockpit-force after a backup.',
				'examples' => [$root . ' --cockpit-import-legacy --cockpit-format=json', $root . ' --cockpit-import-legacy --cockpit-force --cockpit-format=json'],
			],
			'export-links' => [
				'title' => 'Export link configuration',
				'usage' => $root . ' --cockpit-export-links [--cockpit-transfer-format=json|csv] [--cockpit-output=FILE]',
				'description' => 'Create a bounded versioned JSON or formula-safe CSV backup without statistics.',
				'examples' => [$root . ' --cockpit-export-links --cockpit-output=cockpit-links.json'],
			],
			'import-links' => [
				'title' => 'Plan or apply a link import',
				'usage' => $root . ' --cockpit-import-links=FILE [--cockpit-transfer-format=json|csv] [--cockpit-replace] [--cockpit-force]',
				'description' => 'Dry-run by default. Replacement is separate from apply and requires --cockpit-replace; apply requires --cockpit-force.',
				'examples' => [$root . ' --cockpit-import-links=cockpit-links.json --cockpit-format=json', $root . ' --cockpit-import-links=cockpit-links.json --cockpit-replace --cockpit-force --cockpit-format=json'],
			],
		];
	}

	public function help(?string $command = null): array {
		$commands = $this->commands();
		if ($command !== null && $command !== '') {
			$key = $this->normalizeCommand($command);
			if (!isset($commands[$key])) throw new \InvalidArgumentException('Unknown Cockpit CLI command: ' . $command);
			return ['ok' => true, 'command' => $key, 'help' => $commands[$key]];
		}
		return ['ok' => true, 'title' => 'Cockpit CLI', 'commands' => $commands];
	}

	protected function listLinks(): array {
		$rows = [];
		foreach ($this->module->findLinks() as $link) $rows[] = $this->presentLink($link);
		return ['ok' => true, 'count' => count($rows), 'links' => $rows];
	}

	protected function createLink(array $options): array {
		$target = trim((string)($options['cockpit-target'] ?? ''));
		if ($target === '') throw new \InvalidArgumentException('--cockpit-target is required.');
		$id = $this->module->saveLink([
			'path' => (string)($options['cockpit-path'] ?? ''),
			'target_url' => $target,
			'redirect_status' => $this->statusOption($options, $this->module->getDefaultRedirectStatus()),
			'enabled' => $this->module->newLinksEnabledByDefault(),
		]);
		return ['ok' => true, 'message' => 'Short link created.', 'link' => $this->presentLink($this->module->findLinkById($id))];
	}

	protected function updateLink(array $options): array {
		$link = $this->requireLinkById($options);
		$data = [
			'id' => (int)$link['id'],
			'path' => array_key_exists('cockpit-path', $options) ? (string)$options['cockpit-path'] : (string)$link['path'],
			'target_url' => array_key_exists('cockpit-target', $options) ? (string)$options['cockpit-target'] : (string)$link['target_url'],
			'redirect_status' => $this->statusOption($options, (int)$link['redirect_status']),
			'enabled' => !empty($link['enabled']),
		];
		$id = $this->module->saveLink($data);
		return ['ok' => true, 'message' => 'Short link updated.', 'link' => $this->presentLink($this->module->findLinkById($id))];
	}

	protected function deleteLink(array $options): array {
		$link = $this->requireLinkById($options);
		if (!array_key_exists('cockpit-force', $options)) {
			throw new \InvalidArgumentException('Refusing to delete without --cockpit-force.');
		}
		$presented = $this->presentLink($link);
		$this->module->deleteLink((int)$link['id']);
		return ['ok' => true, 'message' => 'Short link and statistics deleted.', 'deleted' => $presented];
	}

	protected function setEnabled(array $options, bool $enabled): array {
		$link = $this->requireLinkById($options);
		$id = $this->module->saveLink([
			'id' => (int)$link['id'],
			'path' => (string)$link['path'],
			'target_url' => (string)$link['target_url'],
			'redirect_status' => (int)$link['redirect_status'],
			'enabled' => $enabled,
		]);
		return [
			'ok' => true,
			'message' => $enabled ? 'Short link enabled.' : 'Short link disabled.',
			'link' => $this->presentLink($this->module->findLinkById($id)),
		];
	}

	protected function statistics(array $options): array {
		$group = strtolower(trim((string)($options['cockpit-group'] ?? 'day')));
		if (!in_array($group, ['day', 'week', 'month'], true)) {
			throw new \InvalidArgumentException('--cockpit-group must be day, week, or month.');
		}
		$id = $this->idOption($options, false);
		$link = $id > 0 ? $this->module->findLinkById($id) : null;
		if ($id > 0 && !$link) throw new \InvalidArgumentException('Short link not found: ' . $id);
		return [
			'ok' => true,
			'group' => $group,
			'link' => $link ? $this->presentLink($link) : null,
			'buckets' => $this->module->getStatistics($group, $id),
			'totals' => $this->module->getDashboardTotals(),
		];
	}

	protected function resolvePath(array $options): array {
		$path = $this->module->normalizePath((string)($options['cockpit-path'] ?? ''));
		if ($path === '') throw new \InvalidArgumentException('--cockpit-path is required.');
		$link = $this->module->findLinkByPath($path);
		if (!$link) throw new \InvalidArgumentException('Short path not found: /' . $path);
		$conflict = $this->module->getPageConflict($path);
		$inspection = $this->module->inspectRoute($path);
		return [
			'ok' => true,
			'link' => $this->presentLink($link),
			'effective' => !empty($link['enabled']) && $inspection->isConclusive() && !$inspection->hasClaims(),
			'page_conflict' => $conflict,
			'route_inspection' => $inspection->jsonSerialize(),
		];
	}

	protected function pruneStatistics(array $options): array {
		$days = (int)($options['cockpit-days'] ?? 0);
		if ($days < 0 || $days > 3650) {
			throw new \InvalidArgumentException('--cockpit-days must be between 1 and 3650, or omitted.');
		}
		$deleted = $this->module->pruneOldStatistics($days);
		return ['ok' => true, 'message' => 'Old daily statistics pruned.', 'deleted_buckets' => $deleted, 'days' => $days ?: null];
	}

	protected function diagnostics(): array {
		return [
			'ok' => true,
			'runtime' => [
				'cockpit' => (string)($this->module->wire('modules')->getModuleInfo('Cockpit')['version'] ?? ''),
				'process_cockpit' => (string)($this->module->wire('modules')->getModuleInfo('ProcessCockpit')['version'] ?? ''),
				'processwire' => (string)$this->module->wire('config')->version,
				'php' => PHP_VERSION,
			],
			'schema' => $this->module->diagnoseSchema(),
			'code_providers' => $this->module->getCodeProviderStatus(),
			'links' => $this->module->getDashboardTotals(),
			'settings' => [
				'base_path' => $this->module->getBasePath(),
				'public_base_url' => (string)$this->module->get('public_base_url'),
			],
		];
	}

	protected function importLegacy(array $options): array {
		$rowLimit = (int)($options['cockpit-row-limit'] ?? 10000);
		$errorLimit = (int)($options['cockpit-error-limit'] ?? 100);
		if ($rowLimit < 1 || $rowLimit > 100000) throw new \InvalidArgumentException('--cockpit-row-limit must be between 1 and 100000.');
		if ($errorLimit < 1 || $errorLimit > 1000) throw new \InvalidArgumentException('--cockpit-error-limit must be between 1 and 1000.');
		$apply = array_key_exists('cockpit-force', $options);
		$report = $this->module->importLegacyShortLinks($apply, $rowLimit, $errorLimit);
		return [
			'ok' => $report['error_count'] === 0,
			'message' => $apply ? 'Legacy import apply completed.' : 'Legacy import dry run completed.',
			'import' => $report,
		];
	}

	protected function exportLinks(array $options): array {
		$format = $this->transferFormat($options, null);
		$document = $this->module->exportLinks($format);
		$output = trim((string)($options['cockpit-output'] ?? ''));
		if ($output !== '') {
			$bytes = file_put_contents($output, $document, LOCK_EX);
			if ($bytes === false || $bytes !== strlen($document)) throw new \RuntimeException('Unable to write the complete Cockpit export file.');
			return ['ok' => true, 'message' => 'Link export written.', 'format' => $format, 'file' => $output, 'bytes' => $bytes];
		}
		return ['ok' => true, 'format' => $format, 'bytes' => strlen($document), 'document' => $document];
	}

	protected function importLinks(array $options): array {
		$file = trim((string)($options['cockpit-import-links'] ?? ''));
		if ($file === '' || !is_file($file) || !is_readable($file)) throw new \InvalidArgumentException('--cockpit-import-links must name a readable file.');
		$format = $this->transferFormat($options, $file);
		$maxBytes = $this->module->getLinkTransferService()->limits()->maxBytes();
		$handle = fopen($file, 'rb');
		if (!$handle) throw new \RuntimeException('Unable to open the Cockpit import file.');
		try {
			$payload = stream_get_contents($handle, $maxBytes + 1);
		} finally {
			fclose($handle);
		}
		if (!is_string($payload) || strlen($payload) > $maxBytes) throw new \RuntimeException('Cockpit import file exceeds the configured byte limit.');
		$report = $this->module->importLinks(
			$payload,
			$format,
			array_key_exists('cockpit-force', $options),
			array_key_exists('cockpit-replace', $options)
		);
		return $report->jsonSerialize();
	}

	protected function transferFormat(array $options, ?string $file): string {
		$format = strtolower(trim((string)($options['cockpit-transfer-format'] ?? '')));
		if ($format === '' && $file !== null) $format = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
		if ($format === '') $format = 'json';
		if (!in_array($format, ['json', 'csv'], true)) throw new \InvalidArgumentException('--cockpit-transfer-format must be json or csv.');
		return $format;
	}

	protected function requireLinkById(array $options): array {
		$id = $this->idOption($options, true);
		$link = $this->module->findLinkById($id);
		if (!$link) throw new \InvalidArgumentException('Short link not found: ' . $id);
		return $link;
	}

	protected function idOption(array $options, bool $required): int {
		$id = (int)($options['cockpit-id'] ?? 0);
		if ($required && $id < 1) throw new \InvalidArgumentException('--cockpit-id must be a positive integer.');
		if ($id < 0) throw new \InvalidArgumentException('--cockpit-id cannot be negative.');
		return $id;
	}

	protected function statusOption(array $options, int $default): int {
		if (!array_key_exists('cockpit-status', $options)) return $default;
		$status = (int)$options['cockpit-status'];
		if (!in_array($status, [301, 302, 307, 308], true)) {
			throw new \InvalidArgumentException('--cockpit-status must be 301, 302, 307, or 308.');
		}
		return $status;
	}

	protected function presentLink(array $link): array {
		return [
			'id' => (int)$link['id'],
			'path' => '/' . (string)$link['path'],
			'short_url' => $this->module->shortUrl((string)$link['path']),
			'target_url' => (string)$link['target_url'],
			'redirect_status' => (int)$link['redirect_status'],
			'enabled' => !empty($link['enabled']),
			'hits' => (int)$link['hits'],
			'last_hit_at' => $link['last_hit_at'] ?: null,
		];
	}

	protected function emit(array $result, string $format, bool $stderr = false): void {
		$output = $format === 'json' ? $this->json($result) : $this->text($result);
		if ($stderr) fwrite(STDERR, $output); else echo $output;
	}

	protected function text(array $result): string {
		if (isset($result['commands'])) return $this->textHelp($result);
		if (isset($result['help'])) return $this->textCommandHelp((string)$result['command'], $result['help']);
		if (isset($result['links']) && is_array($result['links'])) return $this->textLinks($result);
		if (isset($result['buckets']) && is_array($result['buckets'])) return $this->textStats($result);
		$lines = [];
		$this->flatten($result, $lines);
		return implode("\n", $lines) . "\n";
	}

	protected function textHelp(array $result): string {
		$lines = ['Cockpit CLI', '', 'Usage: php index.php --cockpit-help[=command]', '', 'Commands:'];
		foreach ($result['commands'] as $key => $command) {
			$lines[] = '  ' . str_pad((string)$key, 12) . (string)$command['title'];
		}
		$lines[] = '';
		$lines[] = 'Run php index.php --cockpit-help=command for details.';
		return implode("\n", $lines) . "\n";
	}

	protected function textCommandHelp(string $key, array $command): string {
		$lines = ['Cockpit CLI: ' . $key, '', (string)$command['description'], '', 'Usage:', '  ' . (string)$command['usage']];
		if (!empty($command['examples'])) {
			$lines[] = '';
			$lines[] = 'Examples:';
			foreach ($command['examples'] as $example) $lines[] = '  ' . $example;
		}
		return implode("\n", $lines) . "\n";
	}

	protected function textLinks(array $result): string {
		$lines = ['ID    STATE  HTTP  HITS       PATH -> TARGET'];
		foreach ($result['links'] as $link) {
			$lines[] = str_pad((string)$link['id'], 6)
				. str_pad($link['enabled'] ? 'on' : 'off', 7)
				. str_pad((string)$link['redirect_status'], 6)
				. str_pad((string)$link['hits'], 11)
				. $link['path'] . ' -> ' . $link['target_url'];
		}
		$lines[] = '';
		$lines[] = 'Count: ' . (int)$result['count'];
		return implode("\n", $lines) . "\n";
	}

	protected function textStats(array $result): string {
		$max = 0;
		foreach ($result['buckets'] as $bucket) $max = max($max, (int)$bucket['clicks']);
		$lines = ['Cockpit statistics (' . $result['group'] . ')'];
		if (!empty($result['link']['path'])) $lines[] = 'Link: ' . $result['link']['path'];
		$lines[] = '';
		foreach ($result['buckets'] as $bucket) {
			$count = (int)$bucket['clicks'];
			$bars = $max > 0 ? (int)round(($count / $max) * 30) : 0;
			$lines[] = str_pad((string)$bucket['bucket'], 13) . str_pad((string)$count, 9) . str_repeat('#', $bars);
		}
		return implode("\n", $lines) . "\n";
	}

	protected function flatten(array $value, array &$lines, string $prefix = ''): void {
		foreach ($value as $key => $item) {
			$name = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
			if (is_array($item)) {
				$this->flatten($item, $lines, $name);
			} else {
				$lines[] = $name . ': ' . (is_bool($item) ? ($item ? 'yes' : 'no') : (string)$item);
			}
		}
	}

	protected function json(array $result): string {
		$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) throw new \RuntimeException('Unable to encode CLI response as JSON.');
		return $json . "\n";
	}

	protected function format(array $options): string {
		$format = strtolower((string)($options['cockpit-format'] ?? 'text'));
		if (!in_array($format, ['text', 'json'], true)) {
			throw new \InvalidArgumentException('--cockpit-format must be text or json.');
		}
		return $format;
	}

	protected function normalizeCommand(string $command): string {
		$command = preg_replace('/^--?cockpit-/', '', trim($command));
		return str_replace('_', '-', (string)$command);
	}

	protected function hasCockpitArgument(array $argv): bool {
		foreach ($argv as $argument) {
			if (strpos($argument, '--cockpit-') === 0) return true;
		}
		return false;
	}
}
