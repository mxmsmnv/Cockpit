<?php

namespace Cockpit\Routing;

/**
 * Side-effect-free inventory for routes registered through hooks rather than pages.
 * It reads module installation/configuration only and never instantiates a router.
 */
final class HookNamespaceClaimSource implements RouteClaimSource {

	private object $modules;
	private array $definitions;

	public function __construct(object $modules, array $definitions = []) {
		$this->modules = $modules;
		$this->definitions = $definitions ?: self::commonDefinitions();
	}

	public function name(): string { return 'hook-namespaces'; }

	public function claimsFor(string $path): array {
		$claims = [];
		foreach ($this->definitions as $definition) {
			$module = (string)($definition['module'] ?? '');
			if ($module === '' || !$this->modules->isInstalled($module)) continue;
			$config = (array)$this->modules->getModuleConfigData($module);
			if (isset($definition['enabled']) && is_callable($definition['enabled'])) {
				if (!call_user_func($definition['enabled'], $config)) continue;
			}
			$patterns = $definition['patterns'] ?? [];
			if (isset($definition['resolve']) && is_callable($definition['resolve'])) {
				$patterns = call_user_func($definition['resolve'], $config);
			}
			foreach ((array)$patterns as $pattern) {
				if (is_string($pattern)) $pattern = ['pattern' => $pattern];
				if (!is_array($pattern) || empty($pattern['pattern'])) continue;
				$claim = new RouteClaim(
					(string)($definition['owner'] ?? strtolower($module)),
					$this->name(),
					(string)($pattern['kind'] ?? $definition['kind'] ?? RouteClaim::KIND_EXACT),
					(string)$pattern['pattern'],
					(int)($definition['precedence'] ?? 20),
					[
						'module' => $module,
						'feature_detected' => true,
						'conservative' => !empty($definition['conservative']),
					] + (array)($definition['metadata'] ?? [])
				);
				if ($claim->matches($path)) $claims[] = $claim;
			}
		}
		return $claims;
	}

	public static function commonDefinitions(): array {
		return [
			[
				'module' => 'WireWall',
				'owner' => 'wirewall.security',
				'patterns' => [['pattern' => '/*', 'kind' => RouteClaim::KIND_WILDCARD]],
				'precedence' => 0,
				'enabled' => static function(array $config): bool { return !empty($config['enabled']); },
			],
			[
				'module' => 'AppApi',
				'owner' => 'app-api.endpoint',
				'kind' => RouteClaim::KIND_NAMESPACE,
				'precedence' => 20,
				'resolve' => static function(array $config): array {
					$endpoint = trim((string)($config['endpoint'] ?? 'api'), '/ ');
					return [$endpoint === '' ? '/api' : '/' . $endpoint];
				},
			],
			[
				'module' => 'ProcessPulse',
				'owner' => 'pulse.endpoint',
				'kind' => RouteClaim::KIND_NAMESPACE,
				'precedence' => 20,
				'resolve' => static function(array $config): array {
					$endpoint = trim((string)($config['endpointBase'] ?? 'pulse'), '/ ');
					return ['/' . ($endpoint === '' ? 'pulse' : $endpoint)];
				},
			],
			[
				'module' => 'VoxApi',
				'owner' => 'vox-api.endpoint',
				'patterns' => [['pattern' => '/vox-api', 'kind' => RouteClaim::KIND_NAMESPACE]],
				'precedence' => 20,
			],
			[
				'module' => 'ResendWebhooks',
				'owner' => 'resend.webhook',
				'patterns' => ['/resend-webhook'],
				'precedence' => 20,
				'conservative' => true,
				'metadata' => ['method' => 'POST'],
			],
			[
				'module' => 'Compass',
				'owner' => 'compass.endpoint',
				'patterns' => ['/compass-track', '/compass-data'],
				'precedence' => 20,
			],
			[
				'module' => 'ProcessRapidFrontend',
				'owner' => 'rapid.frontend-save',
				'patterns' => ['/rapid-save'],
				'precedence' => 20,
				'conservative' => true,
			],
			[
				'module' => 'FieldtypeBookmarks',
				'owner' => 'bookmarks.endpoint',
				'patterns' => [['pattern' => '/bookmarks', 'kind' => RouteClaim::KIND_NAMESPACE]],
				'precedence' => 20,
			],
			[
				'module' => 'Tickets',
				'owner' => 'tickets.api',
				'patterns' => [['pattern' => '/tickets-api', 'kind' => RouteClaim::KIND_NAMESPACE]],
				'precedence' => 20,
				'conservative' => true,
			],
			[
				'module' => 'Ichiban',
				'owner' => 'ichiban.utility',
				'precedence' => 20,
				'resolve' => static function(array $config): array {
					$patterns = [];
					if (!empty($config['robots_enabled'])) $patterns[] = '/robots.txt';
					if (!empty($config['llms_enabled'])) $patterns[] = '/llms.txt';
					if (!empty($config['sitemap_enabled'])) {
						$patterns[] = ['pattern' => '/sitemaps', 'kind' => RouteClaim::KIND_NAMESPACE];
					}
					return $patterns;
				},
			],
		];
	}
}
