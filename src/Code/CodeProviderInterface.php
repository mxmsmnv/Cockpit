<?php

namespace Cockpit\Code;

interface CodeProviderInterface {
	public function id(): string;
	public function label(): string;
	public function isAvailable(): bool;
	public function capabilities(): array;
	public function generate(string $payload, array $options = []): CodeResult;
}
