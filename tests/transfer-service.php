#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/src/Transfer/bootstrap.php';

use Cockpit\Transfer\LinkTransferService;
use Cockpit\Transfer\TransferAction;
use Cockpit\Transfer\TransferLimits;
use Cockpit\Transfer\TransferOptions;

function transferTestAssert(bool $condition, string $message): void {
	if ($condition) return;
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function transferJson(array $links, int $version = LinkTransferService::VERSION): string {
	return (string)json_encode([
		'schema' => LinkTransferService::SCHEMA,
		'version' => $version,
		'links' => $links,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$links = [];
$nextId = 1;
$validatorCalls = [];
$transactionCalls = 0;
$writerCalls = 0;

$validate = static function(array $candidate, array $context) use (&$validatorCalls): array {
	$validatorCalls[] = $context;
	$candidate['path'] = strtolower(trim((string)$candidate['path'], '/ '));
	if (!preg_match('#^[a-z0-9._~-]+(?:/[a-z0-9._~-]+)*$#', $candidate['path'])) {
		throw new RuntimeException('Current Cockpit path validation rejected the candidate.');
	}
	if (!filter_var($candidate['target_url'], FILTER_VALIDATE_URL)) {
		throw new RuntimeException('Current Cockpit target validation rejected the candidate.');
	}
	return $candidate;
};
$find = static function(string $path) use (&$links): ?array {
	return $links[$path] ?? null;
};
$write = static function(array $candidate, TransferAction $action, ?array $existing) use (&$links, &$nextId, &$writerCalls): int {
	$writerCalls++;
	$id = $action->type() === TransferAction::UPDATE ? (int)$existing['id'] : $nextId++;
	$links[$candidate['path']] = ['id' => $id] + $candidate;
	return $id;
};
$transaction = static function(callable $operation) use (&$links, &$nextId, &$transactionCalls) {
	$transactionCalls++;
	$snapshot = $links;
	$nextSnapshot = $nextId;
	try {
		return $operation();
	} catch (Throwable $exception) {
		$links = $snapshot;
		$nextId = $nextSnapshot;
		throw $exception;
	}
};

$service = new LinkTransferService(new TransferLimits(32768, 10, 3));
$newLink = [
	'path' => 'Campaign/One',
	'target_url' => 'https://example.com/landing',
	'redirect_status' => 302,
	'enabled' => true,
];
$payload = transferJson([$newLink]);
$dryReport = $service->import(
	$payload,
	'json',
	new TransferOptions(true, false),
	$validate,
	$find,
	$write,
	$transaction
);
transferTestAssert($dryReport->succeeded(), 'Valid dry-run did not succeed.');
transferTestAssert(!$dryReport->attempted() && !$dryReport->committed(), 'Dry-run crossed the transaction boundary.');
transferTestAssert($writerCalls === 0 && $transactionCalls === 0 && !$links, 'Dry-run performed a write.');
transferTestAssert($dryReport->plan()->actions()[0]->candidate()['path'] === 'campaign/one', 'Current validator normalization was not captured in the plan.');

$commitReport = $service->import(
	$payload,
	'json',
	new TransferOptions(false, false),
	$validate,
	$find,
	$write,
	$transaction
);
transferTestAssert($commitReport->succeeded() && $commitReport->committed(), 'Valid import did not commit.');
transferTestAssert(isset($links['campaign/one']), 'Committed import did not call the writer.');
transferTestAssert(count(array_filter($validatorCalls, static function(array $context): bool {
	return ($context['phase'] ?? '') === 'execute';
})) > 0, 'Candidate was not revalidated inside the execution boundary.');

$conflictPlan = $service->planImport($payload, 'json', new TransferOptions(true, false), $validate, $find);
transferTestAssert(!$conflictPlan->isExecutable(), 'Existing path was overwritable without explicit replacement.');
transferTestAssert($conflictPlan->issues()[0]->code() === 'existing_path', 'Existing-path conflict was not reported explicitly.');

$replacement = $newLink;
$replacement['target_url'] = 'https://example.org/replacement';
$replacement['redirect_status'] = 307;
$replacePlan = $service->planImport(
	transferJson([$replacement]),
	'json',
	new TransferOptions(false, true),
	$validate,
	$find
);
transferTestAssert($replacePlan->isExecutable(), 'Explicit replacement plan was not executable.');
transferTestAssert($replacePlan->actions()[0]->type() === TransferAction::UPDATE, 'Replacement was not represented as an update action.');
$replaceJson = json_encode($replacePlan, JSON_UNESCAPED_SLASHES);
transferTestAssert(strpos((string)$replaceJson, 'replacement') !== false, 'Replacement plan did not expose the current record.');
$replaceReport = $service->executePlan($replacePlan, $validate, $find, $write, $transaction);
transferTestAssert($replaceReport->committed(), 'Explicit replacement did not commit.');
transferTestAssert($links['campaign/one']['target_url'] === 'https://example.org/replacement', 'Replacement writer did not receive the new target.');

$exportLinks = [
	[
		'path' => '-formula-path',
		'target_url' => '=HYPERLINK("https://evil.invalid")',
		'redirect_status' => 302,
		'enabled' => 1,
	],
];
$csv = $service->exportCsv($exportLinks);
transferTestAssert(strpos($csv, "'-formula-path") !== false, 'CSV path formula was not neutralized.');
transferTestAssert(strpos($csv, "'=HYPERLINK") !== false, 'CSV target formula was not neutralized.');

$validFormulaPathCsv = $service->exportCsv([[
	'path' => '-valid-path',
	'target_url' => 'https://example.com/',
	'redirect_status' => 301,
	'enabled' => false,
]]);
$formulaPlan = $service->planImport($validFormulaPathCsv, 'csv', new TransferOptions(true, false), $validate, $find);
transferTestAssert($formulaPlan->isExecutable(), 'Formula-protected CSV did not round-trip.');
transferTestAssert($formulaPlan->actions()[0]->candidate()['path'] === '-valid-path', 'Formula protection changed the imported path.');

$json = $service->exportJson(array_values($links));
$decoded = json_decode($json, true);
transferTestAssert(($decoded['schema'] ?? '') === LinkTransferService::SCHEMA, 'JSON export schema identifier is unstable or missing.');
transferTestAssert(($decoded['version'] ?? 0) === LinkTransferService::VERSION, 'JSON export schema version is unstable or missing.');
transferTestAssert(is_bool($decoded['links'][0]['enabled']), 'JSON export did not canonicalize enabled as boolean.');

$unsupported = $service->planImport(transferJson([], 99), 'json', new TransferOptions(), $validate, $find);
transferTestAssert(!$unsupported->isExecutable() && $unsupported->issues()[0]->code() === 'unsupported_schema', 'Unsupported JSON version was accepted.');
$invalidUtf8 = $service->planImport("{\"schema\":\"" . LinkTransferService::SCHEMA . "\",\"version\":1,\"links\":[\"\xFF\"]}", 'json', new TransferOptions(), $validate, $find);
transferTestAssert($invalidUtf8->issues()[0]->code() === 'invalid_utf8', 'Invalid UTF-8 was accepted.');

$smallRows = new LinkTransferService(new TransferLimits(32768, 2, 3));
$tooMany = $smallRows->planImport(transferJson([$newLink, $newLink, $newLink]), 'json', new TransferOptions(), $validate, $find);
transferTestAssert($tooMany->issues()[0]->code() === 'row_limit', 'Import row limit was not enforced.');
$smallBytes = new LinkTransferService(new TransferLimits(1024, 10, 3));
$tooLarge = $smallBytes->planImport(str_repeat('x', 1025), 'json', new TransferOptions(), $validate, $find);
transferTestAssert($tooLarge->issues()[0]->code() === 'byte_limit', 'Import byte limit was not enforced before parsing.');

$badRows = [];
for ($i = 0; $i < 6; $i++) {
	$badRows[] = ['path' => 'bad path ' . $i, 'target_url' => 'not-a-url', 'redirect_status' => 999, 'enabled' => true];
}
$boundedErrors = (new LinkTransferService(new TransferLimits(32768, 10, 2)))->planImport(
	transferJson($badRows),
	'json',
	new TransferOptions(),
	$validate,
	$find
);
transferTestAssert(count($boundedErrors->issues()) === 2 && $boundedErrors->errorsTruncated(), 'Import errors were not bounded and marked truncated.');

$beforeRollback = $links;
$rollbackPayload = transferJson([
	['path' => 'rollback/one', 'target_url' => 'https://example.com/one', 'redirect_status' => 302, 'enabled' => true],
	['path' => 'rollback/two', 'target_url' => 'https://example.com/two', 'redirect_status' => 302, 'enabled' => true],
]);
$rollbackPlan = $service->planImport($rollbackPayload, 'json', new TransferOptions(false, false), $validate, $find);
$failOnSecond = static function(array $candidate, TransferAction $action, ?array $existing) use (&$links, &$nextId): int {
	if ($candidate['path'] === 'rollback/two') throw new RuntimeException('simulated writer failure');
	$id = $nextId++;
	$links[$candidate['path']] = ['id' => $id] + $candidate;
	return $id;
};
$rollbackReport = $service->executePlan($rollbackPlan, $validate, $find, $failOnSecond, $transaction);
transferTestAssert(!$rollbackReport->committed() && !$rollbackReport->succeeded(), 'Failed transaction was reported as committed.');
transferTestAssert($links === $beforeRollback, 'Injected transaction boundary did not roll back all writes.');
transferTestAssert(!$rollbackReport->written(), 'Rolled-back IDs were exposed as written.');

$racePayload = transferJson([[
	'path' => 'race/path',
	'target_url' => 'https://example.com/race',
	'redirect_status' => 302,
	'enabled' => true,
]]);
$racePlan = $service->planImport($racePayload, 'json', new TransferOptions(false, false), $validate, $find);
$links['race/path'] = ['id' => 999, 'path' => 'race/path', 'target_url' => 'https://example.net/', 'redirect_status' => 302, 'enabled' => 1];
$raceWriterCalls = 0;
$raceWriter = static function() use (&$raceWriterCalls): int { $raceWriterCalls++; return 1000; };
$raceReport = $service->executePlan($racePlan, $validate, $find, $raceWriter, $transaction);
transferTestAssert(!$raceReport->committed() && $raceWriterCalls === 0, 'Create race overwrote a newly existing path.');

echo json_encode([
	'ok' => true,
	'tests' => [
		'dry_run_has_no_transaction_or_writes',
		'current_validation_plan_and_execute',
		'no_overwrite_default',
		'explicit_replacement_plan_and_commit',
		'csv_formula_injection_protection_and_round_trip',
		'stable_versioned_json_schema',
		'utf8_schema_row_byte_and_error_limits',
		'all_or_nothing_callback_transaction_rollback',
		'create_race_rejected_before_writer',
	],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
