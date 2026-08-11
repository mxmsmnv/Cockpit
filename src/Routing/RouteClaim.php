<?php

namespace Cockpit\Routing;

final class RouteClaim implements \JsonSerializable {

	public const KIND_EXACT = 'exact';
	public const KIND_NAMESPACE = 'namespace';
	public const KIND_WILDCARD = 'wildcard';
	public const KIND_REGEX = 'regex';

	private string $owner;
	private string $source;
	private string $kind;
	private string $pattern;
	private int $precedence;
	private array $metadata;

	public function __construct(
		string $owner,
		string $source,
		string $kind,
		string $pattern,
		int $precedence = 100,
		array $metadata = []
	) {
		if (!in_array($kind, self::kinds(), true)) {
			throw new \InvalidArgumentException('Unsupported route-claim kind: ' . $kind);
		}
		$owner = trim($owner);
		$source = trim($source);
		if ($owner === '' || $source === '') {
			throw new \InvalidArgumentException('Route claims require non-empty owner and source names.');
		}
		$pattern = RoutePath::normalizePattern($pattern, $kind);
		if ($pattern === '') throw new \InvalidArgumentException('Route claims require a non-empty pattern.');

		$this->owner = $owner;
		$this->source = $source;
		$this->kind = $kind;
		$this->pattern = $pattern;
		$this->precedence = $precedence;
		$this->metadata = $metadata;
	}

	public static function kinds(): array {
		return [self::KIND_EXACT, self::KIND_NAMESPACE, self::KIND_WILDCARD, self::KIND_REGEX];
	}

	public function matches(string $path): bool {
		$path = RoutePath::normalize($path);
		if ($this->kind === self::KIND_EXACT) return $path === $this->pattern;
		if ($this->kind === self::KIND_NAMESPACE) {
			return $path === $this->pattern || strpos($path, $this->pattern . '/') === 0;
		}
		if ($this->kind === self::KIND_WILDCARD) {
			$regex = '~^' . str_replace('\\*', '.*', preg_quote($this->pattern, '~')) . '$~';
			return (bool)preg_match($regex, $path);
		}
		$pattern = '~' . str_replace('~', '\\~', $this->pattern) . '~i';
		return @preg_match($pattern, $path) === 1;
	}

	public function owner(): string { return $this->owner; }
	public function source(): string { return $this->source; }
	public function kind(): string { return $this->kind; }
	public function pattern(): string { return $this->pattern; }
	public function precedence(): int { return $this->precedence; }
	public function metadata(): array { return $this->metadata; }

	public function identity(): string {
		return implode('|', [$this->owner, $this->source, $this->kind, $this->pattern]);
	}

	public function jsonSerialize(): array {
		return [
			'owner' => $this->owner,
			'source' => $this->source,
			'kind' => $this->kind,
			'pattern' => $this->pattern,
			'precedence' => $this->precedence,
			'metadata' => $this->metadata,
		];
	}
}
