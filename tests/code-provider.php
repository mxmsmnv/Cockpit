<?php

require_once dirname(__DIR__) . '/src/Code/bootstrap.php';

use Cockpit\Code\FieldtypeQRCodeProvider;

$provider = new FieldtypeQRCodeProvider(
	static function(string $name): bool { return $name === 'FieldtypeQRCode'; },
	static function(string $name): array { return ['version' => $name === 'FieldtypeQRCode' ? 201 : 0]; },
	static function(string $payload, array $options): string {
		if ($options['svg']) return '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z"/></svg>';
		return 'data:image/gif;base64,' . base64_encode('GIF89a' . $payload);
	}
);

$assert = static function(bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};
$rejects = static function(callable $callback, string $message): void {
	try { $callback(); } catch (Throwable $exception) { return; }
	throw new RuntimeException($message);
};

$assert($provider->isAvailable(), 'Compatible provider was not available.');
$svg = $provider->generate('https://example.com/r/a', ['format' => 'svg', 'recoveryLevel' => 'H']);
$assert($svg->mimeType() === 'image/svg+xml' && $svg->extension() === 'svg', 'SVG result metadata is invalid.');
foreach (['L', 'M', 'Q', 'H'] as $recoveryLevel) {
	$assert($provider->generate('https://example.com/r/' . strtolower($recoveryLevel), ['recoveryLevel' => $recoveryLevel])->mimeType() === 'image/svg+xml', "Recovery level {$recoveryLevel} failed.");
}
$gif = $provider->generate('test', ['format' => 'gif']);
$assert(strpos($gif->content(), 'GIF89a') === 0, 'GIF data URI was not decoded.');
$rejects(function() use ($provider): void { $provider->generate('x', ['foreground' => 'red']); }, 'Invalid color was accepted.');

$unsafe = new FieldtypeQRCodeProvider(
	static function(string $name): bool { return true; },
	static function(string $name): array { return ['version' => 201]; },
	static function(string $payload, array $options): string { return '<svg><script>alert(1)</script></svg>'; }
);
$rejects(function() use ($unsafe): void { $unsafe->generate('x'); }, 'Unsafe SVG was accepted.');

$legacy = new FieldtypeQRCodeProvider(
	static function(string $name): bool { return true; },
	static function(string $name): array { return ['version' => '1.1.4']; },
	static function(string $payload, array $options): string {
		if ($options['recoveryLevel'] !== 'Q') throw new RuntimeException('Legacy recovery option was not preserved.');
		return '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z"/></svg>';
	}
);
$assert($legacy->isAvailable(), 'Documented FieldtypeQRCode 1.1.4 provider was not available.');
$legacyCapabilities = $legacy->capabilities();
$assert($legacyCapabilities['provider_version'] === 114 && $legacyCapabilities['provider_version_label'] === '1.1.4', 'Semantic provider version was not normalized.');
$assert($legacyCapabilities['appearance_options'] === false, 'Legacy provider incorrectly advertises 2.x appearance options.');
$assert($legacy->generate('https://example.com/r/legacy', ['recoveryLevel' => 'Q'])->mimeType() === 'image/svg+xml', 'Legacy provider generation failed.');

$tooOld = new FieldtypeQRCodeProvider(
	static function(string $name): bool { return true; },
	static function(string $name): array { return ['version' => '1.1.3']; },
	static function(string $payload, array $options): string { return '<svg></svg>'; }
);
$assert(!$tooOld->isAvailable(), 'Untested FieldtypeQRCode version was accepted.');

echo "Code provider tests passed\n";
