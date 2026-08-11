<?php

namespace Cockpit\Transfer;

final class TransferIssue implements \JsonSerializable {

	private int $row;
	private string $code;
	private string $message;
	private string $field;

	public function __construct(int $row, string $code, string $message, string $field = '') {
		$this->row = max(0, $row);
		$this->code = substr(trim($code), 0, 100);
		$this->message = self::boundedUtf8(trim($message), 2000);
		$this->field = substr(trim($field), 0, 100);
	}

	public function row(): int { return $this->row; }
	public function code(): string { return $this->code; }
	public function message(): string { return $this->message; }
	public function field(): string { return $this->field; }

	public function jsonSerialize(): array {
		$result = ['row' => $this->row, 'code' => $this->code, 'message' => $this->message];
		if ($this->field !== '') $result['field'] = $this->field;
		return $result;
	}

	private static function boundedUtf8(string $value, int $bytes): string {
		if (strlen($value) <= $bytes) return $value;
		$value = substr($value, 0, $bytes);
		while ($value !== '' && preg_match('//u', $value) !== 1) $value = substr($value, 0, -1);
		return rtrim($value) . '…';
	}
}
