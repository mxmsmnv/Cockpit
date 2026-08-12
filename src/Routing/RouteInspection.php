<?php

namespace Cockpit\Routing;

final class RouteInspection implements \JsonSerializable {

	private string $path;
	private array $claims;
	private array $errors;

	public function __construct(string $path, array $claims, array $errors = []) {
		$this->path = RoutePath::normalize($path);
		$this->claims = array_values($claims);
		$this->errors = array_values($errors);
	}

	public function path(): string { return $this->path; }
	public function claims(): array { return $this->claims; }
	public function errors(): array { return $this->errors; }
	public function hasClaims(): bool { return count($this->claims) > 0; }
	public function isConclusive(): bool { return count($this->errors) === 0; }
	public function owner(): ?RouteClaim { return $this->claims[0] ?? null; }

	public function jsonSerialize(): array {
		return [
			'path' => $this->path,
			'claimed' => $this->hasClaims(),
			'conclusive' => $this->isConclusive(),
			'owner' => $this->owner(),
			'claims' => $this->claims,
			'errors' => $this->errors,
		];
	}
}
