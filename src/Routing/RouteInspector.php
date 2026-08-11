<?php

namespace Cockpit\Routing;

final class RouteInspector {

	/** @var RouteClaimSource[] */
	private array $sources = [];

	public function __construct(array $sources = []) {
		foreach ($sources as $source) $this->addSource($source);
	}

	public function addSource(RouteClaimSource $source): self {
		$this->sources[] = $source;
		return $this;
	}

	public function inspect(string $path): RouteInspection {
		$path = RoutePath::normalize($path);
		$claims = [];
		$errors = [];

		foreach ($this->sources as $source) {
			try {
				foreach ($source->claimsFor($path) as $claim) {
					if (!$claim instanceof RouteClaim || !$claim->matches($path)) continue;
					$claims[$claim->identity()] = $claim;
				}
			} catch (\Throwable $exception) {
				$errors[] = [
					'source' => $source->name(),
					'error' => $exception->getMessage(),
				];
			}
		}

		$claims = array_values($claims);
		usort($claims, static function(RouteClaim $a, RouteClaim $b): int {
			$precedence = $a->precedence() <=> $b->precedence();
			if ($precedence !== 0) return $precedence;
			$source = strcmp($a->source(), $b->source());
			if ($source !== 0) return $source;
			$owner = strcmp($a->owner(), $b->owner());
			if ($owner !== 0) return $owner;
			return strcmp($a->pattern(), $b->pattern());
		});

		return new RouteInspection($path, $claims, $errors);
	}
}
