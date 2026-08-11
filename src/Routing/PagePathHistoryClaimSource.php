<?php

namespace Cockpit\Routing;

final class PagePathHistoryClaimSource implements RouteClaimSource {

	private object $database;
	private ?object $pages;
	private $isInstalled;

	public function __construct(object $database, ?object $pages = null, ?callable $isInstalled = null) {
		$this->database = $database;
		$this->pages = $pages;
		$this->isInstalled = $isInstalled;
	}

	public function name(): string { return 'page-path-history'; }

	public function claimsFor(string $path): array {
		if ($this->isInstalled && !(bool)call_user_func($this->isInstalled, 'PagePathHistory')) return [];
		$path = RoutePath::normalize($path);
		$stmt = $this->database->prepare(
			'SELECT `pages_id`,`language_id`,`path`,`created` FROM `page_path_history` '
			. 'WHERE `path`=:path OR `path`=:path_slash ORDER BY `created` DESC LIMIT 2'
		);
		$stmt->execute([
			':path' => $path,
			':path_slash' => $path === '/' ? '/' : $path . '/',
		]);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		$claims = [];
		foreach ($rows as $row) {
			$metadata = [
				'page_id' => (int)$row['pages_id'],
				'language_id' => (int)$row['language_id'],
				'historical_path' => (string)$row['path'],
				'created' => (string)$row['created'],
			];
			if ($this->pages) {
				$page = $this->pages->get((int)$row['pages_id']);
				if ($page && !empty($page->id)) $metadata['current_path'] = (string)$page->path;
			}
			$claims[] = new RouteClaim(
				'processwire.page-path-history',
				$this->name(),
				RouteClaim::KIND_EXACT,
				$path,
				40,
				$metadata
			);
		}
		return $claims;
	}
}
