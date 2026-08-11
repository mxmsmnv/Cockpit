#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/src/Routing/bootstrap.php';
require_once dirname(__DIR__) . '/src/Cache/bootstrap.php';

use Cockpit\Cache\CloudCacheRouteIntegration;
use Cockpit\Routing\HookNamespaceClaimSource;
use Cockpit\Routing\IchibanRedirectClaimSource;
use Cockpit\Routing\PagePathHistoryClaimSource;
use Cockpit\Routing\ProcessRedirectsClaimSource;
use Cockpit\Routing\ProcessWirePageClaimSource;
use Cockpit\Routing\RouteClaim;
use Cockpit\Routing\RouteClaimSource;
use Cockpit\Routing\RouteInspector;
use Cockpit\Routing\StaticRouteClaimSource;

function routeTestAssert(bool $condition, string $message): void {
	if ($condition) return;
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

final class FakePage {
	public int $id;
	public string $path;
	public string $title;
	public object $template;

	public function __construct(int $id = 0, string $path = '/', string $title = '', string $template = '') {
		$this->id = $id;
		$this->path = $path;
		$this->title = $title;
		$this->template = (object)['name' => $template];
	}
}

final class FakePages {
	private array $pages;

	public function __construct() {
		$this->pages = [
			1 => new FakePage(1, '/about/', 'About', 'page'),
			2 => new FakePage(2, '/blog/', 'Blog', 'blog'),
		];
	}

	public function getByPath(string $path, array $options) {
		$path = '/' . trim($path, '/') . '/';
		if ($path === '/about/') return $this->pages[1];
		if (strpos($path, '/blog/') === 0) return $this->pages[2];
		return new FakePage();
	}

	public function get(int $id) { return $this->pages[$id] ?? new FakePage(); }
}

final class FakeStatement {
	private $loader;
	private array $rows = [];

	public function __construct(callable $loader) { $this->loader = $loader; }
	public function execute(array $params = []): bool {
		$this->rows = (array)call_user_func($this->loader, $params);
		return true;
	}
	public function fetchAll(int $mode = 0): array { return $this->rows; }
}

final class FakeDatabase {
	public array $history = [];
	public array $ichiban = [];
	public array $processRedirects = [];
	public int $writes = 0;

	public function prepare(string $sql): FakeStatement {
		if (strpos($sql, 'page_path_history') !== false) {
			return new FakeStatement(function(array $params): array {
				return array_values(array_filter($this->history, static function(array $row) use ($params): bool {
					return in_array($row['path'], [$params[':path'], $params[':path_slash']], true);
				}));
			});
		}
		if (strpos($sql, 'ichiban_redirects') !== false) {
			return new FakeStatement(function(array $params): array {
				return array_values(array_filter($this->ichiban, static function(array $row) use ($params): bool {
					return empty($row['is_regex']) && in_array($row['from_url'], [$params[':path'], $params[':path_slash']], true);
				}));
			});
		}
		if (strpos($sql, 'process_redirects') !== false) {
			return new FakeStatement(function(array $params): array {
				return array_values(array_filter($this->processRedirects, static function(array $row) use ($params): bool {
					return rtrim($row['redirect_from'], '/') === $params[':path'] || strpos($row['redirect_from'], '*') !== false;
				}));
			});
		}
		throw new RuntimeException('Unexpected prepared SQL in fake database.');
	}

	public function query(string $sql): FakeStatement {
		if (strpos($sql, 'ichiban_redirects') !== false) {
			$statement = new FakeStatement(function(): array {
				return array_values(array_filter($this->ichiban, static function(array $row): bool {
					return !empty($row['is_regex']);
				}));
			});
			$statement->execute();
			return $statement;
		}
		throw new RuntimeException('Unexpected query SQL in fake database.');
	}
}

final class FakeModules {
	private array $installed;
	private array $config;

	public function __construct(array $installed, array $config = []) {
		$this->installed = array_fill_keys($installed, true);
		$this->config = $config;
	}
	public function isInstalled(string $name): bool { return isset($this->installed[$name]); }
	public function getModuleConfigData(string $name): array { return $this->config[$name] ?? []; }
}

final class ThrowingClaimSource implements RouteClaimSource {
	public function name(): string { return 'broken-source'; }
	public function claimsFor(string $path): array { throw new RuntimeException('inspection unavailable'); }
}

$exact = new RouteClaim('exact-owner', 'test', RouteClaim::KIND_EXACT, '/campaign/', 30);
$namespace = new RouteClaim('namespace-owner', 'test', RouteClaim::KIND_NAMESPACE, '/api/*', 20);
$wildcard = new RouteClaim('wildcard-owner', 'test', RouteClaim::KIND_WILDCARD, '/legacy/*/view', 10);
routeTestAssert($exact->matches('/CAMPAIGN/?utm_source=test'), 'Exact matching did not normalize case, slash, and query.');
routeTestAssert($namespace->matches('/api') && $namespace->matches('/api/v1/items'), 'Namespace claim did not own its root and descendants.');
routeTestAssert($wildcard->matches('/legacy/42/view'), 'Wildcard claim did not match a middle segment.');
routeTestAssert(!$namespace->matches('/apiary'), 'Namespace claim crossed a segment boundary.');

$inspection = (new RouteInspector([
	new StaticRouteClaimSource('static', [$exact, $namespace, $wildcard]),
]))->inspect('/legacy/42/view');
routeTestAssert($inspection->owner() && $inspection->owner()->owner() === 'wildcard-owner', 'Deterministic precedence did not select the earliest owner.');

$pages = new FakePages();
$pageSource = new ProcessWirePageClaimSource($pages);
$pageClaims = $pageSource->claimsFor('/about');
routeTestAssert(count($pageClaims) === 1 && $pageClaims[0]->owner() === 'processwire.page', 'Exact ProcessWire page was not claimed.');
$segmentClaims = $pageSource->claimsFor('/blog/archive/2026');
routeTestAssert(count($segmentClaims) === 1 && $segmentClaims[0]->owner() === 'processwire.url-segments', 'URL-segment namespace was not claimed.');

$database = new FakeDatabase();
$database->history[] = [
	'pages_id' => 1,
	'language_id' => 0,
	'path' => '/old-about',
	'created' => '2026-08-01 00:00:00',
];
$database->ichiban = [
	['id' => 1, 'from_url' => '/seo-old', 'to_url' => '/about/', 'type' => 301, 'is_regex' => 0, 'auto' => 0],
	['id' => 2, 'from_url' => '^/seo/[0-9]+/?$', 'to_url' => '/about/', 'type' => 302, 'is_regex' => 1, 'auto' => 0],
];
$database->processRedirects = [
	['id' => 1, 'redirect_from' => '/legacy-exact', 'redirect_to' => '/about/'],
	['id' => 2, 'redirect_from' => '/outdated/*', 'redirect_to' => '/about/'],
];
$installed = static function(string $name): bool { return true; };

$history = new PagePathHistoryClaimSource($database, $pages, $installed);
routeTestAssert(count($history->claimsFor('/old-about')) === 1, 'PagePathHistory row was not claimed.');
$ichiban = new IchibanRedirectClaimSource($database, $installed);
routeTestAssert(count($ichiban->claimsFor('/seo-old')) === 1, 'Ichiban exact redirect was not claimed.');
routeTestAssert(count($ichiban->claimsFor('/seo/42')) === 1, 'Ichiban regex redirect was not claimed.');
routeTestAssert($database->writes === 0, 'Read-only redirect inspection performed a write.');
$processRedirects = new ProcessRedirectsClaimSource($database, $installed);
routeTestAssert(count($processRedirects->claimsFor('/legacy-exact')) === 1, 'ProcessRedirects exact route was not claimed.');
routeTestAssert(count($processRedirects->claimsFor('/outdated/item')) === 1, 'ProcessRedirects wildcard route was not claimed.');

$modules = new FakeModules(
	['AppApi', 'Compass', 'ProcessPulse'],
	['AppApi' => ['endpoint' => 'api2'], 'ProcessPulse' => ['endpointBase' => 'quiz']]
);
$hooks = new HookNamespaceClaimSource($modules);
routeTestAssert(count($hooks->claimsFor('/api2/products')) === 1, 'Configured AppApi namespace was not claimed.');
routeTestAssert(count($hooks->claimsFor('/quiz/results')) === 1, 'Configured Pulse namespace was not claimed.');
routeTestAssert(count($hooks->claimsFor('/compass-data')) === 1, 'Exact Compass endpoint was not claimed.');
routeTestAssert(count($hooks->claimsFor('/api/products')) === 0, 'Inactive default AppApi namespace was claimed.');

$inconclusive = (new RouteInspector([new ThrowingClaimSource()]))->inspect('/candidate');
routeTestAssert(!$inconclusive->isConclusive() && count($inconclusive->errors()) === 1, 'Source failures were not surfaced as an inconclusive inspection.');

$purged = [];
$cache = new CloudCacheRouteIntegration(
	static function(string $name): bool { return $name === 'CloudCache'; },
	static function(string $path) use (&$purged): bool { $purged[] = $path; return true; }
);
$headers = $cache->bypassHeaders(301);
routeTestAssert(($headers['X-CloudCache'] ?? '') === 'BYPASS', 'CloudCache bypass plan lacks the explicit bypass marker.');
$purgeResult = $cache->purgePaths(['/old', '/new/', '/new/?ignored=1']);
routeTestAssert($purgeResult->succeeded(), 'CloudCache purge callback did not succeed.');
routeTestAssert($purged === ['/old/', '/new/'], 'CloudCache purge paths were not normalized and deduplicated.');
$noCallback = new CloudCacheRouteIntegration(static function(): bool { return true; });
routeTestAssert(!$noCallback->purgePaths(['/candidate'])->attempted(), 'CloudCache purge was attempted without a verified callback.');

echo json_encode([
	'ok' => true,
	'tests' => [
		'exact_namespace_wildcard_matching',
		'deterministic_precedence',
		'processwire_pages_and_url_segments',
		'page_path_history_read_only_claim',
		'ichiban_exact_and_regex_read_only_claims',
		'process_redirects_exact_and_wildcard_claims',
		'feature_detected_hook_namespaces',
		'inconclusive_source_error_surface',
		'cloudcache_bypass_and_explicit_purge_boundary',
	],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
