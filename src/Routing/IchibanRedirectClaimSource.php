<?php

namespace Cockpit\Routing;

final class IchibanRedirectClaimSource implements RouteClaimSource {

	private object $database;
	private $isInstalled;
	private int $regexLimit;

	public function __construct(object $database, callable $isInstalled, int $regexLimit = 500) {
		$this->database = $database;
		$this->isInstalled = $isInstalled;
		$this->regexLimit = max(1, min(5000, $regexLimit));
	}

	public function name(): string { return 'ichiban-redirects'; }

	public function claimsFor(string $path): array {
		if (!(bool)call_user_func($this->isInstalled, 'Ichiban')) return [];
		$path = RoutePath::normalize($path);
		$candidates = [$path];
		if ($path !== '/') $candidates[] = $path . '/';

		$stmt = $this->database->prepare(
			'SELECT `id`,`from_url`,`to_url`,`type`,`is_regex`,`auto` FROM `ichiban_redirects` '
			. 'WHERE `is_regex`=0 AND (`from_url`=:path OR `from_url`=:path_slash) LIMIT 2'
		);
		$stmt->execute([':path' => $candidates[0], ':path_slash' => $candidates[1] ?? $candidates[0]]);
		$claims = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$claims[] = $this->claim($row, RouteClaim::KIND_EXACT, $path);
		}

		$sql = 'SELECT `id`,`from_url`,`to_url`,`type`,`is_regex`,`auto` FROM `ichiban_redirects` '
			. 'WHERE `is_regex`=1 LIMIT ' . $this->regexLimit;
		foreach ($this->database->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$pattern = '@' . str_replace('@', '\\@', (string)$row['from_url']) . '@';
			if (@preg_match($pattern, $path) !== 1 && @preg_match($pattern, $path . '/') !== 1) continue;
			$claims[] = $this->claim($row, RouteClaim::KIND_REGEX, (string)$row['from_url']);
		}
		return $claims;
	}

	private function claim(array $row, string $kind, string $pattern): RouteClaim {
		return new RouteClaim(
			'ichiban.redirect',
			$this->name(),
			$kind,
			$pattern,
			30,
			[
				'redirect_id' => (int)$row['id'],
				'from_url' => (string)$row['from_url'],
				'to_url' => (string)$row['to_url'],
				'status' => (int)$row['type'],
				'auto' => !empty($row['auto']),
			]
		);
	}
}
