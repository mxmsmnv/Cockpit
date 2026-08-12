<?php

/**
 * Canonical path policy shared by saved links and public requests.
 *
 * Cockpit deliberately supports a compact ASCII route alphabet. Ambiguous URL
 * representations are rejected rather than rewritten so another router cannot
 * interpret the same request differently.
 */
final class CockpitPathPolicy {

	/**
	 * Canonicalize an admin/API path.
	 *
	 * Returns null when the path is ambiguous or outside Cockpit's alphabet.
	 */
	public static function canonicalize(string $path): ?string {
		if ($path === '' || preg_match('/[\x00-\x20\x7F]/', $path)) return null;
		if (strpos($path, '\\') !== false || strpos($path, '%') !== false) return null;

		$path = trim($path, '/');
		if ($path === '' || strpos($path, '//') !== false) return null;

		$segments = explode('/', $path);
		foreach ($segments as &$segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') return null;
			if (!preg_match('/^[A-Za-z0-9_~-](?:[A-Za-z0-9._~-]*[A-Za-z0-9_~-])?$/D', $segment)) {
				return null;
			}
			$segment = strtolower($segment);
		}
		unset($segment);

		$canonical = implode('/', $segments);
		return strlen($canonical) <= 191 ? $canonical : null;
	}

	/**
	 * Canonicalize a raw URL path from REQUEST_URI.
	 *
	 * Percent-encoded Cockpit paths are intentionally not aliases. This prevents
	 * encoded separators, dot segments, double decoding, and router disagreement.
	 */
	public static function fromRequestPath(string $path): ?string {
		if (strpos($path, '?') !== false || strpos($path, '#') !== false) return null;
		return self::canonicalize($path);
	}
}
