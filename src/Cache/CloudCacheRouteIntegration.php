<?php

namespace Cockpit\Cache;

/**
 * Optional CloudCache boundary. It deliberately accepts a verified purge
 * callback instead of guessing or binding to an undocumented module method.
 */
final class CloudCacheRouteIntegration implements RouteCacheIntegration {

	private $isInstalled;
	private $purgePath;

	public function __construct(callable $isInstalled, ?callable $purgePath = null) {
		$this->isInstalled = $isInstalled;
		$this->purgePath = $purgePath;
	}

	public function name(): string { return 'cloudcache'; }

	public function isAvailable(): bool {
		return (bool)call_user_func($this->isInstalled, 'CloudCache');
	}

	public function bypassHeaders(int $redirectStatus): array {
		return [
			'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
			'Pragma' => 'no-cache',
			'Expires' => '0',
			'X-CloudCache' => 'BYPASS',
			'X-Cockpit-Route' => 'redirect',
		];
	}

	public function purgePaths(array $paths): CachePurgeResult {
		$paths = $this->normalizePaths($paths);
		if (!$this->isAvailable() || !$this->purgePath) return new CachePurgeResult(false);

		$purged = [];
		$errors = [];
		foreach ($paths as $path) {
			try {
				$result = call_user_func($this->purgePath, $path);
				if ($result === false) {
					$errors[] = ['path' => $path, 'error' => 'CloudCache purge callback returned false.'];
					continue;
				}
				$purged[] = $path;
			} catch (\Throwable $exception) {
				$errors[] = ['path' => $path, 'error' => $exception->getMessage()];
			}
		}
		return new CachePurgeResult(true, $purged, $errors);
	}

	private function normalizePaths(array $paths): array {
		$result = [];
		foreach ($paths as $path) {
			$path = parse_url((string)$path, PHP_URL_PATH);
			if (!is_string($path)) continue;
			$path = '/' . trim($path, '/');
			if ($path !== '/') $path .= '/';
			$result[$path] = $path;
		}
		return array_values($result);
	}
}
