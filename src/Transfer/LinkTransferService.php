<?php

namespace Cockpit\Transfer;

final class LinkTransferService {

	public const SCHEMA = 'cockpit.links.transfer';
	public const VERSION = 1;
	public const CSV_COLUMNS = ['path', 'target_url', 'redirect_status', 'enabled'];

	private TransferLimits $limits;

	public function __construct(?TransferLimits $limits = null) {
		$this->limits = $limits ?: new TransferLimits();
	}

	public function limits(): TransferLimits { return $this->limits; }

	/**
	 * Build a side-effect-free import plan using current module validation and lookup callbacks.
	 *
	 * Validator signature: function(array $candidate, array $context): array
	 * Lookup signature: function(string $normalizedPath): ?array
	 */
	public function planImport(
		string $payload,
		string $format,
		TransferOptions $options,
		callable $validateCurrent,
		callable $findExisting
	): TransferPlan {
		$format = strtolower(trim($format));
		try {
			$this->assertPayload($payload);
			$rows = $format === 'json' ? $this->parseJson($payload) : ($format === 'csv' ? $this->parseCsv($payload) : null);
			if ($rows === null) throw new TransferParseException('unsupported_format', 'Transfer format must be csv or json.');
		} catch (TransferParseException $exception) {
			return new TransferPlan($format, $options, [], [
				new TransferIssue(0, $exception->issueCode(), $exception->getMessage()),
			], 0);
		}

		$actions = [];
		$issues = [];
		$seenPaths = [];
		$errorsTruncated = false;
		foreach ($rows as $index => $row) {
			$rowNumber = $index + 1;
			if (count($issues) >= $this->limits->maxErrors()) {
				$errorsTruncated = true;
				break;
			}
			try {
				$candidate = $this->canonicalCandidate($row, $format);
				$validated = call_user_func($validateCurrent, $candidate, [
					'phase' => 'plan',
					'row' => $rowNumber,
					'format' => $format,
					'replace_existing' => $options->replaceExisting(),
				]);
				if (!is_array($validated)) throw new \RuntimeException('Candidate validator must return an array.');
				$candidate = $this->canonicalCandidate($validated, 'validated');
				$path = $candidate['path'];
				if (isset($seenPaths[$path])) {
					throw new TransferParseException('duplicate_import_path', 'The import contains the same normalized path more than once.');
				}
				$seenPaths[$path] = true;
				$existing = call_user_func($findExisting, $path);
				if ($existing !== null && !is_array($existing)) {
					throw new \RuntimeException('Existing-link lookup must return an array or null.');
				}
				if ($existing && !$options->replaceExisting()) {
					throw new TransferParseException('existing_path', 'The path already exists; replacement was not explicitly enabled.');
				}
				$actions[] = new TransferAction(
					$rowNumber,
					$existing ? TransferAction::UPDATE : TransferAction::CREATE,
					$candidate,
					$existing ?: null
				);
			} catch (TransferParseException $exception) {
				$issues[] = new TransferIssue($rowNumber, $exception->issueCode(), $exception->getMessage());
			} catch (\Throwable $exception) {
				$issues[] = new TransferIssue($rowNumber, 'validation_failed', $exception->getMessage());
			}
		}

		return new TransferPlan($format, $options, $actions, $issues, count($rows), $errorsTruncated);
	}

	/**
	 * Execute an already reviewable plan inside the caller's transaction boundary.
	 *
	 * Writer signature: function(array $candidate, TransferAction $action, ?array $currentExisting): int
	 * Transaction signature: function(callable $operation): mixed; it MUST rollback when operation throws.
	 */
	public function executePlan(
		TransferPlan $plan,
		callable $validateCurrent,
		callable $findExisting,
		callable $write,
		callable $transaction
	): TransferReport {
		if ($plan->options()->dryRun() || !$plan->isExecutable()) return new TransferReport($plan);

		$written = [];
		try {
			$result = call_user_func($transaction, function() use ($plan, $validateCurrent, $findExisting, $write, &$written) {
				foreach ($plan->actions() as $action) {
					if (!$action instanceof TransferAction) throw new \RuntimeException('Transfer plan contains an invalid action.');
					$candidate = $action->candidate();
					$current = call_user_func($validateCurrent, $candidate, [
						'phase' => 'execute',
						'row' => $action->row(),
						'format' => $plan->format(),
						'replace_existing' => $plan->options()->replaceExisting(),
					]);
					if (!is_array($current)) throw new \RuntimeException('Candidate validator must return an array.');
					$current = $this->canonicalCandidate($current, 'validated');
					if ($current !== $candidate) {
						throw new \RuntimeException('Candidate validation changed after planning; rebuild the import plan.');
					}

					$currentExisting = call_user_func($findExisting, $candidate['path']);
					$this->assertPlanStillCurrent($action, $currentExisting);
					$id = call_user_func($write, $candidate, $action, $currentExisting);
					if (!is_int($id) || $id < 1) throw new \RuntimeException('Transfer writer must return a positive integer link ID.');
					$written[] = ['row' => $action->row(), 'action' => $action->type(), 'id' => $id, 'path' => $candidate['path']];
				}
				return true;
			});
			if ($result === false) throw new \RuntimeException('Transaction callback reported failure.');
			return new TransferReport($plan, true, true, $written);
		} catch (\Throwable $exception) {
			return new TransferReport($plan, true, false, [], [[
				'code' => 'transaction_failed',
				'message' => $this->boundedMessage($exception->getMessage()),
			]]);
		}
	}

