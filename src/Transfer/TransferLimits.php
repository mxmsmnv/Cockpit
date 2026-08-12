<?php

namespace Cockpit\Transfer;

final class TransferLimits implements \JsonSerializable {

	private int $maxBytes;
	private int $maxRows;
	private int $maxErrors;

	public function __construct(int $maxBytes = 5242880, int $maxRows = 5000, int $maxErrors = 100) {
		if ($maxBytes < 1024 || $maxBytes > 104857600) {
			throw new \InvalidArgumentException('Transfer maxBytes must be between 1 KiB and 100 MiB.');
		}
		if ($maxRows < 1 || $maxRows > 100000) {
			throw new \InvalidArgumentException('Transfer maxRows must be between 1 and 100000.');
		}
		if ($maxErrors < 1 || $maxErrors > 10000) {
			throw new \InvalidArgumentException('Transfer maxErrors must be between 1 and 10000.');
		}
		$this->maxBytes = $maxBytes;
		$this->maxRows = $maxRows;
		$this->maxErrors = $maxErrors;
	}

	public function maxBytes(): int { return $this->maxBytes; }
	public function maxRows(): int { return $this->maxRows; }
	public function maxErrors(): int { return $this->maxErrors; }

	public function jsonSerialize(): array {
		return [
			'max_bytes' => $this->maxBytes,
			'max_rows' => $this->maxRows,
			'max_errors' => $this->maxErrors,
		];
	}
}
