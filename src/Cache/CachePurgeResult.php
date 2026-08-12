<?php

namespace Cockpit\Cache;

final class CachePurgeResult implements \JsonSerializable {

	private bool $attempted;
	private array $purgedPaths;
	private array $errors;

	public function __construct(bool $attempted, array $purgedPaths = [], array $errors = []) {
		$this->attempted = $attempted;
		$this->purgedPaths = array_values($purgedPaths);
		$this->errors = array_values($errors);
	}

	public function attempted(): bool { return $this->attempted; }
	public function purgedPaths(): array { return $this->purgedPaths; }
	public function errors(): array { return $this->errors; }
	public function succeeded(): bool { return $this->attempted && !$this->errors; }

	public function jsonSerialize(): array {
		return [
			'attempted' => $this->attempted,
			'succeeded' => $this->succeeded(),
			'purged_paths' => $this->purgedPaths,
			'errors' => $this->errors,
		];
	}
}