	public function import(
		string $payload,
		string $format,
		TransferOptions $options,
		callable $validateCurrent,
		callable $findExisting,
		callable $write,
		callable $transaction
	): TransferReport {
		$plan = $this->planImport($payload, $format, $options, $validateCurrent, $findExisting);
		return $this->executePlan($plan, $validateCurrent, $findExisting, $write, $transaction);
	}

	public function exportJson(iterable $links): string {
		$records = $this->collectExportRecords($links);
		$json = json_encode([
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'links' => $records,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json)) throw new \RuntimeException('Unable to encode the Cockpit JSON transfer document.');
		$json .= "\n";
		if (strlen($json) > $this->limits->maxBytes()) throw new \RuntimeException('Export exceeds the configured byte limit.');
		return $json;
	}

	public function exportCsv(iterable $links): string {
		$records = $this->collectExportRecords($links);
		$handle = fopen('php://temp', 'w+b');
		if (!$handle) throw new \RuntimeException('Unable to open the CSV transfer buffer.');
		try {
			fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');
			foreach ($records as $record) {
				$row = [];
				foreach (self::CSV_COLUMNS as $column) {
					$value = $column === 'enabled' ? ($record[$column] ? '1' : '0') : (string)$record[$column];
					$row[] = $this->protectCsvCell($value);
				}
				if (fputcsv($handle, $row, ',', '"', '') === false) throw new \RuntimeException('Unable to write the CSV transfer document.');
				if (ftell($handle) > $this->limits->maxBytes()) throw new \RuntimeException('Export exceeds the configured byte limit.');
			}
			rewind($handle);
			$output = stream_get_contents($handle);
			if (!is_string($output)) throw new \RuntimeException('Unable to read the CSV transfer document.');
			return $output;
		} finally {
			fclose($handle);
		}
	}

	private function parseJson(string $payload): array {
		$data = json_decode($payload, true);
		if (!is_array($data)) throw new TransferParseException('invalid_json', 'JSON transfer document is invalid.');
		if (($data['schema'] ?? null) !== self::SCHEMA || ($data['version'] ?? null) !== self::VERSION) {
			throw new TransferParseException('unsupported_schema', 'JSON transfer schema or version is not supported.');
		}
		if (!isset($data['links']) || !is_array($data['links'])) {
			throw new TransferParseException('invalid_json_links', 'JSON transfer document must contain a links array.');
		}
		if (count($data['links']) > $this->limits->maxRows()) {
			throw new TransferParseException('row_limit', 'Import exceeds the configured row limit.');
		}
		return array_values($data['links']);
	}

