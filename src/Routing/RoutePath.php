<?php

namespace Cockpit\Routing;

final class RoutePath {

	public static function normalize(string $path): string {
		$parsed = parse_url($path, PHP_URL_PATH);
		if (is_string($parsed)) $path = $parsed;
		$path = str_replace('\\', '/', rawurldecode($path));
		$path = preg_replace('~/+~', '/', $path);
		$path = strtolower('/' . ltrim((string)$path, '/'));
		if ($path !== '/') $path = rtrim($path, '/');
		return $path;
	}

	public static function normalizePattern(string $pattern, string $kind): string {
		if ($kind === RouteClaim::KIND_REGEX) return trim($pattern);
		$pattern = str_replace('\\', '/', trim($pattern));
		$pattern = preg_replace('~/+~', '/', $pattern);
		$pattern = strtolower('/' . ltrim((string)$pattern, '/'));
		if ($kind === RouteClaim::KIND_NAMESPACE) {
			$pattern = preg_replace('~/\*$~', '', $pattern);
		}
		if ($pattern !== '/') $pattern = rtrim((string)$pattern, '/');
		return $pattern;
	}
}
