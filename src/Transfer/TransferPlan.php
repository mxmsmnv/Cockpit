<?php

namespace Cockpit\Transfer;

final class TransferPlan implements \JsonSerializable {

	private string $format;
	private TransferOptions $options;
	private array $actions;
	private array $issues;
	private int $inputRows;
	private bool $errorsTruncated;

	public function __construct(
		string $format,
		TransferOptions $options,
		array $actions,
		array $issues,
		int $inputRows,
		bool $errorsTruncated = false
	) {
		$this->format = $format;
		$this->options = $options;
		$this->actions = array_values($actions);
		$this->issues = array_values($issues);
		$this->inputRows = $inputRows;
		$this->errorsTruncated = $errorsTruncated;
	}

	public function format(): string { return $this->format; }
	public function options(): TransferOptions { return $this->options; }
	public function actions(): array { return $this->actions; }
	public function issues(): array { return $this->issues; }
	public function inputRows(): int { return $this->inputRows; }
	public function errorsTruncated(): bool { return $this->errorsTruncated; }
	public function isExecutable(): bool { return !$this->issues && !$this->errorsTruncated; }

	public function counts(): array {
		$counts = ['input' => $this->inputRows, 'create' => 0, 'update' => 0, 'errors' => count($this->issues)];
		foreach ($this->actions as $action) {
			if ($action instanceof TransferAction) $counts[$action->type()]++;
		}
		return $counts;
	}

	public function jsonSerialize(): array {
		return [
			'schema' => LinkTransferService::SCHEMA,
			'version' => LinkTransferService::VERSION,
			'format' => $this->format,
			'options' => $this->options,
			'executable' => $this->isExecutable(),
			'counts' => $this->counts(),
			'actions' => $this->actions,
			'issues' => $this->issues,
			'errors_truncated' => $this->errorsTruncated,
		];
	}
}