	private function parseCsv(string $payload): array {
		$handle = fopen('php://temp', 'w+b');
		if (!$handle) throw new TransferParseException('csv_buffer', 'Unable to open the CSV transfer buffer.');
		try {
			fwrite($handle, $payload);
			rewind($handle);
			$header = fgetcsv($handle, 0, ',', '"', '');
			if (!is_array($header)) throw new TransferParseException('csv_header', 'CSV transfer document is missing its header.');
			if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
			$header = array_map('strval', $header);
			if (count($header) !== count(array_unique($header)) || array_diff(self::CSV_COLUMNS, $header) || array_diff($header, self::CSV_COLUMNS)) {
				throw new TransferParseException('csv_header', 'CSV header must contain exactly: ' . implode(', ', self::CSV_COLUMNS) . '.');
			}
			$rows = [];
			while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
				if ($values === [null] || $values === []) continue;
				if (count($values) !== count($header)) {
					throw new TransferParseException('csv_columns', 'A CSV row has a different number of columns than the header.');
				}
				$row = array_combine($header, $values);
				if (!is_array($row)) throw new TransferParseException('csv_columns', 'Unable to map a CSV row to the header.');
				foreach ($row as $key => $value) $row[$key] = $this->unprotectCsvCell((string)$value);
				$rows[] = $row;
				if (count($rows) > $this->limits->maxRows()) {
					throw new TransferParseException('row_limit', 'Import exceeds the configured row limit.');
				}
			}
			return $rows;
		} finally {
			fclose($handle);
		}
	}

	private function canonicalCandidate(array $row, string $format): array {
		foreach (self::CSV_COLUMNS as $field) {
			if (!array_key_exists($field, $row)) throw new TransferParseException('missing_field', 'Required field is missing: ' . $field . '.');
		}
		if (!is_string($row['path']) || !is_string($row['target_url'])) {
			throw new TransferParseException('invalid_field_type', 'Path and target_url must be strings.');
		}
		$path = trim($row['path'], '/ ');
		$target = trim($row['target_url']);
		$this->assertUtf8($path, 'path');
		$this->assertUtf8($target, 'target_url');
		if ($path === '' || $target === '') throw new TransferParseException('empty_field', 'Path and target_url cannot be empty.');

		$status = filter_var($row['redirect_status'], FILTER_VALIDATE_INT);
		if ($status === false || !in_array((int)$status, [301, 302, 307, 308], true)) {
			throw new TransferParseException('invalid_status', 'redirect_status must be 301, 302, 307, or 308.');
		}
		$enabled = $this->parseBoolean($row['enabled'], $format);
		return [
			'path' => $path,
			'target_url' => $target,
			'redirect_status' => (int)$status,
			'enabled' => $enabled,
		];
	}

	private function parseBoolean($value, string $format): bool {
		if (is_bool($value)) return $value;
		if ($format === 'json') throw new TransferParseException('invalid_enabled', 'JSON enabled values must be boolean.');
		$value = strtolower(trim((string)$value));
		if (in_array($value, ['1', 'true', 'yes', 'on'], true)) return true;
		if (in_array($value, ['0', 'false', 'no', 'off'], true)) return false;
		throw new TransferParseException('invalid_enabled', 'enabled must be a boolean or an explicit 1/0 value.');
	}

	private function assertPlanStillCurrent(TransferAction $action, $currentExisting): void {
		if ($currentExisting !== null && !is_array($currentExisting)) {
			throw new \RuntimeException('Existing-link lookup must return an array or null.');
		}
		if ($action->type() === TransferAction::CREATE && $currentExisting) {
			throw new \RuntimeException('A planned create path now exists; rebuild the import plan.');
		}
		if ($action->type() === TransferAction::UPDATE) {
			if (!$currentExisting) throw new \RuntimeException('A planned replacement no longer exists; rebuild the import plan.');
			$planned = $action->existing();
			$plannedId = isset($planned['id']) ? (int)$planned['id'] : 0;
			$currentId = isset($currentExisting['id']) ? (int)$currentExisting['id'] : 0;
			if ($plannedId > 0 && $currentId !== $plannedId) {
				throw new \RuntimeException('The replacement path now belongs to another link; rebuild the import plan.');
			}
		}
	}

	private function collectExportRecords(iterable $links): array {
		$records = [];
		$estimatedBytes = 128;
		foreach ($links as $link) {
			if (!is_array($link)) throw new \InvalidArgumentException('Export links must be arrays.');
			$record = $this->canonicalCandidate($link, 'export');
			$estimatedBytes += strlen($record['path']) + strlen($record['target_url']) + 96;
			if ($estimatedBytes > $this->limits->maxBytes()) throw new \RuntimeException('Export exceeds the configured byte limit.');
			$records[] = $record;
			if (count($records) > $this->limits->maxRows()) throw new \RuntimeException('Export exceeds the configured row limit.');
		}
		return $records;
	}

	private function protectCsvCell(string $value): string {
		if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) return "'" . $value;
		return $value;
	}

	private function unprotectCsvCell(string $value): string {
		if (strlen($value) > 1 && $value[0] === "'" && preg_match('/^[=+\-@\t\r]/', substr($value, 1))) {
			return substr($value, 1);
		}
		return $value;
	}

	private function assertPayload(string $payload): void {
		if (strlen($payload) > $this->limits->maxBytes()) {
			throw new TransferParseException('byte_limit', 'Import exceeds the configured byte limit.');
		}
		$this->assertUtf8($payload, 'document');
	}

	private function assertUtf8(string $value, string $field): void {
		if (preg_match('//u', $value) !== 1) {
			throw new TransferParseException('invalid_utf8', 'Invalid UTF-8 in ' . $field . '.');
		}
	}

	private function boundedMessage(string $message): string {
		if (strlen($message) <= 2000) return $message;
		$message = substr($message, 0, 2000);
		while ($message !== '' && preg_match('//u', $message) !== 1) $message = substr($message, 0, -1);
		return rtrim($message) . '…';
	}
}
