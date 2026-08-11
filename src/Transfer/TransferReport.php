<?php

namespace Cockpit\Transfer;

final class TransferReport implements \JsonSerializable {

	private TransferPlan $plan;
	private bool $attempted;
	private bool $committed;
	private array $written;
	private array $executionErrors;

	public function __construct(
		TransferPlan $plan,
		bool $attempted = false,
		bool $committed = false,
		array $written = [],
		array $executionErrors = []
	) {
		$this->plan = $plan;
		$this->attempted = $attempted;
		$this->committed = $committed;
		$this->written = array_values($written);
		$this->executionErrors = array_values($executionErrors);
	}

	public function plan(): TransferPlan { return $this->plan; }
	public function attempted(): bool { return $this->attempted; }
	public function committed(): bool { return $this->committed; }
	public function written(): array { return $this->written; }
	public function executionErrors(): array { return $this->executionErrors; }
	public function succeeded(): bool {
		return $this->plan->options()->dryRun()
			? $this->plan->isExecutable()
			: $this->committed && !$this->executionErrors;
	}

	public function jsonSerialize(): array {
		return [
			'schema' => LinkTransferService::SCHEMA,
			'version' => LinkTransferService::VERSION,
			'ok' => $this->succeeded(),
			'dry_run' => $this->plan->options()->dryRun(),
			'attempted' => $this->attempted,
			'committed' => $this->committed,
			'plan' => $this->plan,
			'written' => $this->written,
			'execution_errors' => $this->executionErrors,
		];
	}
}
