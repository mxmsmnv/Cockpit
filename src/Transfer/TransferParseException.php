<?php

namespace Cockpit\Transfer;

final class TransferParseException extends \RuntimeException {

	private string $issueCode;

	public function __construct(string $issueCode, string $message) {
		parent::__construct($message);
		$this->issueCode = $issueCode;
	}

	public function issueCode(): string { return $this->issueCode; }
}
