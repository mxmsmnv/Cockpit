<?php

namespace Cockpit\Routing;

final class CockpitRouteInspectorFactory {

	/**
	 * Build the standard read-only ProcessWire inspector without loading optional routers.
	 */
	public static function forProcessWire(object $wire, array $projectClaims = []): RouteInspector {
		$modules = $wire->modules;
		$database = $wire->database;
		$isInstalled = static function(string $name) use ($modules): bool {
			return (bool)$modules->isInstalled($name);
		};

		$sources = [
			new StaticRouteClaimSource('processwire-core', [
				new RouteClaim('processwire.core', 'processwire-core', RouteClaim::KIND_NAMESPACE, '/wire', 1),
				new RouteClaim('processwire.site', 'processwire-core', RouteClaim::KIND_NAMESPACE, '/site', 1),
				new RouteClaim('processwire.admin', 'processwire-core', RouteClaim::KIND_NAMESPACE, (string)$wire->config->urls->admin, 1),
			]),
			new ProcessWirePageClaimSource($wire->pages),
			new HookNamespaceClaimSource($modules),
			new IchibanRedirectClaimSource($database, $isInstalled),
			new PagePathHistoryClaimSource($database, $wire->pages, $isInstalled),
			new ProcessRedirectsClaimSource($database, $isInstalled),
		];
		if ($projectClaims) $sources[] = new StaticRouteClaimSource('project-routes', $projectClaims);
		return new RouteInspector($sources);
	}
}
