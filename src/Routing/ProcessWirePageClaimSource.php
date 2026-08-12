<?php

namespace Cockpit\Routing;

final class ProcessWirePageClaimSource implements RouteClaimSource {

	private object $pages;

	public function __construct(object $pages) {
		$this->pages = $pages;
	}

	public function name(): string { return 'processwire-pages'; }

	public function claimsFor(string $path): array {
		$path = RoutePath::normalize($path);
		$page = $this->pages->getByPath($path . ($path === '/' ? '' : '/'), [
			'allowUrl' => false,
			'allowPartial' => false,
			'allowUrlSegments' => true,
			'useLanguages' => true,
			'useHistory' => false,
		]);
		if (!$page || empty($page->id)) return [];

		$pagePath = RoutePath::normalize((string)$page->path);
		$isExact = $pagePath === $path;
		return [new RouteClaim(
			$isExact ? 'processwire.page' : 'processwire.url-segments',
			$this->name(),
			$isExact ? RouteClaim::KIND_EXACT : RouteClaim::KIND_NAMESPACE,
			$pagePath,
			10,
			[
				'page_id' => (int)$page->id,
				'page_path' => (string)$page->path,
				'title' => (string)$page->title,
				'template' => isset($page->template->name) ? (string)$page->template->name : '',
			]
		)];
	}
}
