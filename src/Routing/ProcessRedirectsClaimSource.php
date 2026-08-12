<?php

namespace Cockpit\Routing;

final class ProcessRedirectsClaimSource implements RouteClaimSource {

	private object $database;
	private $isInstalled;
	private int $wildcardLimit;

	public function __construct(object $database, callable $isInstalled, int $wildcardLimit = 1000) {
		$this->database = $database;
		$this->isInstalled = $isInstalled;
		$this->wildcardLimit = max(1, min(10000, $wildcardLimit));
	}

	public function name(): string { return 'process-redirects'; }

	public function claimsFor(string $path): array {
		if (!(bool)call_user_func($this->isInstalled, 'ProcessRedirects')) return [];
		$path = RoutePath::normalize($path);
		$stmt = $this->database->prepare(
			'SELECT `id`,`redirect_from`,`redirect_to` FROM `process_redirects` '
			. 'WHERE TRIM(TRAILING \'/\' FROM `redirect_from`)=:path OR `redirect_from` LIKE \'%*%\' '
			. 'ORDER BY `id` ASC LIMIT ' . ($this->wildcardLimit + 1)
		);
		$stmt->execute([':path' => $path]);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		if (count($rows) > $this->wildcardLimit) {
			throw new \RuntimeException('ProcessRedirects wildcard inspection limit exceeded.');
		}

		$claims = [];
		foreach ($rows as $row) {
			$from = (string)$row['redirect_from'];
			$pathOnly = explode('?', $from, 2)[0];
			$kind = strpos($pathOnly, '*') === false ? RouteClaim::KIND_EXACT : RouteClaim::KIND_WILDCARD;
			$claim = new RouteClaim(
				'process-redirects.redirect',
				$this->name(),
				$kind,
				$pathOnly,
				50,
				[
					'redirect_id' => (int)$row['id'],
					'from_url' => $from,
					'to_url' => (string)$row['redirect_to'],
				]
			);
			if ($claim->matches($path)) $claims[] = $claim;
		}
		return $claims;
	}
}
