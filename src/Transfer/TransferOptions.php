<?php

namespace Cockpit\Transfer;

final class TransferOptions implements \JsonSerializable {

	private bool $dryRun;
	private bool $replaceExisting;

	public function __construct(bool $dryRun = true, bool $replaceExisting = false) {
		$this->dryRun = $dryRun;
		$this->replaceExisting = $replaceExisting;
	}

	public function dryRun(): bool { return $this->dryRun; }
	public function replaceExisting(): bool { return $this->replaceExisting; }

	public function jsonSerialize(): array {
		return [
			'dry_run' => $this->dryRun,
			'replace_existing' => $this->replaceExisting,
		];
	}
}
