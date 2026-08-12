#!/usr/bin/env php
<?php namespace ProcessWire;

/** Read-only runtime test for the module configuration input hierarchy. */

function cockpitConfigUiFail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

$options = getopt('', ['site-root:']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
if ($siteRoot === '' || !is_file($siteRoot . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/config-ui.php --site-root=/path/to/processwire\n");
	exit(2);
}

chdir($siteRoot);
require $siteRoot . '/index.php';
if (!$wire->modules->isInstalled('Cockpit')) cockpitConfigUiFail('Cockpit is not installed.');
/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$wrapper = $wire->wire(new InputfieldWrapper());
$rendered = $cockpit->getModuleConfigInputfields($wrapper);
if ($rendered !== $wrapper) cockpitConfigUiFail('Config builder returned a different wrapper.');

$labels = [];
foreach ($wrapper->children() as $child) {
	$labels[] = html_entity_decode((string)$child->label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
foreach (['Links', 'Routing', 'Analytics', 'Integrations', 'Data & privacy'] as $label) {
	if (!in_array($label, $labels, true)) cockpitConfigUiFail("Missing top-level config section: {$label}.");
}

$expectedFields = [
	'public_base_url', 'base_path', 'generated_code_length', 'default_redirect_status',
	'new_links_enabled', 'append_trailing_slash', 'reserved_paths', 'allowed_target_hosts',
	'blocked_target_hosts', 'forward_query_string', 'send_no_cache_headers',
	'allow_any_public_target_host', 'statistics_enabled', 'ignore_known_bots',
	'count_head_requests', 'excluded_ips', 'statistics_retention_days', 'delete_data_on_uninstall',
];
foreach ($expectedFields as $name) {
	if (!$wrapper->getChildByName($name)) cockpitConfigUiFail("Missing config input: {$name}.");
}

$config = (array)$wire->modules->getModuleConfigData('Cockpit');
foreach (['public_base_url', 'base_path', 'reserved_paths'] as $name) {
	if (!array_key_exists($name, $config)) continue;
	$field = $wrapper->getChildByName($name);
	if ((string)$field->attr('value') !== (string)$config[$name]) cockpitConfigUiFail("Rendered value drifted for {$name}.");
}

echo "Config UI runtime tests passed\n";
