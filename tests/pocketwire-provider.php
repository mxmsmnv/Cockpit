<?php
$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Cockpit.module.php');
$trait = (string)file_get_contents($root . '/src/CockpitPocketWireProviderTrait.php');
$read = strstr($trait, 'public function pocketWireRead');
$read = $read === false ? '' : (strstr($read, 'private function pocketWireCanRead', true) ?: '');
$checks = [
	str_contains($module, "'pocketWireProvider' => true"),
	str_contains($trait, 'pocketWireProviderInfo()'),
	str_contains($trait, 'pocketWireManifest(User $actor)'),
	str_contains($trait, 'pocketWireRead(string $resource, string $identifier, array $query, User $actor)'),
	str_contains($trait, "wire('user')") && str_contains($trait, "hasPermission('cockpit-view-stats')") && str_contains($trait, "hasPermission('cockpit-manage')"),
	str_contains($trait, "'id' => 'summary'") && str_contains($trait, "'actions' => []"),
	str_contains($trait, "'layout' => 'dashboard'") && str_contains($trait, "'layout' => 'metrics'"),
	str_contains($trait, "'external_request_performed' => false") && str_contains($trait, "'write_tools' => false"),
	$read !== '' && str_contains($read, 'getDashboardTotals()'),
	$read !== '' && !preg_match('/findLink|target_url|path|audit|purge|save|delete|import|export|http/i', $read),
];
if(in_array(false, $checks, true)) { fwrite(STDERR, "Cockpit PocketWire provider contract failed.\n"); exit(1); }
echo 'Cockpit PocketWire provider contract passed: ' . count($checks) . " checks.\n";
