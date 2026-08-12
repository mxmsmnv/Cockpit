<?php

namespace Cockpit\Transfer;

final class TransferAction implements \JsonSerializable {

	public const CREATE = 'create';
	public const UPDATE = 'update';

	private int $row;
	private string $type;
	private array $candidate;
	private ?array $existing;

	public function __construct(int $row, string $type, array $candidate, ?array $existing = null) {
		if (!in_array($type, [self::CREATE, self::UPDATE], true)) {
			throw new \InvalidArgumentException('Unsupported transfer action: ' . $type);
		}
		if ($type === self::UPDATE && !$existing) {
			throw new \InvalidArgumentException('Update transfer actions require the existing record.');
		}
		$this->row = $row;
		$this->type = $type;
		$this->candidate = $candidate;
		$this->existing = $existing;
	}

	public function row(): int { return $this->row; }
	public function type(): string { return $this->type; }
	public function candidate(): array { return $this->candidate; }
	public function existing(): ?array { return $this->existing; }

	public function jsonSerialize(): array {
		$result = [
			'row' => $this->row,
			'action' => $this->type,
			'candidate' => $this->candidate,
		];
		if ($this->existing) {
			$result['replacement'] = [
				'id' => isset($this->existing['id']) ? (int)$this->existing['id'] : null,
				'path' => (string)($this->existing['path'] ?? ''),
				'target_url' => (string)($this->existing['target_url'] ?? ''),
				'redirect_status' => (int)($this->existing['redirect_status'] ?? 0),
				'enabled' => !empty($this->existing['enabled']),
			];
		}
		return $result;
	}
}
