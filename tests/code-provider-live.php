#!/usr/bin/env php
<?php namespace ProcessWire;

/** Read-only contract test against an installed FieldtypeQRCode provider. */

function cockpitCodeProviderLiveFail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

$options = getopt('', ['site-root:']);
$siteRoot = rtrim((string)($options['site-root'] ?? getenv('COCKPIT_PROCESSWIRE_ROOT') ?: ''), '/');
if ($siteRoot === '' || !is_file($siteRoot . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/code-provider-live.php --site-root=/path/to/processwire\n");
	exit(2);
}

chdir($siteRoot);
require $siteRoot . '/index.php';
if (!$wire->modules->isInstalled('Cockpit')) cockpitCodeProviderLiveFail('Cockpit is not installed.');
if (!$wire->modules->isInstalled('FieldtypeQRCode')) {
	echo "SKIP FieldtypeQRCode is not installed\n";
	exit(0);
}

/** @var Cockpit $cockpit */
$cockpit = $wire->modules->get('Cockpit');
$status = $cockpit->getCodeProviderStatus()['fieldtype-qrcode'] ?? null;
if (!$status || empty($status['available'])) cockpitCodeProviderLiveFail('Installed FieldtypeQRCode is not available through Cockpit.');
$provider = $cockpit->getCodeProvider('fieldtype-qrcode');
if (!$provider) cockpitCodeProviderLiveFail('FieldtypeQRCode provider is missing.');
$payload = 'https://example.com/cockpit-provider-contract';
$result = $provider->generate($payload, ['format' => 'svg', 'recoveryLevel' => 'M']);
if ($result->mimeType() !== 'image/svg+xml' || strpos(ltrim($result->content()), '<svg') !== 0) {
	cockpitCodeProviderLiveFail('Installed provider returned an invalid SVG contract.');
}
if (strlen($result->content()) < 100) cockpitCodeProviderLiveFail('Installed provider returned an unexpectedly small SVG.');

echo json_encode([
	'ok' => true,
	'provider' => $status['label'],
	'version' => $status['provider_version_label'] ?? $status['provider_version'],
	'format' => $result->format(),
	'bytes' => strlen($result->content()),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
