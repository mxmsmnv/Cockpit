<?php

namespace Cockpit\Routing;

final class StaticRouteClaimSource implements RouteClaimSource {

	private string $name;
	private array $claims;

	public function __construct(string $name, array $claims) {
		$this->name = $name;
		$this->claims = $claims;
	}

	public function name(): string { return $this->name; }

	public function claimsFor(string $path): array {
		return array_values(array_filter($this->claims, static function($claim) use ($path): bool {
			return $claim instanceof RouteClaim && $claim->matches($path);
		}));
	}
}
