<?php

namespace Cockpit\Code;

/**
 * Optional adapter for EPRC/Romain Cazier's MIT-licensed FieldtypeQRCode.
 * No provider source is bundled and the adapter never installs the module.
 */
final class FieldtypeQRCodeProvider implements CodeProviderInterface {
	private $isInstalled;
	private $moduleInfo;
	private $generator;
	private $capabilityCheck;

	public function __construct(callable $isInstalled, callable $moduleInfo, ?callable $generator = null, ?callable $capabilityCheck = null) {
		$this->isInstalled = $isInstalled;
		$this->moduleInfo = $moduleInfo;
		$this->generator = $generator;
		$this->capabilityCheck = $capabilityCheck;
	}

	public function id(): string { return 'fieldtype-qrcode'; }
	public function label(): string { return 'FieldtypeQRCode by EPRC / Romain Cazier'; }

	public function isAvailable(): bool {
		if (!(bool)call_user_func($this->isInstalled, 'FieldtypeQRCode')) return false;
		$version = $this->version();
		if ($version < 114 || $version >= 300) return false;
		if ($this->capabilityCheck) return (bool)call_user_func($this->capabilityCheck);
		if ($this->generator) return true;
		return class_exists('ProcessWire\\FieldtypeQRCode')
			&& is_callable(['ProcessWire\\FieldtypeQRCode', 'generateRawQRCode']);
	}

	public function capabilities(): array {
		return [
			'available' => $this->isAvailable(),
			'formats' => ['svg', 'gif'],
			'recovery_levels' => ['L', 'M', 'Q', 'H'],
			'minimum_version' => 114,
			'tested_majors' => [1, 2],
			'provider_version' => $this->version(),
			'provider_version_label' => $this->versionLabel(),
			'appearance_options' => $this->version() >= 201,
			'attribution' => 'FieldtypeQRCode by EPRC / Romain Cazier (MIT)',
			'directory_url' => 'https://processwire.com/modules/fieldtype-qrcode/',
			'repository_url' => 'https://github.com/eprcstudio/FieldtypeQRCode',
		];
	}

	public function generate(string $payload, array $options = []): CodeResult {
		if (!$this->isAvailable()) throw new \RuntimeException('FieldtypeQRCode 1.1.4–2.x is not installed or compatible.');
		if ($payload === '' || strlen($payload) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $payload)) {
			throw new \InvalidArgumentException('QR payload must contain 1–4096 safe bytes.');
		}

		$format = strtolower((string)($options['format'] ?? 'svg'));
		if (!in_array($format, ['svg', 'gif'], true)) throw new \InvalidArgumentException('QR format must be svg or gif.');
		$recovery = strtoupper((string)($options['recoveryLevel'] ?? 'M'));
		if (!in_array($recovery, ['L', 'M', 'Q', 'H'], true)) throw new \InvalidArgumentException('QR recovery level must be L, M, Q, or H.');
		$size = max(1, min(64, (int)($options['size'] ?? 4)));
		$foreground = $this->color((string)($options['foreground'] ?? '#000000'), 'foreground');
		$background = $this->color((string)($options['background'] ?? '#FFFFFF'), 'background');
		$providerOptions = [
			'svg' => $format === 'svg',
			'markup' => $format === 'svg',
			'recoveryLevel' => $recovery,
			'size' => $size,
			'foreground' => $foreground,
			'background' => $background,
			'transparent' => !empty($options['transparent']),
		];

		if ($this->generator) {
			$output = call_user_func($this->generator, $payload, $providerOptions);
		} elseif ($this->version() >= 201) {
			$output = \ProcessWire\FieldtypeQRCode::generateRawQRCode($payload, $providerOptions);
		} else {
			// FieldtypeQRCode 1.1.4 exposes the same documented generator with
			// positional format/markup/recovery arguments and no appearance options.
			$output = \ProcessWire\FieldtypeQRCode::generateRawQRCode(
				$payload,
				$providerOptions['svg'],
				$providerOptions['markup'],
				$providerOptions['recoveryLevel']
			);
		}
		if (!is_string($output) || $output === '') throw new \RuntimeException('FieldtypeQRCode returned empty output.');

		if ($format === 'svg') {
			$content = $this->validateSvg($output);
			$mime = 'image/svg+xml';
		} else {
			$content = $this->decodeGif($output);
			$mime = 'image/gif';
		}
		if (strlen($content) > 5 * 1024 * 1024) throw new \RuntimeException('Generated code exceeds the 5 MiB output limit.');

		return new CodeResult($this->id(), $format, $mime, $content, [
			'recovery_level' => $recovery,
			'size' => $size,
			'transparent' => $providerOptions['transparent'],
			'provider_version' => $this->version(),
		]);
	}

	private function version(): int {
		if (!(bool)call_user_func($this->isInstalled, 'FieldtypeQRCode')) return 0;
		$info = call_user_func($this->moduleInfo, 'FieldtypeQRCode');
		if (!is_array($info)) return 0;
		$value = $info['version'] ?? 0;
		if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
		if (is_string($value) && preg_match('/^(\d+)\.(\d+)\.(\d+)/', $value, $matches)) {
			return ((int)$matches[1] * 100) + ((int)$matches[2] * 10) + (int)$matches[3];
		}
		return 0;
	}

	private function versionLabel(): string {
		if (!(bool)call_user_func($this->isInstalled, 'FieldtypeQRCode')) return '';
		$info = call_user_func($this->moduleInfo, 'FieldtypeQRCode');
		return is_array($info) ? (string)($info['version'] ?? '') : '';
	}

	private function color(string $value, string $name): string {
		if (!preg_match('/^#[0-9A-Fa-f]{6}$/D', $value)) throw new \InvalidArgumentException("QR {$name} must be a six-digit hexadecimal color.");
		return strtoupper($value);
	}

	private function validateSvg(string $svg): string {
		if (strlen($svg) > 5 * 1024 * 1024 || !preg_match('/^\s*<svg\b/i', $svg)) {
			throw new \RuntimeException('FieldtypeQRCode returned invalid SVG markup.');
		}
		if (preg_match('/<(?:script|foreignObject|iframe|object|embed|audio|video)\b|\son[a-z]+\s*=|(?:href|src)\s*=\s*["\']\s*(?:https?:|\/\/|data:)/i', $svg)) {
			throw new \RuntimeException('FieldtypeQRCode SVG contains an unsafe element or external reference.');
		}
		return $svg;
	}

	private function decodeGif(string $data): string {
		$prefix = 'data:image/gif;base64,';
		if (strpos($data, $prefix) !== 0) throw new \RuntimeException('FieldtypeQRCode returned an invalid GIF data URI.');
		$decoded = base64_decode(substr($data, strlen($prefix)), true);
		if (!is_string($decoded) || !in_array(substr($decoded, 0, 6), ['GIF87a', 'GIF89a'], true)) {
			throw new \RuntimeException('FieldtypeQRCode returned invalid GIF data.');
		}
		return $decoded;
	}
}
