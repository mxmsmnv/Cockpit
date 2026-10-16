<?php namespace ProcessWire;

/** Permission-gated aggregate link and click totals for PocketWire. */
trait CockpitPocketWireProviderTrait {
	public function pocketWireProviderInfo(): array {
		return ['id' => 'cockpit', 'title' => 'Cockpit', 'module' => 'Cockpit', 'moduleVersion' => '1.0.5'];
	}

	public function pocketWireManifest(User $actor): array {
		$ready = $this->pocketWireCanRead($actor);
		return [
			'id' => 'cockpit', 'title' => 'Cockpit', 'module' => 'Cockpit', 'moduleVersion' => '1.0.5',
			'status' => $ready ? 'native_read_only' : 'unsupported',
			'permissions' => ['scope' => 'providers.read', 'processWire' => ['cockpit-view-stats', 'cockpit-manage']],
			'presentation' => ['layout' => 'dashboard', 'icon' => 'link-outline', 'summary' => 'Aggregate short-link and click totals.', 'accentColor' => '#0F766E'],
			'resources' => $ready ? [['id' => 'summary', 'title' => 'Summary', 'method' => 'GET', 'endpoint' => '/api/pocketwire/v1/providers/cockpit/summary', 'presentation' => ['layout' => 'metrics']]] : [],
			'actions' => [],
		];
	}

	public function pocketWireRead(string $resource, string $identifier, array $query, User $actor): array {
		if(!$this->pocketWireCanRead($actor)) throw new WirePermissionException('The cockpit-view-stats permission is required.');
		if($resource !== 'summary' || $identifier !== '') throw new Wire404Exception('Cockpit resource was not found.');
		$totals = $this->getDashboardTotals();
		return [
			'version' => '1.0.5',
			'links' => [
				'total' => (int)($totals['links'] ?? 0),
				'active' => (int)($totals['active_links'] ?? 0),
				'disabled' => (int)($totals['disabled_links'] ?? 0),
			],
			'clicks' => [
				'lifetime' => (int)($totals['total'] ?? 0),
				'today' => (int)($totals['today'] ?? 0),
				'seven_days' => (int)($totals['seven_days'] ?? 0),
				'thirty_days' => (int)($totals['thirty_days'] ?? 0),
			],
			'link_records_exposed' => false,
			'external_request_performed' => false,
			'write_tools' => false,
		];
	}

	private function pocketWireCanRead(User $actor): bool {
		$current = $this->wire('user');
		return $current instanceof User
			&& (int)$current->id === (int)$actor->id
			&& ($actor->isSuperuser() || ($actor->isLoggedin() && (
				$actor->hasPermission('cockpit-view-stats') || $actor->hasPermission('cockpit-manage')
			)));
	}
}
