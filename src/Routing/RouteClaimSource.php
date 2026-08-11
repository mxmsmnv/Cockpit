<?php

namespace Cockpit\Routing;

interface RouteClaimSource {

	public function name(): string;

	/**
	 * Return only claims that match the supplied path.
	 * Implementations must not increment counters or mutate router state.
	 *
	 * @return RouteClaim[]
	 */
	public function claimsFor(string $path): array;
}
