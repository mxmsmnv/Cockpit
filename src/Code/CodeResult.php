<?php

namespace Cockpit\Code;

final class CodeResult implements \JsonSerializable {
	private string $provider;
	private string $format;
	private string $mimeType;
	private string $content;
	private array $metadata;

	public function __construct(string $provider, string $format, string $mimeType, string $content, array $metadata = []) {
		$this->provider = $provider;
		$this->format = $format;
		$this->mimeType = $mimeType;
		$this->content = $content;
		$this->metadata = $metadata;
	}

	public function provider(): string { return $this->provider; }
	public function format(): string { return $this->format; }
	public function mimeType(): string { return $this->mimeType; }
	public function content(): string { return $this->content; }
	public function metadata(): array { return $this->metadata; }
	public function extension(): string { return $this->format === 'gif' ? 'gif' : 'svg'; }

	public function jsonSerialize(): array {
		return [
			'provider' => $this->provider,
			'format' => $this->format,
			'mime_type' => $this->mimeType,
			'bytes' => strlen($this->content),
			'metadata' => $this->metadata,
		];
	}
}
