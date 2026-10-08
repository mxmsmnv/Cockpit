<?php namespace ProcessWire;

require_once __DIR__ . '/src/Access/CockpitPermissionPolicy.php';
require_once __DIR__ . '/src/Audit/CockpitAuditLog.php';

/**
 * ProcessCockpit — ProcessWire admin UI for Cockpit.
 */
class ProcessCockpit extends Process {

	/** @var Cockpit */
	protected $cockpit;

	/** @var CockpitPermissionPolicy */
	protected $permissionPolicy;

	/** @var CockpitAuditLog */
	protected $auditLog;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Cockpit Admin',
			'summary' => 'Create short links and review click statistics.',
			'author' => 'Maxim Semenov',
			'href' => 'https://github.com/mxmsmnv/Cockpit',
			'version' => 104,
			'requires' => 'Cockpit',
			'page' => [
				'name' => 'cockpit',
				'title' => 'Cockpit',
				'parent' => 'admin',
				'icon' => 'link',
			],
			'permissions' => [
				CockpitPermissionPolicy::LEGACY => 'Legacy umbrella: manage all Cockpit features',
				CockpitPermissionPolicy::VIEW_STATS => 'View Cockpit click statistics',
				CockpitPermissionPolicy::MANAGE_LINKS => 'Create and update Cockpit links',
				CockpitPermissionPolicy::DELETE_LINKS => 'Delete Cockpit links and their statistics',
				CockpitPermissionPolicy::IMPORT_EXPORT => 'Import and export Cockpit data',
				CockpitPermissionPolicy::SETTINGS => 'Access Cockpit settings',
				CockpitPermissionPolicy::VIEW_AUDIT => 'View the Cockpit administrative audit log',
			],
		];
	}

	public function init(): void {
		parent::init();
		$this->cockpit = $this->wire('modules')->get('Cockpit');
		$this->permissionPolicy = new CockpitPermissionPolicy($this->wire('user'));
		$this->auditLog = new CockpitAuditLog($this->cockpit);
		$url = (string)$this->wire('config')->urls->Cockpit;
		$path = (string)$this->wire('config')->paths->Cockpit;
		$assetVersion = self::getModuleInfo()['version'] . '-' . max(
			(int)@filemtime($path . 'assets/css/process-cockpit.css'),
			(int)@filemtime($path . 'assets/js/process-cockpit.js'),
			(int)@filemtime($path . 'assets/js/cockpit-utm.js')
		);
		$this->wire('config')->styles->add($url . 'assets/css/process-cockpit.css?v=' . $assetVersion);
		$this->wire('config')->scripts->add($url . 'assets/js/cockpit-utm.js?v=' . $assetVersion);
		$this->wire('config')->scripts->add($url . 'assets/js/process-cockpit.js?v=' . $assetVersion);
	}

	public function ___upgrade($fromVersion, $toVersion): void {
		$this->ensureGranularPermissions();
	}

	protected function ensureGranularPermissions(): void {
		$definitions = self::getModuleInfo()['permissions'];
		$permissions = $this->wire('permissions');
		foreach ($definitions as $name => $title) {
			$permission = $permissions->get($name);
			if ($permission && $permission->id) continue;
			$permission = $permissions->add($name);
			$permission->title = $title;
			$permissions->save($permission);
		}
	}

	public function execute(): string {
		if (!$this->permissionPolicy->allowsAny()) {
			throw new WirePermissionException($this->_('You do not have permission to access Cockpit.'));
		}
		$this->processActions();

		$canManage = $this->permissionPolicy->allows(CockpitPermissionPolicy::MANAGE_LINKS);
		$canViewStats = $this->permissionPolicy->allows(CockpitPermissionPolicy::VIEW_STATS);
		$canViewAudit = $this->permissionPolicy->allows(CockpitPermissionPolicy::VIEW_AUDIT);
		$allowedViews = [];
		if ($canViewStats) $allowedViews[] = 'overview';
		if ($canManage) $allowedViews[] = 'links';
		if ($canViewStats) $allowedViews[] = 'analytics';
		if ($canViewAudit) $allowedViews[] = 'audit';
		$view = (string)$this->wire('input')->get->text('view');
		if (!in_array($view, $allowedViews, true)) $view = $allowedViews[0] ?? 'overview';
		$editingId = (int)$this->wire('input')->get->int('edit');
		$qrLinkId = (int)$this->wire('input')->get->int('qr');
		$managedLinkId = (int)$this->wire('input')->get->int('link') ?: ($editingId ?: $qrLinkId);
		if ($managedLinkId && (!$canManage || $view !== 'links')) {
			throw new WirePermissionException($this->_('You do not have permission to edit Cockpit links.'));
		}
		$managedLink = $managedLinkId ? $this->cockpit->findLinkById($managedLinkId) : null;
		if ($managedLinkId && !$managedLink) throw new Wire404Exception($this->_('Short link not found.'));
		$this->configurePageChrome($view, $managedLink);

		$out = '<div class="pw-wrap pw-module-workspace ProcessCockpit cockpit-workspace">'
			. $this->renderAdminNav($view, $canManage, $canViewStats, $canViewAudit)
			. ($managedLink ? $this->renderLinkPageIntro($managedLink) : $this->renderPageIntro($view, $canManage, $canViewStats))
			. '<div class="cockpit-admin">';
		if ($view === 'overview' && $canViewStats) {
			$overviewLinks = $this->cockpit->findLinks(['limit' => 500]);
			$out .= $this->renderOverview(
				$this->cockpit->getDashboardTotals(),
				$overviewLinks,
				$this->cockpit->getStatistics('day'),
				$canManage
			);
		} elseif ($view === 'links' && $canManage) {
			if ($managedLink) {
				$out .= $this->renderLinkManagementPage($managedLink, $canViewStats, $canViewAudit, $this->permissionPolicy->allowsDelete());
			} else {
				$out .= $this->renderLinksTable($this->linkListState(), $this->permissionPolicy->allowsDelete(), $canViewStats);
				$out .= $this->renderEditor(null);
			}
		} elseif ($view === 'analytics' && $canViewStats) {
			$analytics = $this->cockpit->getAnalytics([
				'preset' => (string)$this->wire('input')->get->text('preset'),
				'date_from' => (string)$this->wire('input')->get->text('date_from'),
				'date_to' => (string)$this->wire('input')->get->text('date_to'),
				'group' => (string)$this->wire('input')->get->text('group'),
				'link_id' => (int)$this->wire('input')->get->int('link_id'),
				'state' => (string)$this->wire('input')->get->text('state'),
				'status' => (int)$this->wire('input')->get->int('status'),
			]);
			$out .= $this->renderStatistics($this->cockpit->findLinks(['limit' => 500]), $analytics);
		} elseif ($view === 'audit' && $canViewAudit) {
			$auditState = $this->auditListState();
			$out .= $this->renderAuditLog($auditState['rows'], $auditState);
		}
		if (!$allowedViews && $this->permissionPolicy->allows(CockpitPermissionPolicy::SETTINGS)) $out .= $this->renderSettingsAccess();
		if (!$canManage && !$canViewStats && !$canViewAudit
			&& ($this->permissionPolicy->allows(CockpitPermissionPolicy::IMPORT_EXPORT)
				|| $this->permissionPolicy->allows(CockpitPermissionPolicy::DELETE_LINKS))) {
			$out .= $this->renderCapabilityNotice();
		}
		$out .= '</div></div>';
		return $out;
	}

	/**
	 * Keep the admin headline, browser title, and breadcrumbs aligned with the
	 * selected Cockpit workspace view.
	 */
	protected function configurePageChrome(string $view, ?array $managedLink = null): void {
		$views = [
			'overview' => [$this->_('Dashboard'), ''],
			'links' => [$this->_('Links'), '?view=links'],
			'analytics' => [$this->_('Analytics'), '?view=analytics'],
			'audit' => [$this->_('Audit log'), '?view=audit'],
		];
		[$label, $relativeUrl] = $views[$view] ?? [$this->_('Cockpit'), ''];
		$baseUrl = rtrim((string)$this->wire('config')->urls->admin, '/') . '/cockpit/';

		$this->breadcrumb($baseUrl, $this->_('Cockpit'));
		if ($relativeUrl !== '') {
			$this->breadcrumb($baseUrl . $relativeUrl, $label);
		}
		if ($managedLink) {
			$label = '/' . (string)$managedLink['path'];
			$this->breadcrumb($baseUrl . '?view=links&link=' . (int)$managedLink['id'], $label);
		}

		$this->headline($label);
		$this->browserTitle(sprintf($this->_('%s — Cockpit'), $label));
	}

	protected function processActions(): void {
		$action = (string)$this->wire('input')->post->text('action');
		if ($action === '') return;

		try {
			if ($action === 'save') {
				$this->requireCapability(CockpitPermissionPolicy::MANAGE_LINKS);
				$this->wire('session')->CSRF->validate();
				$requestedId = (int)$this->wire('input')->post->int('id');
				$before = $requestedId > 0 ? $this->cockpit->findLinkById($requestedId) : null;
				$id = $this->cockpit->saveLink([
					'id' => $requestedId,
					'path' => (string)$this->wire('input')->post->text('path'),
					'target_url' => trim((string)$this->wire('input')->post('target_url')),
					'redirect_status' => (int)$this->wire('input')->post->int('redirect_status'),
					'enabled' => (bool)$this->wire('input')->post->int('enabled'),
				]);
				$this->recordAuditSafely($before, $this->cockpit->findLinkById($id));
				$this->wire('session')->message($this->_('Short link saved.'));
				$returnView = (string)$this->wire('input')->post->text('return_view');
				if ($requestedId === 0 && $returnView === 'overview') {
					$this->wire('session')->redirect('./?created=' . $id);
				}
				$this->wire('session')->redirect('./?view=links&link=' . $id);
			}

			if ($action === 'delete') {
				if (!$this->permissionPolicy->allowsDelete()) {
					throw new WirePermissionException($this->_('You do not have permission to delete Cockpit links.'));
				}
				$this->wire('session')->CSRF->validate();
				$id = (int)$this->wire('input')->post->int('id');
				$before = $this->cockpit->findLinkById($id);
				if (!$before) throw new WireException($this->_('Short link not found.'));
				$this->cockpit->deleteLink($id);
				$this->recordAuditSafely($before, null);
				$this->wire('session')->message($this->_('Short link and its statistics were deleted.'));
				$this->wire('session')->redirect('./?view=links');
			}

			throw new WireException($this->_('Unknown Cockpit action.'));
		} catch (\Throwable $exception) {
			$this->wire('session')->error($exception->getMessage());
		}
	}

	protected function renderAdminNav(string $active, bool $canManage, bool $canViewStats, bool $canViewAudit): string {
		$items = [];
		if ($canViewStats) $items['overview'] = $this->_('Dashboard');
		if ($canManage) $items['links'] = $this->_('Links');
		if ($canViewStats) $items['analytics'] = $this->_('Analytics');
		if ($canViewAudit) $items['audit'] = $this->_('Audit');
		$out = '<div class="cockpit-admin-nav uk-flex uk-flex-top"><div class="uk-width-expand"><ul class="uk-subnav uk-subnav-pill cockpit-admin-nav-list" aria-label="' . $this->e($this->_('Cockpit sections')) . '">';
		foreach ($items as $key => $label) {
			$out .= '<li' . ($key === $active ? ' class="uk-active"' : '') . '><a href="./?view=' . $key . '">'
				. $this->e($label) . '</a></li>';
		}
		$out .= '</ul></div>';
		if ($this->permissionPolicy->allows(CockpitPermissionPolicy::SETTINGS)) {
			$settingsUrl = rtrim((string)$this->wire('config')->urls->admin, '/') . '/module/edit?name=Cockpit&collapse_info=1';
			$out .= '<div class="uk-width-auto"><a class="cockpit-admin-settings" href="' . $this->e($settingsUrl) . '" title="'
				. $this->e($this->_('Cockpit settings')) . '" aria-label="' . $this->e($this->_('Cockpit settings')) . '">' . $this->settingsIcon() . '</a></div>';
		}
		return $out . '</div>';
	}

	protected function renderPageIntro(string $view, bool $canManage, bool $canViewStats): string {
		$copy = [
			'overview' => [$this->_('Cockpit'), $this->_('Monitor link traffic, campaign health, and recent activity from one focused workspace.')],
			'links' => [$this->_('Link workspace'), $this->_('Create, search, and manage stable short URLs without changing their destinations in print.')],
			'analytics' => [$this->_('Traffic analytics'), $this->_('Compare periods and filter aggregate clicks without collecting visitor identities.')],
			'audit' => [$this->_('Administrative history'), $this->_('Review privacy-minimal link changes without storing destinations or request data.')],
		];
		[$eyebrow, $description] = $copy[$view] ?? [$this->_('Cockpit'), $this->_('Manage short links and aggregate traffic.')];
		$actions = '';
		if ($view === 'overview') {
			if ($canManage) $actions .= '<a class="uk-button uk-button-primary" href="./?view=links">' . $this->_('Manage links') . '</a>';
			if ($canViewStats) $actions .= '<a class="uk-button uk-button-default" href="./?view=analytics">' . $this->_('Open analytics') . '</a>';
		}
		return '<section class="cockpit-page-intro"><div class="cockpit-page-intro-copy"><p class="cockpit-page-intro-eyebrow">'
			. $this->e($eyebrow) . '</p><p>' . $this->e($description) . '</p></div>'
			. ($actions !== '' ? '<div class="cockpit-page-intro-actions">' . $actions . '</div>' : '') . '</section>';
	}

	protected function renderLinkPageIntro(array $link): string {
		$shortUrl = $this->cockpit->shortUrl((string)$link['path']);
		$actions = '<a class="uk-button uk-button-default" href="./?view=links">' . $this->_('Back to links') . '</a>'
			. '<button class="uk-button uk-button-default" type="button" data-copy="' . $this->e($shortUrl) . '">' . $this->_('Copy short URL') . '</button>'
			. '<a class="uk-button uk-button-primary" href="' . $this->e($shortUrl) . '" target="_blank" rel="noopener">' . $this->_('Open short URL') . '</a>';
		return '<section class="cockpit-page-intro cockpit-link-page-intro"><div class="cockpit-page-intro-copy"><p class="cockpit-page-intro-eyebrow">'
			. $this->_('Managed link') . '</p><p>' . $this->_('Traffic, QR code, destination, status, editing, and administrative history for this short URL.')
			. '</p><code class="cockpit-link-page-url">' . $this->e($shortUrl) . '</code></div><div class="cockpit-page-intro-actions">'
			. $actions . '</div></section>';
	}

	protected function renderOverview(array $totals, array $links, array $dailyStats, bool $canManage): string {
		$out = $this->renderSummary($totals);
		if ($canManage) $out .= $this->renderQuickCreate();

		$clickShares = [];
		foreach ($links as $link) {
			$hits = (int)$link['hits'];
			if ($hits < 1) continue;
			$clickShares[] = [
				'id' => (int)$link['id'],
				'path' => (string)$link['path'],
				'label' => '/' . (string)$link['path'],
				'value' => $hits,
				'enabled' => !empty($link['enabled']),
				'status' => (int)$link['redirect_status'],
				'last_hit_at' => (string)($link['last_hit_at'] ?? ''),
			];
		}
		usort($clickShares, static function(array $a, array $b): int { return $b['value'] <=> $a['value']; });
		$clickShares = array_slice($clickShares, 0, 6);

		$out .= '<div class="cockpit-insight-grid">'
			. $this->renderLineChartCard($dailyStats, $this->_('Traffic trend'), $this->_('Clicks across all links during the last 30 days.'))
			. $this->renderDonutChartCard($clickShares, $this->_('Lifetime click share'), $this->_('How lifetime clicks are distributed across the top campaigns.'))
			. '</div>';

		$out .= '<section class="cockpit-panel cockpit-top-links"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Performance') . '</p><h2>'
			. $this->_('Top links') . '</h2><p class="uk-text-meta">' . $this->_('Highest lifetime traffic across the current link set.')
			. '</p></div>';
		if ($this->permissionPolicy->allows(CockpitPermissionPolicy::MANAGE_LINKS)) {
			$out .= '<a class="uk-button uk-button-default uk-button-small" href="./?view=links">' . $this->_('View all links') . '</a>';
		}
		$out .= '</div>';
		if (!$clickShares) return $out . $this->renderEmptyState(
			$this->_('No click activity yet'),
			$this->_('Create a link or share an active short URL to start collecting aggregate traffic.'),
			'./?view=links',
			$this->_('Manage links'),
			'link'
		) . '</section>';
		$out .= '<div class="cockpit-table-panel pw-table-panel uk-overflow-auto"><table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small"><thead><tr><th>'
			. $this->_('Short URL') . '</th><th>' . $this->_('Status') . '</th><th class="uk-text-right">' . $this->_('Clicks') . '</th><th>'
			. $this->_('Last click') . '</th><th><span class="cockpit-visually-hidden">' . $this->_('Actions') . '</span></th></tr></thead><tbody>';
		foreach ($clickShares as $index => $row) {
			$primaryUrl = $canManage ? './?view=links&link=' . $row['id'] : $this->cockpit->shortUrl($row['path']);
			$shortUrl = $this->cockpit->shortUrl($row['path']);
			$lastClick = $row['last_hit_at'] !== '' ? date('M j, Y · H:i', strtotime($row['last_hit_at'])) : '—';
			$out .= '<tr><td><div class="cockpit-ranked-link"><span class="cockpit-rank" aria-label="' . $this->e(sprintf($this->_('Rank %d'), $index + 1)) . '">#' . ($index + 1) . '</span><div class="cockpit-short-url"><a href="' . $this->e($primaryUrl) . '"' . (!$canManage ? ' target="_blank" rel="noopener"' : '') . '><code>'
				. $this->e($row['label']) . '</code></a>' . $this->renderIconButton('button', $this->_('Copy short URL'), 'copy', '', 'data-copy="' . $this->e($shortUrl) . '" data-copy-success="' . $this->e($this->_('Short URL copied')) . '"')
				. '</div></div></td><td><div class="cockpit-status-cell"><small class="cockpit-status-code">' . $row['status'] . '</small><span class="uk-label ' . ($row['enabled'] ? 'uk-label-success' : 'uk-label-warning') . '">'
				. ($row['enabled'] ? $this->_('Active') : $this->_('Disabled')) . '</span></div></td><td class="uk-text-right cockpit-click-count"><strong>'
				. number_format((int)$row['value']) . '</strong></td><td><time' . ($row['last_hit_at'] !== '' ? ' datetime="' . $this->e($row['last_hit_at']) . '" title="' . $this->e($row['last_hit_at']) . '"' : '') . '>' . $this->e($lastClick) . '</time>'
				. '</td><td><div class="cockpit-row-actions">' . $this->renderIconButton('a', $canManage ? $this->_('Manage link') : $this->_('View statistics'), $canManage ? 'edit' : 'statistics', $canManage ? './?view=links&link=' . $row['id'] : './?view=analytics&link_id=' . $row['id'])
				. '</div></td></tr>';
		}
		return $out . '</tbody></table></div></section>';
	}

	protected function renderQuickCreate(): string {
		$input = $this->wire('input');
		$isRetry = (string)$input->post->text('action') === 'save' && (string)$input->post->text('return_view') === 'overview';
		$path = $isRetry ? (string)$input->post->text('path') : '';
		$target = $isRetry ? trim((string)$input->post('target_url')) : '';
		$status = $isRetry ? (int)$input->post->int('redirect_status') : $this->cockpit->getDefaultRedirectStatus();
		if (!in_array($status, [301, 302, 307, 308], true)) $status = $this->cockpit->getDefaultRedirectStatus();
		$enabled = $isRetry ? (bool)$input->post->int('enabled') : $this->cockpit->newLinksEnabledByDefault();
		$createdId = (int)$input->get->int('created');
		$created = $createdId > 0 ? $this->cockpit->findLinkById($createdId) : null;
		$out = '<section class="cockpit-panel cockpit-quick-create"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">'
			. $this->_('Quick create') . '</p><h2>' . $this->_('Create a short link') . '</h2><p class="uk-text-meta">'
			. $this->_('Publish a validated redirect without leaving the Dashboard. Leave Path empty to generate one automatically.') . '</p></div></div>';
		if ($created) {
			$url = $this->cockpit->shortUrl((string)$created['path']);
			$out .= '<div class="cockpit-created cockpit-quick-created"><span><small class="uk-text-meta">' . $this->_('Link ready') . '</small><code>'
				. $this->e($url) . '</code></span><div class="cockpit-row-actions">'
				. $this->renderIconButton('button', $this->_('Copy short URL'), 'copy', '', 'data-copy="' . $this->e($url) . '"')
				. $this->renderIconButton('a', $this->_('Open short URL'), 'open', $url, 'target="_blank" rel="noopener"')
				. $this->renderIconButton('a', $this->_('Manage link'), 'edit', './?view=links&link=' . (int)$created['id'])
				. '</div></div>';
		}
		$out .= '<form method="post" action="./" class="cockpit-quick-create-form uk-form-stacked">'
			. $this->wire('session')->CSRF->renderInput() . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">'
			. '<input type="hidden" name="return_view" value="overview"><label><span class="uk-form-label">' . $this->_('Path') . '</span><input class="uk-input" type="text" name="path" maxlength="191" pattern="[A-Za-z0-9._~\/\-]+" value="'
			. $this->e($path) . '" placeholder="campaign/summer"></label><label class="cockpit-quick-target"><span class="uk-form-label">' . $this->_('Target URL')
			. '</span><input class="uk-input" type="url" name="target_url" required value="' . $this->e($target) . '" placeholder="https://example.com/campaign"></label>'
			. '<label><span class="uk-form-label">' . $this->_('Redirect') . '</span><select class="uk-select" name="redirect_status">';
		foreach ($this->redirectStatusOptions() as $value => $label) {
			$out .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
		}
		$out .= '</select></label><label class="cockpit-quick-enabled"><span class="uk-form-label">' . $this->_('Status') . '</span><span><input class="uk-checkbox" type="checkbox" name="enabled" value="1"'
			. ($enabled ? ' checked' : '') . '> ' . $this->_('Active') . '</span></label><button class="uk-button uk-button-primary" type="submit">'
			. $this->_('Create link') . '</button><p class="cockpit-field-help cockpit-quick-redirect-help"><strong>' . $this->_('Which redirect?') . '</strong> ' . $this->e($this->redirectStatusHelp()) . '</p></form></section>';
		return $out;
	}

	protected function renderLineChartCard(array $stats, string $title, string $description, array $previousStats = [], string $currentLabel = '', string $previousLabel = '', string $emptyActionUrl = '', string $emptyActionLabel = ''): string {
		$points = array_map(static function(array $row): array {
			return ['label' => (string)$row['bucket'], 'value' => (int)$row['clicks']];
		}, $stats);
		$out = '<section class="cockpit-panel cockpit-chart-card"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Traffic') . '</p><h2>'
			. $this->e($title) . '</h2><p class="uk-text-meta">' . $this->e($description) . '</p></div></div>';
		if (!$points || array_sum(array_column($points, 'value')) < 1) {
			return $out . $this->renderEmptyState(
				$this->_('No clicks in this period'),
				$emptyActionUrl !== '' ? $this->_('No clicks were recorded in the last 30 days. Open Analytics to inspect another date range.') : $this->_('Choose another period or share an active short URL to collect traffic.'),
				$emptyActionUrl,
				$emptyActionLabel,
				'bar-chart'
			) . '</section>';
		}
		$label = sprintf($this->_('%s. Interactive line chart.'), $title);
		$previousPoints = array_map(static function(array $row): array {
			return ['label' => (string)$row['bucket'], 'value' => (int)$row['clicks']];
		}, $previousStats);
		if ($previousPoints) {
			$out .= '<div class="cockpit-chart-series" aria-label="' . $this->e($this->_('Chart series')) . '"><span><i></i>' . $this->e($currentLabel ?: $this->_('Current period'))
				. '</span><span class="is-previous"><i></i>' . $this->e($previousLabel ?: $this->_('Previous period')) . '</span></div>';
		}
		return $out . '<div class="cockpit-canvas-wrap"><canvas class="cockpit-line-chart" role="img" aria-label="'
			. $this->e($label) . '" data-points="' . $this->e(json_encode($points)) . '" data-previous-points="' . $this->e(json_encode($previousPoints)) . '"></canvas>'
			. '<div class="cockpit-chart-tooltip" hidden></div></div></section>';
	}

	protected function renderDonutChartCard(array $points, string $title, string $description): string {
		$out = '<section class="cockpit-panel cockpit-chart-card"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Distribution') . '</p><h2>'
			. $this->e($title) . '</h2><p class="uk-text-meta">' . $this->e($description) . '</p></div></div>';
		if (!$points) return $out . $this->renderEmptyState(
			$this->_('No click activity yet'),
			$this->_('Campaign shares will appear after active links receive clicks.'),
			'',
			'',
			'pie-chart'
		) . '</section>';
		$label = sprintf($this->_('%s. Donut chart.'), $title);
		$total = array_sum(array_map(static function(array $point): int { return (int)$point['value']; }, $points));
		$out .= '<div class="cockpit-donut-layout"><div class="cockpit-canvas-wrap cockpit-donut-wrap"><canvas class="cockpit-donut-chart" role="img" aria-label="'
			. $this->e($label) . '" data-points="' . $this->e(json_encode($points)) . '"></canvas>'
			. '<div class="cockpit-chart-tooltip" hidden></div></div><ol class="cockpit-chart-legend">';
		foreach ($points as $index => $point) {
			$percentage = $total > 0 ? ((int)$point['value'] / $total) * 100 : 0;
			$out .= '<li><span class="cockpit-legend-swatch" data-color-index="' . $index . '"></span><span title="'
				. $this->e($point['label']) . '">' . $this->e($point['label']) . '</span><strong><span>'
				. number_format((int)$point['value']) . '</span><small>' . number_format($percentage, 1) . '%</small></strong></li>';
		}
		return $out . '</ol></div></section>';
	}

	protected function renderSummary(array $totals): string {
		$linkHealth = sprintf(
			$this->_('%d active · %d disabled'),
			(int)($totals['active_links'] ?? 0),
			(int)($totals['disabled_links'] ?? 0)
		);
		$cards = [
			[$this->_('Links'), (int)$totals['links'], $linkHealth, 'link'],
			[$this->_('Clicks today'), (int)$totals['today'], $this->_('Current site day'), 'today'],
			[$this->_('Last 7 days'), (int)$totals['seven_days'], $this->_('Rolling seven-day traffic'), 'week'],
			[$this->_('Last 30 days'), (int)$totals['thirty_days'], $this->_('Rolling thirty-day traffic'), 'month'],
			[$this->_('All clicks'), (int)$totals['total'], $this->_('Lifetime aggregate total'), 'total'],
		];
		$out = '<section class="cockpit-metric-grid cockpit-summary" aria-label="' . $this->e($this->_('Link metrics')) . '">';
		foreach ($cards as [$label, $value, $note, $icon]) {
			$out .= '<article class="cockpit-metric"><span class="cockpit-metric-icon">' . $this->metricIcon($icon) . '</span><div><span>'
				. $this->e($label) . '</span><strong>' . number_format($value) . '</strong><small>' . $this->e($note) . '</small></div></article>';
		}
		return $out . '</section>';
	}

	protected function renderEditor(?array $link): string {
		$id = (int)($link['id'] ?? 0);
		$path = (string)($link['path'] ?? '');
		$target = (string)($link['target_url'] ?? '');
		$status = (int)($link['redirect_status'] ?? $this->cockpit->getDefaultRedirectStatus());
		$enabled = $link === null ? $this->cockpit->newLinksEnabledByDefault() : !empty($link['enabled']);
		$out = '<section class="cockpit-panel cockpit-editor-section" id="editor"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Link editor') . '</p><h2>'
			. ($id ? $this->_('Edit short link') : $this->_('Create short link'))
			. '</h2><p class="uk-text-meta">' . $this->_('Enter any free relative path, or leave it empty to generate one automatically.') . '</p></div>'
			. '<div class="cockpit-actions">';
		if ($id) $out .= '<a class="uk-button uk-button-default" href="./?view=links">' . $this->_('Create another') . '</a>';
		$out .= '</div></div>' . $this->renderLinkForm($id, $path, $target, $status, $enabled);
		if ($id) {
			$url = $this->cockpit->shortUrl($path);
			try {
				$conflict = $this->cockpit->getPageConflict($path);
			} catch (\Throwable $exception) {
				$conflict = null;
			}
			if ($conflict) {
				$out .= '<div class="uk-alert uk-alert-warning" uk-alert><strong>' . $this->_('ProcessWire page has priority:') . '</strong> '
					. $this->e($conflict['path']) . '. ' . $this->_('This short redirect is not active while that page or URL-segment route exists.') . '</div>';
			}
			$out .= '<div class="cockpit-created"><code>' . $this->e($url) . '</code>'
				. '<button type="button" class="uk-button uk-button-default" data-copy="' . $this->e($url) . '">' . $this->_('Copy') . '</button>'
				. '<a class="uk-button uk-button-default" href="' . $this->e($url) . '" target="_blank" rel="noopener">' . $this->_('Open') . '</a></div>';
		}
		return $out . '</section>';
	}

	protected function renderLinkManagementPage(array $link, bool $canViewStats, bool $canViewAudit, bool $canDelete): string {
		$id = (int)$link['id'];
		$shortUrl = $this->cockpit->shortUrl((string)$link['path']);
		$enabled = !empty($link['enabled']);
		$out = $this->renderLinkSectionNav($canViewStats, $canViewAudit)
			. '<section class="cockpit-panel cockpit-link-overview" id="details"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">'
			. $this->_('Link details') . '</p><h2>/' . $this->e($link['path']) . '</h2><p class="uk-text-meta">'
			. $this->_('One canonical workspace for the public route and its destination.') . '</p></div><div class="cockpit-actions">';
		if ($canViewStats) {
			$out .= '<a class="uk-button uk-button-default uk-button-small" href="./?view=analytics&link_id=' . $id . '">' . $this->_('Full analytics') . '</a>';
		}
		if ($canDelete) {
			$out .= '<form method="post" data-confirm-delete>' . $this->wire('session')->CSRF->renderInput()
				. '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '">'
				. '<button class="uk-button uk-button-danger uk-button-small" type="submit">' . $this->_('Delete link') . '</button></form>';
		}
		$out .= '</div></div><dl class="cockpit-link-facts">'
			. $this->renderLinkFact($this->_('Short URL'), '<code>' . $this->e($shortUrl) . '</code>')
			. $this->renderLinkFact($this->_('Destination'), '<a href="' . $this->e($link['target_url']) . '" target="_blank" rel="noopener">' . $this->e($link['target_url']) . '</a>')
			. $this->renderLinkFact($this->_('Status'), '<span class="uk-label ' . ($enabled ? 'uk-label-success' : 'uk-label-warning') . '">' . ($enabled ? $this->_('Active') : $this->_('Disabled')) . '</span> <small>HTTP ' . (int)$link['redirect_status'] . '</small>')
			. $this->renderLinkFact($this->_('Created'), $this->formatAdminDateTime((string)($link['created_at'] ?? '')))
			. $this->renderLinkFact($this->_('Updated'), $this->formatAdminDateTime((string)($link['updated_at'] ?? '')))
			. $this->renderLinkFact($this->_('Last click'), $this->formatAdminDateTime((string)($link['last_hit_at'] ?? '')))
			. '</dl></section>';

		if ($canViewStats) {
			$daily = $this->cockpit->getStatistics('day', $id);
			$counts = array_map(static function(array $row): int { return (int)$row['clicks']; }, $daily);
			$today = $counts ? (int)end($counts) : 0;
			$sevenDays = array_sum(array_slice($counts, -7));
			$thirtyDays = array_sum($counts);
			$metrics = [
				[$this->_('Lifetime clicks'), (int)$link['hits'], $this->_('All recorded traffic'), 'total'],
				[$this->_('Today'), $today, $this->_('Current site day'), 'today'],
				[$this->_('Last 7 days'), $sevenDays, $this->_('Rolling seven-day traffic'), 'week'],
				[$this->_('Last 30 days'), $thirtyDays, $this->_('Rolling thirty-day traffic'), 'month'],
			];
			$out .= '<section class="cockpit-metric-grid cockpit-link-metrics" aria-label="' . $this->e($this->_('Link traffic metrics')) . '">';
			foreach ($metrics as [$label, $value, $note, $icon]) {
				$out .= '<article class="cockpit-metric"><span class="cockpit-metric-icon">' . $this->metricIcon($icon) . '</span><div><span>'
					. $this->e($label) . '</span><strong>' . number_format($value) . '</strong><small>' . $this->e($note) . '</small></div></article>';
			}
			$out .= '</section><div id="analytics" class="cockpit-link-traffic-stack">' . $this->renderLineChartCard(
				$daily,
				$this->_('Link traffic'),
				$this->_('Daily clicks for this short URL during the last 30 days.'),
				[],
				'',
				'',
				'./?view=analytics&link_id=' . $id,
				$this->_('Open full analytics')
			) . $this->renderPeriodDetail($daily, true) . '</div>';
		}

		$out .= $this->renderQrPanel($link, false);
		$out .= $this->renderEditor($link);
		if ($canViewAudit) $out .= $this->renderAuditLog($this->auditLog->findForLink($id, 50));
		return $out;
	}

	protected function renderLinkFact(string $label, string $value): string {
		return '<div class="cockpit-link-fact"><dt>' . $this->e($label) . '</dt><dd>' . $value . '</dd></div>';
	}

	protected function renderLinkSectionNav(bool $canViewStats, bool $canViewAudit): string {
		$items = ['details' => $this->_('Details')];
		if ($canViewStats) $items['analytics'] = $this->_('Traffic');
		$items['qr'] = $this->_('QR code');
		$items['editor'] = $this->_('Editor');
		if ($canViewAudit) $items['audit'] = $this->_('Audit');
		$out = '<nav class="cockpit-link-section-nav" aria-label="' . $this->e($this->_('Link workspace sections')) . '"><ul class="uk-subnav uk-subnav-pill">';
		foreach ($items as $anchor => $label) $out .= '<li><a href="#' . $anchor . '">' . $this->e($label) . '</a></li>';
		return $out . '</ul></nav>';
	}

	protected function formatAdminDateTime(string $value): string {
		if ($value === '') return '—';
		$timestamp = strtotime($value);
		if (!$timestamp) return $this->e($value);
		return '<time datetime="' . $this->e($value) . '" title="' . $this->e($value) . '">' . $this->e(date('M j, Y · H:i', $timestamp)) . '</time>';
	}

	/** Render the editor through ProcessWire's native Inputfield API. */
	protected function renderLinkForm(int $id, string $path, string $target, int $status, bool $enabled): string {
		$modules = $this->wire('modules');
		$form = $modules->get('InputfieldForm');
		$form->attr('method', 'post');
		$form->attr('action', './?view=links' . ($id ? '&link=' . $id : ''));
		$form->addClass('cockpit-editor');

		foreach (['action' => 'save', 'id' => $id] as $name => $value) {
			$field = $modules->get('InputfieldHidden');
			$field->attr('name', $name);
			$field->attr('value', $value);
			$form->add($field);
		}

		$field = $modules->get('InputfieldText');
		$field->attr('name', 'path');
		$field->attr('value', $path);
		$field->attr('maxlength', 191);
		$field->attr('pattern', '[A-Za-z0-9._~\/\-]+');
		$field->attr('placeholder', 'campaign/summer');
		$field->label = $this->_('Path');
		$field->description = $this->_('Relative to the site root. Leave empty to generate a path automatically.');
		$field->notes = $this->_('Example: campaign/summer');
		$field->icon = 'link';
		$field->columnWidth = 23;
		$form->add($field);

		$field = $modules->get('InputfieldURL');
		$field->attr('name', 'target_url');
		$field->attr('value', $target);
		$field->attr('placeholder', 'https://example.com/campaign');
		$field->label = $this->_('Target URL');
		$field->description = $this->_('Public HTTP(S) destination allowed by the configured host policy.');
		$field->icon = 'external-link';
		$field->required = true;
		$field->columnWidth = 39;
		$form->add($field);

		$field = $modules->get('InputfieldSelect');
		$field->attr('name', 'redirect_status');
		$field->attr('value', $status);
		$field->label = $this->_('Redirect');
		$field->icon = 'exchange';
		$field->addOptions($this->redirectStatusOptions());
		$field->notes = $this->_('Most links: 302. Final destination: 301. Forms/APIs: 307 or 308.');
		$field->columnWidth = 24;
		$form->add($field);

		$field = $modules->get('InputfieldCheckbox');
		$field->attr('name', 'enabled');
		$field->attr('value', 1);
		$field->attr('checked', $enabled ? 'checked' : '');
		$field->label = $this->_('Status');
		$field->checkboxLabel = $this->_('Active');
		$field->icon = 'toggle-on';
		$field->columnWidth = 14;
		$form->add($field);

		$this->addUtmBuilderFields($form, $modules, $target);

		$field = $modules->get('InputfieldSubmit');
		$field->attr('name', 'submit_link');
		$field->attr('value', $id ? $this->_('Save changes') : $this->_('Create link'));
		$field->icon = $id ? 'save' : 'plus';
		$field->columnWidth = 100;
		$form->add($field);

		return $form->render();
	}

	/** Human-readable redirect choices shared by the full and quick editors. */
	protected function redirectStatusOptions(): array {
		return [
			302 => $this->_('302 — Recommended'),
			301 => $this->_('301 — Final destination'),
			307 => $this->_('307 — Forms/API, temporary'),
			308 => $this->_('308 — Forms/API, permanent'),
		];
	}

	protected function redirectStatusHelp(): string {
		return $this->_('Choose 302 for most links—you can change the destination later. Choose 301 only if it will never change. Options 307 and 308 are for forms and APIs.');
	}

	/** Add an optional client-side Campaign URL Builder to the canonical target form. */
	protected function addUtmBuilderFields($form, $modules, string $target): void {
		$fieldset = $modules->get('InputfieldFieldset');
		$fieldset->attr('name', 'utm_builder');
		$fieldset->label = $this->_('Campaign URL Builder');
		$fieldset->description = $this->_('Build a Google Analytics campaign destination, preview it, then apply it to Target URL. Existing non-UTM query parameters and fragments are preserved.');
		$fieldset->icon = 'bullseye';
		$fieldset->collapsed = preg_match('/(?:[?&])utm_[a-z_]+=/i', $target) ? Inputfield::collapsedNo : Inputfield::collapsedYes;
		$form->add($fieldset);

		$field = $modules->get('InputfieldURL');
		$field->attr('name', 'utm_website_url');
		$field->attr('maxlength', 2048);
		$field->attr('placeholder', 'https://example.com/landing-page');
		$field->label = $this->_('Website URL');
		$field->description = $this->_('The destination before campaign parameters are added.');
		$field->icon = 'globe';
		$field->columnWidth = 100;
		$fieldset->add($field);

		$definitions = [
			['utm_source', $this->_('Campaign source'), $this->_('Required when using UTM tags. Example: newsletter, google, instagram.'), 'newsletter', 33],
			['utm_medium', $this->_('Campaign medium'), $this->_('Required when using UTM tags. Example: email, cpc, social.'), 'email', 33],
			['utm_campaign', $this->_('Campaign name'), $this->_('Required when using UTM tags. Use one consistent campaign name.'), 'summer-sale', 34],
			['utm_id', $this->_('Campaign ID'), $this->_('Optional GA4 campaign or promotion identifier.'), '2026-summer-01', 33],
			['utm_source_platform', $this->_('Source platform'), $this->_('Optional platform responsible for the traffic.'), 'meta', 33],
			['utm_term', $this->_('Campaign term'), $this->_('Optional paid-search keyword.'), 'wine-delivery', 34],
			['utm_content', $this->_('Campaign content'), $this->_('Optional creative, placement, headline, or call-to-action identifier.'), 'hero-button', 100],
		];
		foreach ($definitions as [$name, $label, $description, $placeholder, $width]) {
			$field = $modules->get('InputfieldText');
			$field->attr('name', $name);
			$field->attr('maxlength', 200);
			$field->attr('placeholder', $placeholder);
			$field->label = $label;
			$field->description = $description;
			$field->columnWidth = $width;
			$fieldset->add($field);
		}

		$output = $modules->get('InputfieldMarkup');
		$output->attr('name', 'utm_output');
		$output->label = $this->_('Generated campaign URL');
		$output->value = '<div class="cockpit-utm-output"><code class="cockpit-utm-preview" data-utm-preview>—</code>'
			. '<p class="cockpit-utm-message uk-text-meta" data-utm-message aria-live="polite"></p>'
			. '<div class="cockpit-utm-actions">'
			. '<button class="uk-button uk-button-primary" type="button" data-utm-apply>' . $this->_('Apply to Target URL') . '</button>'
			. '<button class="uk-button uk-button-default" type="button" data-utm-copy disabled>' . $this->_('Copy URL') . '</button>'
			. '<button class="uk-button uk-button-text" type="button" data-utm-clear>' . $this->_('Clear UTM fields') . '</button>'
			. '</div></div>';
		$fieldset->add($output);
	}

	protected function renderQrPanel(array $link, bool $showClose = true): string {
		$provider = $this->cockpit->getCodeProvider('fieldtype-qrcode');
		$status = $this->cockpit->getCodeProviderStatus()['fieldtype-qrcode'] ?? ['available' => false];
		$settingsUrl = rtrim((string)$this->wire('config')->urls->admin, '/') . '/module/edit?name=FieldtypeQRCode&collapse_info=1';
		$out = '<section class="cockpit-panel cockpit-qr-panel" id="qr"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Code') . '</p><h2>'
			. $this->_('QR code') . '</h2><p class="uk-text-meta">' . $this->_('Generated from the canonical short URL; changing the destination does not change the printed code.')
			. '</p></div>' . ($showClose ? '<div class="cockpit-actions"><a class="uk-button uk-button-default" href="./?view=links">' . $this->_('Close') . '</a></div>' : '') . '</div>';

		if (!$provider || empty($status['available'])) {
			return $out . '<div class="uk-alert uk-alert-warning" uk-alert><p>'
				. $this->_('A compatible FieldtypeQRCode provider is not available. Cockpit links and analytics are unaffected.')
				. '</p><a class="uk-button uk-button-default" href="' . $this->e($settingsUrl) . '">' . $this->_('Open provider settings') . '</a></div></section>';
		}

		$shortUrl = $this->cockpit->shortUrl((string)$link['path']);
		try {
			$result = $provider->generate($shortUrl, ['format' => 'svg', 'recoveryLevel' => 'M', 'size' => 6]);
		} catch (\Throwable $exception) {
			$this->wire('log')->save('cockpit', 'FieldtypeQRCode generation failed for link ID ' . (int)$link['id'] . '.');
			return $out . '<div class="uk-alert uk-alert-danger" uk-alert><p>'
				. $this->_('FieldtypeQRCode could not generate this QR image. The error was logged without the destination or request data.')
				. '</p></div></section>';
		}

		$dataUri = 'data:' . $result->mimeType() . ';base64,' . base64_encode($result->content());
		$fileBase = trim((string)preg_replace('/[^a-z0-9._-]+/i', '-', (string)$link['path']), '-');
		if ($fileBase === '') $fileBase = 'cockpit-link';
		$version = (string)($status['provider_version_label'] ?? $status['provider_version'] ?? '');
		$out .= '<div class="cockpit-qr-layout"><div class="cockpit-qr-preview"><img src="' . $this->e($dataUri) . '" alt="'
			. $this->e(sprintf($this->_('QR code for %s'), $shortUrl)) . '"></div><div><h3 class="uk-h4">/' . $this->e($link['path'])
			. '</h3><p><code>' . $this->e($shortUrl) . '</code></p>';
		if (empty($link['enabled'])) {
			$out .= '<div class="uk-alert uk-alert-warning" uk-alert><p>' . $this->_('This link is disabled. The QR image is valid, but the short URL currently returns 404.') . '</p></div>';
		}
		$out .= '<div class="cockpit-actions uk-flex-left"><a class="uk-button uk-button-primary" href="' . $this->e($dataUri)
			. '" download="' . $this->e($fileBase . '.svg') . '">' . $this->_('Download SVG') . '</a><a class="uk-button uk-button-default" href="'
			. $this->e($shortUrl) . '" target="_blank" rel="noopener">' . $this->_('Open short URL') . '</a></div><p class="uk-text-meta">'
			. $this->e(sprintf($this->_('Generated by FieldtypeQRCode %s by EPRC / Romain Cazier (MIT).'), $version !== '' ? $version : $this->_('compatible')))
			. ' <a href="https://github.com/eprcstudio/FieldtypeQRCode" target="_blank" rel="noopener">' . $this->_('Provider repository') . '</a></p></div></div></section>';
		return $out;
	}

	protected function renderLinksTable(array $state, bool $canDelete, bool $canViewStats): string {
		$links = $state['links'];
		$filters = $state['filters'];
		$qrStatus = $this->cockpit->getCodeProviderStatus()['fieldtype-qrcode'] ?? ['available' => false];
		$canGenerateQr = !empty($qrStatus['available']);
		$out = '<section class="cockpit-panel cockpit-links-section"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Inventory')
			. '</p><div class="cockpit-inventory-title"><h2>' . $this->_('Links') . '</h2><span class="uk-text-meta">'
			. $this->e(sprintf($this->_('%d links'), (int)$state['total'])) . '</span></div><p class="uk-text-meta">'
			. $this->_('Destinations can be changed without changing the public short URL.') . '</p></div><a class="uk-button uk-button-primary uk-button-small" href="#editor">' . $this->_('Create link') . '</a></div>'
			. $this->renderLinkFilters($filters);
		if (!$links) return $out . $this->renderEmptyState(
			$this->_('No matching links'),
			$this->_('Clear the filters or create a new short link.'),
			'#editor',
			$this->_('Create link'),
			'filter'
		) . '</section>';

		$csrf = $this->wire('session')->CSRF;
		$out .= '<div class="cockpit-table-panel pw-table-panel uk-overflow-auto"><table class="AdminDataTable uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small"><thead><tr>'
			. '<th>' . $this->_('Short URL') . '</th><th>' . $this->_('Target') . '</th><th>' . $this->_('Status') . '</th>'
			. '<th>' . $this->_('Clicks') . '</th><th>' . $this->_('Last click') . '</th><th><span class="cockpit-visually-hidden">' . $this->_('Actions') . '</span></th></tr></thead><tbody>';
		foreach ($links as $link) {
			$id = (int)$link['id'];
			$shortUrl = $this->cockpit->shortUrl((string)$link['path']);
			$lastClick = $link['last_hit_at'] ? date('M j, Y · H:i', strtotime((string)$link['last_hit_at'])) : '—';
			$out .= '<tr><td class="cockpit-short-url-cell"><div class="cockpit-short-url"><a href="./?view=links&amp;link=' . $id . '"><code>/'
				. $this->e($link['path']) . '</code></a>'
				. $this->renderIconButton('button', $this->_('Copy short URL'), 'copy', '', 'data-copy="' . $this->e($shortUrl) . '" data-copy-success="' . $this->e($this->_('Short URL copied')) . '"') . '</div></td>'
				. '<td class="cockpit-target-cell"><a href="' . $this->e($link['target_url']) . '" target="_blank" rel="noopener" title="' . $this->e($link['target_url']) . '">'
				. $this->e($link['target_url']) . '</a></td>'
				. '<td><div class="cockpit-status-cell"><small class="cockpit-status-code">' . (int)$link['redirect_status'] . '</small><span class="uk-label ' . (!empty($link['enabled']) ? 'uk-label-success' : 'uk-label-warning') . '">'
				. (!empty($link['enabled']) ? $this->_('Active') : $this->_('Disabled')) . '</span></div></td>'
				. '<td class="cockpit-click-count uk-text-right"><strong>' . number_format((int)$link['hits']) . '</strong></td><td><time' . ($link['last_hit_at'] ? ' datetime="' . $this->e($link['last_hit_at']) . '" title="' . $this->e($link['last_hit_at']) . '"' : '') . '>'
				. $this->e($lastClick) . '</time></td><td><div class="cockpit-row-actions">'
					. $this->renderIconButton('a', $this->_('Manage link'), 'edit', './?view=links&link=' . $id . '#editor');
			if ($canViewStats) {
				$out .= $this->renderIconButton('a', $this->_('View statistics'), 'statistics', './?view=links&link=' . $id . '#analytics');
			}
			if ($canGenerateQr) {
				$out .= $this->renderIconButton('a', $this->_('Open QR code'), 'qr', './?view=links&link=' . $id . '#qr');
			}
			if ($canDelete) {
				$out .= '<form method="post" data-confirm-delete>' . $csrf->renderInput() . '<input type="hidden" name="action" value="delete">'
					. '<input type="hidden" name="id" value="' . $id . '">' . $this->renderIconButton('button', $this->_('Delete link'), 'delete', '', '', true) . '</form>';
			}
			$out .= '</div></td></tr>';
		}
		return $out . '</tbody></table></div>' . $this->renderLinkPagination($filters, (int)$state['page'], (int)$state['pages']) . '</section>';
	}

	protected function renderIconButton(string $element, string $label, string $icon, string $href = '', string $attributes = '', bool $danger = false): string {
		$class = 'cockpit-icon-button' . ($danger ? ' cockpit-icon-button-danger' : '');
		$common = ' class="' . $class . '" title="' . $this->e($label) . '" aria-label="' . $this->e($label) . '"';
		if ($attributes !== '') $common .= ' ' . $attributes;
		$content = $this->actionIcon($icon) . '<span class="cockpit-visually-hidden">' . $this->e($label) . '</span>';
		if ($element === 'a') return '<a' . $common . ' href="' . $this->e($href) . '">' . $content . '</a>';
		return '<button' . $common . ' type="' . ($danger ? 'submit' : 'button') . '">' . $content . '</button>';
	}

	protected function actionIcon(string $name): string {
		$paths = [
			'copy' => '<rect x="8" y="8" width="10" height="10" rx="1"/><path d="M6 14H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v1"/>',
			'edit' => '<path d="M4 16.5V20h3.5L18.7 8.8l-3.5-3.5L4 16.5Z"/><path d="m13.8 6.7 3.5 3.5"/>',
			'statistics' => '<path d="M4 19V10M10 19V5M16 19v-7M2 19h18"/>',
			'qr' => '<rect x="3" y="3" width="6" height="6"/><rect x="15" y="3" width="6" height="6"/><rect x="3" y="15" width="6" height="6"/><path d="M15 15h2v2h-2zM19 15h2v6h-2M15 19h2v2h-2"/>',
			'open' => '<path d="M14 4h6v6M20 4 11 13"/><path d="M18 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h6"/>',
			'delete' => '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/>',
		];
		return '<svg class="cockpit-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
			. ($paths[$name] ?? $paths['edit']) . '</svg>';
	}

	protected function linkListState(): array {
		$query = substr(trim((string)$this->wire('input')->get->text('q')), 0, 200);
		$state = (string)$this->wire('input')->get->text('state');
		if (!in_array($state, ['', 'enabled', 'disabled'], true)) $state = '';
		$status = (int)$this->wire('input')->get->int('status');
		if (!in_array($status, [301, 302, 307, 308], true)) $status = 0;
		$activeOnly = (bool)$this->wire('input')->get->int('active_only');
		$filters = ['query' => $query, 'redirect_status' => $status, 'active_only' => $activeOnly];
		if ($state !== '') $filters['enabled'] = $state === 'enabled' ? 1 : 0;
		$total = $this->cockpit->countLinks($filters);
		$perPage = 50;
		$pages = max(1, (int)ceil($total / $perPage));
		$page = max(1, min($pages, (int)$this->wire('input')->get->int('p') ?: 1));
		$queryFilters = $filters;
		$queryFilters['limit'] = $perPage;
		$queryFilters['offset'] = ($page - 1) * $perPage;
		return [
			'links' => $this->cockpit->findLinks($queryFilters),
			'filters' => ['q' => $query, 'state' => $state, 'status' => $status, 'active_only' => $activeOnly ? 1 : 0],
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
		];
	}

	protected function renderLinkFilters(array $filters): string {
		$out = '<form method="get" action="./" class="cockpit-filter-panel cockpit-stat-filter cockpit-link-filter pw-filter-panel pw-filter-row uk-form-stacked uk-margin"><input type="hidden" name="view" value="links"><label><span class="uk-form-label">' . $this->_('Search')
			. '</span><input class="uk-input" type="search" name="q" maxlength="200" value="' . $this->e($filters['q'])
			. '" placeholder="' . $this->e($this->_('Path or target')) . '"></label><label><span class="uk-form-label">' . $this->_('State')
			. '</span><select class="uk-select" name="state">';
		foreach (['' => $this->_('All'), 'enabled' => $this->_('Active'), 'disabled' => $this->_('Disabled')] as $value => $label) {
			$out .= '<option value="' . $this->e($value) . '"' . ($filters['state'] === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
		}
		$out .= '</select></label><label><span class="uk-form-label">' . $this->_('HTTP status') . '</span><select class="uk-select" name="status"><option value="0">'
			. $this->_('All') . '</option>';
		foreach ([301, 302, 307, 308] as $status) {
			$out .= '<option value="' . $status . '"' . ((int)$filters['status'] === $status ? ' selected' : '') . '>' . $status . '</option>';
		}
		$out .= '</select></label><label class="cockpit-enabled"><span class="uk-form-label">' . $this->_('Activity') . '</span><span class="cockpit-checkbox">'
			. '<input class="uk-checkbox" type="checkbox" name="active_only" value="1"' . (!empty($filters['active_only']) ? ' checked' : '') . '> '
			. $this->_('Clicked') . '</span></label><button class="uk-button uk-button-default" type="submit">' . $this->_('Filter') . '</button>'
			. '<a class="uk-button uk-button-default" href="./?view=links">' . $this->_('Clear') . '</a></form>';
		return $out;
	}

	protected function renderLinkPagination(array $filters, int $page, int $pages): string {
		if ($pages < 2) return '';
		$out = '<nav aria-label="' . $this->e($this->_('Link pages')) . '"><ul class="uk-pagination uk-flex-center" uk-margin>';
		$numbers = [1, $pages];
		for ($number = max(1, $page - 3); $number <= min($pages, $page + 3); $number++) $numbers[] = $number;
		$numbers = array_values(array_unique($numbers));
		sort($numbers);
		$previous = 0;
		foreach ($numbers as $number) {
			if ($previous > 0 && $number > $previous + 1) $out .= '<li class="uk-disabled"><span aria-hidden="true">…</span></li>';
			$params = array_filter([
				'view' => 'links',
				'q' => $filters['q'],
				'state' => $filters['state'],
				'status' => $filters['status'] ?: null,
				'active_only' => $filters['active_only'] ?: null,
				'p' => $number > 1 ? $number : null,
			], static function($value): bool { return $value !== null && $value !== ''; });
			$url = './' . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
			$out .= '<li' . ($number === $page ? ' class="uk-active"' : '') . '><a href="' . $this->e($url) . '"'
				. ($number === $page ? ' aria-current="page"' : '') . '>' . $number . '</a></li>';
			$previous = $number;
		}
		return $out . '</ul></nav>';
	}

	protected function renderStatistics(array $links, array $analytics): string {
		$filters = $analytics['filters'];
		$stats = $analytics['buckets'];
		$summary = $analytics['summary'];
		$presets = [
			'today' => $this->_('Today'),
			'7d' => $this->_('7 days'),
			'30d' => $this->_('30 days'),
			'90d' => $this->_('90 days'),
			'12m' => $this->_('12 months'),
		];
		$groupLabels = [
			'auto' => $this->_('Automatic'),
			'day' => $this->_('By day'),
			'week' => $this->_('By week'),
			'month' => $this->_('By month'),
		];
		$baseParams = [
			'view' => 'analytics',
			'link_id' => (int)$filters['link_id'] ?: null,
			'state' => $filters['state'] !== 'all' ? $filters['state'] : null,
			'status' => (int)$filters['status'] ?: null,
			'group' => $filters['group'] !== 'auto' ? $filters['group'] : null,
		];

		$out = '<section class="cockpit-panel cockpit-analytics-filter"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Explore') . '</p><h2>'
			. $this->_('Traffic filters') . '</h2><p class="uk-text-meta">' . $this->_('Compare a quick period or choose an exact date range. All filters also apply to the previous-period comparison.')
			. '</p></div><a class="uk-button uk-button-default uk-button-small" href="./?view=analytics">' . $this->_('Reset') . '</a></div><nav aria-label="'
			. $this->e($this->_('Quick periods')) . '"><ul class="uk-subnav uk-subnav-pill cockpit-period-presets">';
		foreach ($presets as $value => $label) {
			$params = array_filter(array_merge($baseParams, ['preset' => $value]), static function($item): bool { return $item !== null && $item !== ''; });
			$out .= '<li' . ($filters['preset'] === $value ? ' class="uk-active"' : '') . '><a href="./?'
				. $this->e(http_build_query($params, '', '&', PHP_QUERY_RFC3986)) . '">' . $this->e($label) . '</a></li>';
		}
		$out .= '</ul></nav><form method="get" action="./" class="cockpit-filter-panel cockpit-stat-filter pw-filter-panel pw-filter-row uk-form-stacked"><input type="hidden" name="view" value="analytics"><input type="hidden" name="preset" value="custom"><label><span class="uk-form-label">'
			. $this->_('From') . '</span><input class="uk-input" type="date" name="date_from" value="' . $this->e($filters['date_from'])
			. '" max="' . $this->e($filters['date_to']) . '"></label><label><span class="uk-form-label">' . $this->_('To')
			. '</span><input class="uk-input" type="date" name="date_to" value="' . $this->e($filters['date_to'])
			. '" max="' . date('Y-m-d') . '"></label><label class="cockpit-filter-link"><span class="uk-form-label">'
			. $this->_('Link') . '</span><select class="uk-select" name="link_id"><option value="0">' . $this->_('All links') . '</option>';
		foreach ($links as $link) {
			$id = (int)$link['id'];
			$out .= '<option value="' . $id . '"' . ((int)$filters['link_id'] === $id ? ' selected' : '') . '>/' . $this->e($link['path']) . '</option>';
		}
		$out .= '</select></label><label><span class="uk-form-label">' . $this->_('State') . '</span><select class="uk-select" name="state">';
		foreach (['all' => $this->_('All states'), 'active' => $this->_('Active'), 'disabled' => $this->_('Disabled')] as $value => $label) {
			$out .= '<option value="' . $value . '"' . ($filters['state'] === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
		}
		$out .= '</select></label><label><span class="uk-form-label">' . $this->_('HTTP status') . '</span><select class="uk-select" name="status"><option value="0">' . $this->_('All') . '</option>';
		foreach ([301, 302, 307, 308] as $status) {
			$out .= '<option value="' . $status . '"' . ((int)$filters['status'] === $status ? ' selected' : '') . '>' . $status . '</option>';
		}
		$out .= '</select></label><label><span class="uk-form-label">' . $this->_('Grouping') . '</span><select class="uk-select" name="group">';
		foreach ($groupLabels as $value => $label) {
			$out .= '<option value="' . $value . '"' . ($filters['group'] === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
		}
		$out .= '</select></label><button class="uk-button uk-button-primary" type="submit">' . $this->_('Apply filters') . '</button></form>';
		$selectedLink = $this->_('All links');
		foreach ($links as $link) {
			if ((int)$filters['link_id'] === (int)$link['id']) {
				$selectedLink = '/' . (string)$link['path'];
				break;
			}
		}
		$statusScope = (int)$filters['status'] ? 'HTTP ' . (int)$filters['status'] : $this->_('All HTTP statuses');
		$out .= '<div class="cockpit-filter-summary" aria-label="' . $this->e($this->_('Applied filters')) . '">'
			. '<span>' . $this->e($this->formatDateRange($filters['date_from'], $filters['date_to'])) . '</span>'
			. '<span title="' . $this->e($selectedLink) . '">' . $this->e($selectedLink) . '</span>'
			. '<span>' . $this->e($filters['state'] === 'all' ? $this->_('All states') : ucfirst($filters['state'])) . '</span>'
			. '<span>' . $this->e($statusScope) . '</span><span>' . $this->e($groupLabels[$filters['resolved_group']]) . '</span></div></section>';

		$change = $summary['change_percent'];
		$changeText = $change === null ? $this->_('No previous traffic') : (($change > 0 ? '+' : '') . number_format((float)$change, 1) . '%');
		$currentRange = $this->formatDateRange($filters['date_from'], $filters['date_to']);
		$previousRange = $this->formatDateRange($summary['previous_date_from'], $summary['previous_date_to']);
		[, , $peakLabel] = $this->periodBucketLabel((string)$summary['peak_bucket']);
		$summaryCards = [
			[$this->_('Clicks'), number_format((int)$summary['clicks']), $currentRange],
			[$this->_('Daily average'), number_format((float)$summary['average_per_day'], 1), $this->_('Clicks per day')],
			[$this->_('Previous period'), number_format((int)$summary['previous_clicks']), $previousRange . ' · ' . $changeText],
			[$this->_('Peak period'), number_format((int)$summary['peak_clicks']), $peakLabel],
		];
		$out .= '<section class="cockpit-metric-grid cockpit-analytics-summary" aria-label="' . $this->e($this->_('Selected period metrics')) . '">';
		foreach ($summaryCards as $index => [$label, $value, $meta]) {
			$icons = ['total', 'today', 'compare', 'peak'];
			$out .= '<article class="cockpit-metric"><span class="cockpit-metric-icon">' . $this->metricIcon($icons[$index]) . '</span><div><span>'
				. $this->e($label) . '</span><strong>' . $this->e($value) . '</strong><small>' . $this->e($meta) . '</small></div></article>';
		}
		$out .= '</section><div class="cockpit-insight-grid cockpit-analytics-grid">'
			. $this->renderLineChartCard($stats, $this->_('Clicks over time'), sprintf($this->_('%s · grouped %s.'), $currentRange, $groupLabels[$filters['resolved_group']]), $analytics['previous_buckets'], $currentRange, $previousRange)
			. $this->renderDonutChartCard($analytics['shares'], $this->_('Traffic share'), $this->_('Top links for the selected filters.'))
			. '</div>';

		return $out . $this->renderPeriodDetail($stats, true);
	}

	protected function renderPeriodDetail(array $stats, bool $newestFirst = true): string {
		if (!$stats) return '';
		$displayStats = $newestFirst ? array_reverse($stats) : $stats;
		$actualMax = max(array_map(static function(array $row): int { return (int)$row['clicks']; }, $displayStats));
		$scaleMax = max(1, $actualMax);
		$peakBucket = '';
		if ($actualMax > 0) {
			foreach ($displayStats as $row) {
				if ((int)$row['clicks'] === $actualMax) {
					$peakBucket = (string)$row['bucket'];
					break;
				}
			}
		}
		$latestRow = end($stats);
		$latestBucket = (string)($latestRow['bucket'] ?? '');
		if ($actualMax > 0) {
			[, , $peakDate] = $this->periodBucketLabel($peakBucket);
			$description = sprintf($this->_('%d periods · Peak %s clicks on %s. Newest periods are shown first.'), count($displayStats), number_format($actualMax), $peakDate);
		} else {
			$description = sprintf($this->_('%d periods · No clicks in this range. Newest periods are shown first.'), count($displayStats));
		}
		$out = '<details class="cockpit-panel cockpit-period-card"><summary class="cockpit-period-summary"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Timeline') . '</p><h2>'
			. $this->_('Period detail') . '</h2><p class="uk-text-meta">' . $this->e($description)
			. '</p></div><span class="cockpit-period-toggle"><span class="is-closed">' . $this->_('Show periods') . '</span><span class="is-open">' . $this->_('Hide periods') . '</span><i aria-hidden="true"></i></span></summary><div class="cockpit-period-strip" role="list" aria-label="' . $this->e($this->_('Clicks by period, newest first')) . '">';
		foreach ($displayStats as $row) {
			$count = (int)$row['clicks'];
			$bucket = (string)$row['bucket'];
			[$primaryDate, $secondaryDate, $accessibleDate] = $this->periodBucketLabel($bucket);
			$isPeak = $peakBucket !== '' && $bucket === $peakBucket;
			$isLatest = $bucket === $latestBucket;
			$classes = 'cockpit-period-item' . ($isPeak ? ' is-peak' : '') . ($isLatest ? ' is-latest' : '');
			$out .= '<article class="' . $classes . '" role="listitem"><header><time datetime="' . $this->e($bucket) . '" aria-label="' . $this->e($accessibleDate) . '"><strong>'
				. $this->e($primaryDate) . '</strong><small>' . $this->e($secondaryDate) . '</small></time><span class="cockpit-period-flags">'
				. ($isPeak ? '<span class="uk-label">' . $this->_('Peak') . '</span>' : '')
				. ($isLatest ? '<span class="uk-label cockpit-period-latest">' . $this->_('Latest') . '</span>' : '')
				. '</span></header><div class="cockpit-period-value"><strong>' . number_format($count) . '</strong><span class="uk-text-meta">' . $this->_('clicks')
				. '</span></div><progress class="uk-progress" value="' . $count . '" max="' . $scaleMax . '" aria-label="'
				. $this->e(sprintf($this->_('%s: %s clicks'), $accessibleDate, number_format($count))) . '"></progress></article>';
		}
		return $out . '</div></details>';
	}

	/** Format daily, weekly, and monthly analytics buckets for fast scanning. */
	protected function periodBucketLabel(string $bucket): array {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bucket)) {
			$timestamp = strtotime($bucket . ' 12:00:00');
			return [date('M j', $timestamp), date('D', $timestamp), date('F j, Y', $timestamp)];
		}
		if (preg_match('/^(\d{4})-W(\d{2})$/', $bucket, $matches)) {
			return [sprintf($this->_('Week %d'), (int)$matches[2]), $matches[1], sprintf($this->_('Week %d, %s'), (int)$matches[2], $matches[1])];
		}
		if (preg_match('/^\d{4}-\d{2}$/', $bucket)) {
			$timestamp = strtotime($bucket . '-01 12:00:00');
			return [date('M', $timestamp), date('Y', $timestamp), date('F Y', $timestamp)];
		}
		return [$bucket, $this->_('Period'), $bucket];
	}

	protected function formatDateRange(string $from, string $to): string {
		$fromTime = strtotime($from . ' 12:00:00');
		$toTime = strtotime($to . ' 12:00:00');
		if (!$fromTime || !$toTime) return $from . ' – ' . $to;
		if ($from === $to) return date('M j, Y', $fromTime);
		if (date('Y', $fromTime) === date('Y', $toTime)) {
			return date('M j', $fromTime) . ' – ' . date('M j, Y', $toTime);
		}
		return date('M j, Y', $fromTime) . ' – ' . date('M j, Y', $toTime);
	}

	protected function auditListState(): array {
		$input = $this->wire('input');
		$query = substr(trim((string)$input->get->text('q')), 0, 191);
		$action = strtolower((string)$input->get->text('action_type'));
		if (!in_array($action, ['', 'create', 'update', 'enable', 'disable', 'delete'], true)) $action = '';
		$actorId = (int)$input->get->int('actor_id');
		if ($actorId < -1) $actorId = 0;
		$dateFrom = (string)$input->get->text('date_from');
		$dateTo = (string)$input->get->text('date_to');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';
		if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
		$filters = ['query' => $query, 'action' => $action, 'actor_id' => $actorId, 'date_from' => $dateFrom, 'date_to' => $dateTo];
		$total = $this->auditLog->countRecent($filters);
		$perPage = 50;
		$pages = max(1, (int)ceil($total / $perPage));
		$page = max(1, min($pages, (int)$input->get->int('p') ?: 1));
		$countFilters = $filters;
		$countFilters['action'] = '';
		$counts = [];
		foreach (['create', 'update', 'enable', 'disable', 'delete'] as $type) {
			$countFilters['action'] = $type;
			$counts[$type] = $this->auditLog->countRecent($countFilters);
		}
		return [
			'rows' => $this->auditLog->findRecent($perPage, ($page - 1) * $perPage, $filters),
			'filters' => $filters,
			'total' => $total,
			'counts' => $counts,
			'actor_ids' => $this->auditLog->findActorIds(),
			'page' => $page,
			'pages' => $pages,
		];
	}

	protected function renderAuditSummary(array $counts): string {
		$labels = ['create' => $this->_('Created'), 'update' => $this->_('Updated'), 'enable' => $this->_('Enabled'), 'disable' => $this->_('Disabled'), 'delete' => $this->_('Deleted')];
		$out = '<div class="cockpit-audit-summary" aria-label="' . $this->e($this->_('Event totals')) . '">';
		foreach ($labels as $action => $label) {
			$out .= '<div><span class="' . $this->auditActionBadgeClass($action) . '">' . $this->e($label) . '</span><strong>' . number_format((int)($counts[$action] ?? 0)) . '</strong></div>';
		}
		return $out . '</div>';
	}

	protected function auditActionBadgeClass(string $action): string {
		$action = in_array($action, ['create', 'update', 'enable', 'disable', 'delete'], true) ? $action : 'update';
		$modifiers = ['create' => 'uk-label-success', 'enable' => 'uk-label-success', 'disable' => 'uk-label-warning', 'delete' => 'uk-label-danger'];
		return 'uk-label' . (isset($modifiers[$action]) ? ' ' . $modifiers[$action] : '');
	}

	protected function renderAuditFilters(array $state): string {
		$filters = $state['filters'];
		$out = '<form method="get" action="./" class="cockpit-filter-panel cockpit-audit-filter pw-filter-panel pw-filter-row uk-form-stacked"><input type="hidden" name="view" value="audit"><label><span class="uk-form-label">'
			. $this->_('Search path') . '</span><input class="uk-input" type="search" name="q" value="' . $this->e($filters['query']) . '" placeholder="campaign/summer"></label><label><span class="uk-form-label">'
			. $this->_('Action') . '</span><select class="uk-select" name="action_type"><option value="">' . $this->_('All actions') . '</option>';
		foreach (['create' => $this->_('Created'), 'update' => $this->_('Updated'), 'enable' => $this->_('Enabled'), 'disable' => $this->_('Disabled'), 'delete' => $this->_('Deleted')] as $value => $label) {
			$out .= '<option value="' . $value . '"' . ($filters['action'] === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
		}
		$out .= '</select></label><label><span class="uk-form-label">' . $this->_('Actor') . '</span><select class="uk-select" name="actor_id"><option value="0">' . $this->_('All actors') . '</option>';
		foreach ($state['actor_ids'] as $actorId) {
			$filterActorId = (int)$actorId === 0 ? -1 : (int)$actorId;
			$user = $filterActorId > 0 ? $this->wire('users')->get($filterActorId) : null;
			$label = $filterActorId === -1 ? $this->_('System') : ($user && $user->id ? (string)$user->name : '#' . $filterActorId);
			$out .= '<option value="' . $filterActorId . '"' . ((int)$filters['actor_id'] === $filterActorId ? ' selected' : '') . '>' . $this->e($label) . '</option>';
		}
		$out .= '</select></label><label><span class="uk-form-label">' . $this->_('From') . '</span><input class="uk-input" type="date" name="date_from" value="' . $this->e($filters['date_from']) . '"></label><label><span class="uk-form-label">'
			. $this->_('To') . '</span><input class="uk-input" type="date" name="date_to" value="' . $this->e($filters['date_to']) . '" max="' . date('Y-m-d') . '"></label><button class="uk-button uk-button-primary" type="submit">'
			. $this->_('Apply filters') . '</button><a class="uk-button uk-button-default" href="./?view=audit">' . $this->_('Clear') . '</a></form>';
		return $out;
	}

	protected function renderAuditPagination(array $state): string {
		if ((int)$state['pages'] < 2) return '';
		$out = '<nav aria-label="' . $this->e($this->_('Audit pages')) . '"><ul class="uk-pagination uk-flex-center" uk-margin>';
		for ($page = 1; $page <= (int)$state['pages']; $page++) {
			$params = array_filter([
				'view' => 'audit', 'q' => $state['filters']['query'], 'action_type' => $state['filters']['action'],
				'actor_id' => $state['filters']['actor_id'] ?: null, 'date_from' => $state['filters']['date_from'],
				'date_to' => $state['filters']['date_to'], 'p' => $page > 1 ? $page : null,
			], static function($value): bool { return $value !== null && $value !== ''; });
			$out .= '<li' . ($page === (int)$state['page'] ? ' class="uk-active"' : '') . '><a href="./?' . $this->e(http_build_query($params, '', '&', PHP_QUERY_RFC3986)) . '"'
				. ($page === (int)$state['page'] ? ' aria-current="page"' : '') . '>' . $page . '</a></li>';
		}
		return $out . '</ul></nav>';
	}

	protected function renderAuditLog(array $rows, array $state = []): string {
		$isFullPage = array_key_exists('total', $state);
		$out = '<section class="cockpit-panel cockpit-audit-section" id="audit"><div class="cockpit-panel-heading"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">' . $this->_('Audit') . '</p><div class="cockpit-audit-title"><h2>'
			. $this->_('Administrative activity') . '</h2>' . ($isFullPage ? '<span class="uk-text-meta">' . $this->e(sprintf($this->_('%d events'), (int)$state['total'])) . '</span>' : '')
			. '</div><p class="uk-text-meta">' . $this->_('Administrative link changes. Destination URLs and request data are not stored.')
			. '</p></div></div>';
		if (!$this->auditLog->isAvailable()) {
			return $out . '<p class="uk-text-muted">' . $this->_('Audit schema is not installed yet.') . '</p></section>';
		}
		if ($isFullPage) {
			$out .= $this->renderAuditSummary($state['counts']) . $this->renderAuditFilters($state);
		}
		if (!$rows) return $out . $this->renderEmptyState(
			$isFullPage ? $this->_('No matching events') : $this->_('No administrative changes'),
			$isFullPage ? $this->_('Clear or broaden the filters to review more administrative activity.') : $this->_('Create, edit, enable, disable, or delete a link to populate the audit log.'),
			$isFullPage ? './?view=audit' : '',
			$isFullPage ? $this->_('Clear filters') : '',
			'history'
		) . '</section>';
		$actionLabels = [
			'create' => $this->_('Created'),
			'update' => $this->_('Updated'),
			'enable' => $this->_('Enabled'),
			'disable' => $this->_('Disabled'),
			'delete' => $this->_('Deleted'),
		];
		$currentLinks = [];
		foreach ($this->cockpit->findLinks(['limit' => 500]) as $link) $currentLinks[(int)$link['id']] = true;
		$out .= '<div class="cockpit-table-panel pw-table-panel uk-overflow-auto"><table class="AdminDataTable uk-table uk-table-divider uk-table-hover uk-table-small"><thead><tr><th>'
			. $this->_('Time') . '</th><th>' . $this->_('Actor') . '</th><th>' . $this->_('Action') . '</th><th>'
			. $this->_('Path') . '</th><th>' . $this->_('Change') . '</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			$actor = (int)$row['actor_id'] > 0 ? $this->wire('users')->get((int)$row['actor_id']) : null;
			$actorLabel = (int)$row['actor_id'] === 0 ? $this->_('System') : ($actor && $actor->id ? (string)$actor->name : '#' . (int)$row['actor_id']);
			$timestamp = strtotime((string)$row['created_at']);
			$timeLabel = $timestamp ? date('M j, Y · H:i', $timestamp) : (string)$row['created_at'];
			$action = strtolower((string)$row['action']);
			$linkExists = (int)$row['link_id'] > 0 && isset($currentLinks[(int)$row['link_id']]);
			$path = '<code>/' . $this->e($row['path']) . '</code>';
			if ($linkExists) $path = '<a href="./?view=links&amp;link=' . (int)$row['link_id'] . '">' . $path . '</a>';
			$initial = strtoupper(substr($actorLabel, 0, 1));
			$out .= '<tr><td><time datetime="' . $this->e($row['created_at']) . '" title="' . $this->e($row['created_at']) . '">' . $this->e($timeLabel) . '</time></td><td><span class="cockpit-audit-actor"><i aria-hidden="true">'
				. $this->e($initial) . '</i><span>' . $this->e($actorLabel) . '</span></span></td><td><span class="' . $this->auditActionBadgeClass($action) . '">'
				. $this->e($actionLabels[$action] ?? ucfirst($action)) . '</span></td><td>' . $path . '</td><td>'
				. $this->renderAuditChange($row) . '</td></tr>';
		}
		$out .= '</tbody></table></div>';
		if ($isFullPage) $out .= $this->renderAuditPagination($state);
		return $out . '</section>';
	}

	protected function renderAuditChange(array $row): string {
		$fields = array_values(array_filter(array_map('trim', explode(',', (string)$row['changed_fields']))));
		$labels = ['path' => $this->_('Path'), 'target' => $this->_('Destination'), 'status' => $this->_('HTTP status'), 'enabled' => $this->_('Activity')];
		$out = '<div class="cockpit-audit-change"><span class="cockpit-audit-fields">';
		foreach ($fields as $field) $out .= '<span class="uk-label">' . $this->e($labels[$field] ?? ucfirst($field)) . '</span>';
		$out .= '</span>';
		$transitions = [];
		$action = strtolower((string)($row['action'] ?? ''));
		if ($action === 'create') {
			if ($row['after_status'] !== null) $transitions[] = 'HTTP ' . (int)$row['after_status'];
			if ($row['after_enabled'] !== null) $transitions[] = (int)$row['after_enabled'] ? $this->_('Active') : $this->_('Disabled');
		} elseif ($action === 'delete') {
			if ($row['before_status'] !== null) $transitions[] = 'HTTP ' . (int)$row['before_status'];
			if ($row['before_enabled'] !== null) $transitions[] = sprintf($this->_('Was %s'), (int)$row['before_enabled'] ? $this->_('active') : $this->_('disabled'));
		}
		if (!in_array($action, ['create', 'delete'], true) && ($row['before_status'] !== null || $row['after_status'] !== null)) {
			$before = $row['before_status'] !== null ? (string)(int)$row['before_status'] : '—';
			$after = $row['after_status'] !== null ? (string)(int)$row['after_status'] : '—';
			if ($before !== $after) $transitions[] = 'HTTP ' . $before . ' → ' . $after;
		}
		if (!in_array($action, ['create', 'delete'], true) && ($row['before_enabled'] !== null || $row['after_enabled'] !== null)) {
			$before = $row['before_enabled'] === null ? '—' : ((int)$row['before_enabled'] ? $this->_('Active') : $this->_('Disabled'));
			$after = $row['after_enabled'] === null ? '—' : ((int)$row['after_enabled'] ? $this->_('Active') : $this->_('Disabled'));
			if ($before !== $after) $transitions[] = $before . ' → ' . $after;
		}
		if ($transitions) $out .= '<small>' . $this->e(implode(' · ', $transitions)) . '</small>';
		return $out . '</div>';
	}

	protected function renderCapabilityNotice(): string {
		$items = [];
		if ($this->permissionPolicy->allows(CockpitPermissionPolicy::IMPORT_EXPORT)) $items[] = $this->_('Import/export permission is active; the workflow is not available in this release.');
		if ($this->permissionPolicy->allows(CockpitPermissionPolicy::DELETE_LINKS) && !$this->permissionPolicy->allowsDelete()) {
			$items[] = $this->_('Delete permission also requires link-management permission.');
		}
		if (!$items) $items[] = $this->_('No Cockpit view is available for the assigned capabilities.');
		return '<section class="uk-card uk-card-default uk-card-body"><p>' . $this->e(implode(' ', $items)) . '</p></section>';
	}

	protected function renderSettingsAccess(): string {
		$settingsUrl = rtrim((string)$this->wire('config')->urls->admin, '/') . '/module/edit?name=Cockpit&collapse_info=1';
		return '<section class="uk-card uk-card-default uk-card-body"><h2 class="uk-card-title">' . $this->_('Settings') . '</h2><p>'
			. $this->_('ProcessWire module administration permission is also required to open module settings.') . '</p><a class="uk-button uk-button-default" href="'
			. $this->e($settingsUrl) . '">' . $this->_('Open module settings') . '</a></section>';
	}

	protected function renderEmptyState(string $title, string $body, string $actionUrl = '', string $actionLabel = '', string $icon = 'info'): string {
		$out = '<div class="cockpit-empty-state pw-empty-state uk-placeholder uk-text-center"><span uk-icon="icon:' . $this->e($icon)
			. ';ratio:1.5"></span><h3 class="uk-h4">' . $this->e($title) . '</h3><p class="uk-text-muted uk-margin-small-top">'
			. $this->e($body) . '</p>';
		if ($actionUrl !== '' && $actionLabel !== '') {
			$out .= '<a class="uk-button uk-button-primary" href="' . $this->e($actionUrl) . '">' . $this->e($actionLabel) . '</a>';
		}
		return $out . '</div>';
	}

	protected function metricIcon(string $name): string {
		$paths = [
			'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15"/><path d="M14 11a5 5 0 0 0-7.54-.54l-2 2a5 5 0 0 0 7.07 7.07l1.14-1.14"/>',
			'today' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4m8-4v4M4 9h16M8 13h2m4 0h2"/>',
			'week' => '<path d="M4 18V9m5 9V5m5 13v-7m5 7V3"/>',
			'month' => '<path d="M4 19h16M5 16l4-5 4 3 6-8"/>',
			'total' => '<circle cx="12" cy="12" r="8"/><path d="M8 12h8m-4-4v8"/>',
			'compare' => '<path d="M7 7h11l-3-3m3 3-3 3M17 17H6l3 3m-3-3 3-3"/>',
			'peak' => '<path d="M4 19 9 9l3 5 3-8 5 13"/>',
		];
		$path = $paths[$name] ?? $paths['total'];
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
	}

	protected function settingsIcon(): string {
		return '<svg aria-hidden="true" fill="none" stroke-width="1.5" stroke="currentColor" viewBox="0 0 24 24"><path d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z"/><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>';
	}

	protected function requireCapability(string $permission): void {
		if (!$this->permissionPolicy->allows($permission)) {
			throw new WirePermissionException($this->_('You do not have permission to perform this Cockpit action.'));
		}
	}

	protected function recordAuditSafely(?array $before, ?array $after): void {
		try {
			$this->auditLog->recordLinkChange($before, $after, (int)$this->wire('user')->id);
		} catch (\Throwable $exception) {
			$state = $after ?: $before ?: [];
			$this->wire('log')->save('cockpit', 'Unable to record administrative audit event for link ID ' . (int)($state['id'] ?? 0) . '.');
		}
	}

	protected function e($value): string {
		return (string)$this->wire('sanitizer')->entities((string)$value);
	}
}
