<?php

require_once dirname(__DIR__) . '/src/CockpitPathPolicy.php';

$valid = [
	'go/ABC-_~.9' => 'go/abc-_~.9',
	'/campaign/summer/' => 'campaign/summer',
	'a' => 'a',
];

$invalid = [
	'', '/', 'a//b', 'a/./b', 'a/../b', '../admin', '.hidden', 'hidden.',
	'a\\b', 'a b', "a\tb", "a\nb", 'café', '%61', '%2f', '%252f',
	'r/%2e%2e/admin', 'r/。', 'r/／admin',
];

foreach ($valid as $input => $expected) {
	$actual = CockpitPathPolicy::canonicalize($input);
	if ($actual !== $expected) {
		fwrite(STDERR, "Expected {$input} => {$expected}; got " . var_export($actual, true) . "\n");
		exit(1);
	}
}

foreach ($invalid as $input) {
	if (CockpitPathPolicy::canonicalize($input) !== null) {
		fwrite(STDERR, "Expected invalid path: {$input}\n");
		exit(1);
	}
}

foreach (['/r/%61', '/r/%2Fadmin', '/r/%252e', '/r//a', '/r/../a'] as $input) {
	if (CockpitPathPolicy::fromRequestPath($input) !== null) {
		fwrite(STDERR, "Expected invalid request path: {$input}\n");
		exit(1);
	}
}

echo "Path policy tests passed\n";
