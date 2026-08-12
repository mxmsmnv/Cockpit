<?php

namespace Cockpit\Cache;

interface RouteCacheIntegration {

	public function name(): string;
	public function isAvailable(): bool;

	/**
	 * Pure header plan; the caller remains responsible for emitting headers.
	 */
	public function bypassHeaders(int $redirectStatus): array;

	/**
	 * Explicit mutation boundary called only after an authorized route change.
	 */
	public function purgePaths(array $paths): CachePurgeResult;
}
