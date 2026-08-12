#!/usr/bin/env php
<?php

/** Static invariants for the dependency-free ProcessWire admin workspace. */

function cockpitAdminUiFail(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

$root = dirname(__DIR__);
$process = file_get_contents($root . '/ProcessCockpit.module.php');
$module = file_get_contents($root . '/Cockpit.module.php');
$styles = file_get_contents($root . '/assets/css/process-cockpit.css');
$scripts = file_get_contents($root . '/assets/js/process-cockpit.js');
$utmScript = file_get_contents($root . '/assets/js/cockpit-utm.js');
if ($process === false || $module === false || $styles === false || $scripts === false || $utmScript === false) cockpitAdminUiFail('Unable to read admin UI assets.');

foreach (['overview', 'links', 'analytics', 'audit'] as $view) {
	if (strpos($process, "'{$view}'") === false) cockpitAdminUiFail("Missing admin view: {$view}.");
}
foreach (['uk-subnav uk-subnav-pill cockpit-admin-nav-list', 'uk-button uk-button-default', 'uk-table', 'uk-progress'] as $primitive) {
	if (strpos($process, $primitive) === false) cockpitAdminUiFail("Missing ProcessWire/UIkit primitive: {$primitive}.");
}
foreach (['pw-module-workspace', 'cockpit-page-intro', 'cockpit-metric-grid', 'pw-filter-panel', 'pw-table-panel', 'pw-empty-state', 'cockpit-insight-grid', 'cockpit-panel'] as $workspacePrimitive) {
	if (strpos($process, $workspacePrimitive) === false) cockpitAdminUiFail("Missing design-system workspace primitive: {$workspacePrimitive}.");
}
foreach (['InputfieldForm', 'InputfieldText', 'InputfieldURL', 'InputfieldSelect', 'InputfieldCheckbox', 'InputfieldSubmit'] as $inputfieldClass) {
	if (strpos($process, "get('{$inputfieldClass}')") === false) cockpitAdminUiFail("Link editor is missing native {$inputfieldClass}.");
}
foreach (['addUtmBuilderFields', 'InputfieldFieldset', 'utm_website_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_source_platform', 'utm_term', 'utm_content', 'data-utm-apply', 'data-utm-copy', 'data-utm-clear'] as $utmBoundary) {
	if (strpos($process, $utmBoundary) === false) cockpitAdminUiFail("Missing Campaign URL Builder boundary: {$utmBoundary}.");
}
foreach (['initUtmBuilders', 'window.CockpitUtm', 'parseUrl', 'buildUrl', 'Source, medium, and campaign are required'] as $utmScriptBoundary) {
	if (strpos($scripts . $utmScript, $utmScriptBoundary) === false) cockpitAdminUiFail("Missing UTM interaction boundary: {$utmScriptBoundary}.");
}
foreach (['.Inputfield_submit_link', "columnWidth = 100", 'justify-content: flex-end', '.InputfieldColumnWidth:not(.Inputfield_submit_link)'] as $editorLayoutBoundary) {
	if (strpos($process . $styles, $editorLayoutBoundary) === false) cockpitAdminUiFail("Missing stable editor action layout: {$editorLayoutBoundary}.");
}
foreach (['redirectStatusOptions', 'redirectStatusHelp', '302 — Recommended', 'you can change the destination later', '301 only if it will never change', 'forms and APIs', 'cockpit-field-help', 'columnWidth = 24'] as $redirectGuidanceBoundary) {
	if (strpos($process . $styles, $redirectGuidanceBoundary) === false) cockpitAdminUiFail("Missing non-technical redirect guidance boundary: {$redirectGuidanceBoundary}.");
}
foreach (['cockpit-quick-redirect-help', 'grid-column: 1 / -1;', 'max-width: none;'] as $quickRedirectHelpBoundary) {
	if (strpos($process . $styles, $quickRedirectHelpBoundary) === false) cockpitAdminUiFail("Quick redirect guidance is not in its own row: {$quickRedirectHelpBoundary}.");
}
foreach (['cockpit-line-chart', 'cockpit-donut-chart', 'role="img"', 'data-points'] as $chartBoundary) {
	if (strpos($process, $chartBoundary) === false) cockpitAdminUiFail("Missing chart boundary: {$chartBoundary}.");
}
foreach (['Traffic filters', 'Quick periods', 'date_from', 'date_to', 'Previous period', 'cockpit-period-strip', 'cockpit-analytics-grid'] as $analyticsBoundary) {
	if (strpos($process . $styles, $analyticsBoundary) === false) cockpitAdminUiFail("Missing rich analytics boundary: {$analyticsBoundary}.");
}
foreach (['auditListState', 'renderAuditSummary', 'renderAuditFilters', 'renderAuditPagination', 'renderAuditChange', 'auditActionBadgeClass', 'uk-label-success', 'uk-label-warning', 'uk-label-danger', 'cockpit-audit-summary', 'cockpit-audit-filter', 'cockpit-audit-fields', "date('M j, Y · H:i'", "'System'"] as $auditUxBoundary) {
	if (strpos($process . $styles, $auditUxBoundary) === false) cockpitAdminUiFail("Missing polished Audit UX boundary: {$auditUxBoundary}.");
}
if (strpos($styles, '.cockpit-audit-action') !== false) cockpitAdminUiFail('Audit badges must use unmodified native UIkit label styling.');
if (strpos($styles, '.cockpit-audit-fields > span') !== false) cockpitAdminUiFail('Audit changed-field badges must use unmodified native UIkit label styling.');
if (strpos($process, '<span class="uk-label">') === false) cockpitAdminUiFail('Audit changed fields are missing native UIkit labels.');
foreach (['periodBucketLabel', 'cockpit-period-value', 'cockpit-period-flags', 'is-peak', 'is-latest', "'Peak'", "'Latest'", 'grid-template-columns: repeat(6, minmax(0, 1fr));', 'grid-template-columns: repeat(2, minmax(0, 1fr));'] as $timelineUxBoundary) {
	if (strpos($process . $styles, $timelineUxBoundary) === false) cockpitAdminUiFail("Missing responsive period-detail UX boundary: {$timelineUxBoundary}.");
}
foreach (['<details class="cockpit-panel cockpit-period-card">', 'cockpit-period-summary', 'cockpit-period-toggle', "'Show periods'", "'Hide periods'", '.cockpit-period-card[open]'] as $timelineDisclosureBoundary) {
	if (strpos($process . $styles, $timelineDisclosureBoundary) === false) cockpitAdminUiFail("Missing progressive Timeline disclosure boundary: {$timelineDisclosureBoundary}.");
}
if (strpos($process, 'scroll horizontally for the full range') !== false || strpos($styles, 'grid-auto-flow: column;') !== false) cockpitAdminUiFail('Period detail reintroduced the horizontal card scroller.');
foreach (['cockpit-filter-summary', 'cockpit-analytics-summary', 'number_format($percentage, 1)', 'aspect-ratio: 1'] as $analyticsUxBoundary) {
	if (strpos($process . $styles, $analyticsUxBoundary) === false) cockpitAdminUiFail("Missing analytics UX boundary: {$analyticsUxBoundary}.");
}
foreach (['previous_buckets', 'previous_date_from', 'formatDateRange', 'cockpit-filter-summary', 'cockpit-chart-series', 'data-previous-points', "ctx.setLineDash([7, 6])", 'previousPoints', 'All HTTP statuses'] as $comparisonUxBoundary) {
	if (strpos($process . $module . $styles . $scripts, $comparisonUxBoundary) === false) cockpitAdminUiFail("Missing analytics comparison UX boundary: {$comparisonUxBoundary}.");
}
foreach (["'active_links'", "'disabled_links'", 'cockpit-top-links', "'View statistics'", "'last_hit_at'"] as $dashboardUxBoundary) {
	if (strpos($process . $module . $styles, $dashboardUxBoundary) === false) cockpitAdminUiFail("Missing dashboard UX boundary: {$dashboardUxBoundary}.");
}
foreach (['Lifetime click share', 'cockpit-ranked-link', 'cockpit-rank', 'cockpit-click-count', "'Rank %d'", 'Short URL copied', "date('M j, Y · H:i'", 'cockpit-summary > :last-child:nth-child(odd)', 'Which redirect?'] as $overviewUxBoundary) {
	if (strpos($process . $styles, $overviewUxBoundary) === false) cockpitAdminUiFail("Missing polished Overview boundary: {$overviewUxBoundary}.");
}
foreach (['renderQuickCreate', 'cockpit-quick-create-form', 'return_view', "'Quick create'", "'Link ready'"] as $quickCreateBoundary) {
	if (strpos($process . $styles, $quickCreateBoundary) === false) cockpitAdminUiFail("Missing dashboard quick-create boundary: {$quickCreateBoundary}.");
}
if (substr_count($process, '[A-Za-z0-9._~\\/\\-]+') !== 2) cockpitAdminUiFail('Path inputs are missing the HTML pattern compatible with Unicode Sets mode.');
if (strpos($process, '[A-Za-z0-9._~/-]+') !== false) cockpitAdminUiFail('Path inputs reintroduced a pattern that is invalid in browser Unicode Sets mode.');
if (strpos($scripts, "classList.add('cockpit-admin-page')") === false || strpos($styles, 'html.cockpit-admin-page body { overflow-x: hidden; }') === false || strpos($styles, '.cockpit-top-links { max-width: 100%; overflow: hidden; }') === false) {
	cockpitAdminUiFail('Dashboard table is missing mobile overflow containment.');
}
if (strpos($styles, 'body.ProcessCockpit #main') !== false) cockpitAdminUiFail('Cockpit must not override the shared AdminThemeUikit page container.');
if (strpos($styles, '.cockpit-period-presets > :first-child { padding-left: 0; }') === false) cockpitAdminUiFail('Quick periods reintroduced the first-item left inset.');
if (strpos($scripts, 'Math.max(240') !== false || strpos($scripts, 'Math.max(180') !== false) {
	cockpitAdminUiFail('Canvas minimum dimensions would distort responsive charts.');
}
foreach (['resolveCssColor', "getComputedStyle(probe).color", "prefers-color-scheme: dark", '_cockpitChartColors'] as $darkChartBoundary) {
	if (strpos($scripts, $darkChartBoundary) === false) cockpitAdminUiFail("Missing resolved dark-theme chart color boundary: {$darkChartBoundary}.");
}
foreach (['renderQrPanel', '#qr', 'Download SVG', 'Generated by FieldtypeQRCode', 'cockpit-qr-layout'] as $qrBoundary) {
	if (strpos($process . $styles, $qrBoundary) === false) cockpitAdminUiFail("Missing QR admin boundary: {$qrBoundary}.");
}
foreach (['renderIconButton', "'copy'", "'edit'", "'statistics'", "'qr'", "'delete'", 'cockpit-icon-button', 'aria-label=', 'focus-visible'] as $actionBoundary) {
	if (strpos($process . $styles, $actionBoundary) === false) cockpitAdminUiFail("Missing accessible icon-action boundary: {$actionBoundary}.");
}
foreach (['cockpit-short-url-cell', 'cockpit-short-url', 'data-copy-success=', 'cockpit-copy-toast', "classList.add('is-copied')", "setAttribute('aria-live', 'polite')"] as $copyUxBoundary) {
	if (strpos($process . $styles . $scripts, $copyUxBoundary) === false) cockpitAdminUiFail("Missing stable copy-action UX boundary: {$copyUxBoundary}.");
}
if (strpos($scripts, "button.textContent = 'Copied'") !== false) cockpitAdminUiFail('Copy feedback must not replace icon-button content or alter table layout.');
foreach (['cockpit-status-cell', 'cockpit-status-code', 'grid-template-columns: 3ch max-content;', 'font-variant-numeric: tabular-nums;', 'text-align: right;'] as $statusAlignmentBoundary) {
	if (strpos($process . $styles, $statusAlignmentBoundary) === false) cockpitAdminUiFail("Missing numeric-first aligned status boundary: {$statusAlignmentBoundary}.");
}
foreach (['border: 0;', 'background: transparent;', 'color: var(--pw-main-color);'] as $iconChromeBoundary) {
	if (strpos($styles, $iconChromeBoundary) === false) cockpitAdminUiFail("Icon actions have reintroduced permanent button chrome: {$iconChromeBoundary}.");
}
if (strpos($styles, 'color-mix(in srgb, var(--pw-main-color) 11%, transparent)') !== false) cockpitAdminUiFail('Icon actions reintroduced a colored hover tile.');
if (strpos($styles, 'filter: drop-shadow(0 0 .2rem currentColor);') === false) cockpitAdminUiFail('Icon actions are missing non-box keyboard focus feedback.');
foreach (['renderLinkManagementPage', 'renderLinkPageIntro', 'renderLinkSectionNav', 'formatAdminDateTime', '?view=links&link=', 'cockpit-link-facts', 'cockpit-link-metrics', 'cockpit-link-section-nav', 'cockpit-link-traffic-stack', 'findForLink($id, 50)', 'id="analytics"', 'id="editor"', 'id="qr"'] as $managementBoundary) {
	if (strpos($process . $styles, $managementBoundary) === false) cockpitAdminUiFail("Missing canonical link management boundary: {$managementBoundary}.");
}
foreach (['renderPeriodDetail', 'array_reverse($stats)', 'newest first', 'is-latest', 'is-peak', 'No clicks in this range'] as $periodOrderBoundary) {
	if (strpos($process, $periodOrderBoundary) === false) cockpitAdminUiFail("Missing newest-first period-detail boundary: {$periodOrderBoundary}.");
}
foreach (['emptyActionUrl', 'No clicks were recorded in the last 30 days', 'Open full analytics'] as $linkTrafficEmptyBoundary) {
	if (strpos($process, $linkTrafficEmptyBoundary) === false) cockpitAdminUiFail("Missing actionable per-link empty traffic state: {$linkTrafficEmptyBoundary}.");
}
foreach (['--pw-text-color', '--pw-border-color', '--pw-primary-color', '--pw-blocks-background'] as $themeVariable) {
	if (strpos($styles, $themeVariable) === false) cockpitAdminUiFail("Missing ProcessWire theme variable: {$themeVariable}.");
}
foreach (['--cockpit-space-xs', '--cockpit-space-sm', '--cockpit-space-md', '--cockpit-space-lg', '--cockpit-space-xl'] as $spacingToken) {
	if (strpos($styles, $spacingToken) === false) cockpitAdminUiFail("Missing Tickets-compatible spacing token: {$spacingToken}.");
}
if (strpos($styles, '.cockpit-link-filter {') === false || strpos($styles, 'margin-bottom: var(--cockpit-space-md);') === false) {
	cockpitAdminUiFail('Links filters and inventory table are missing their vertical gap.');
}
foreach (['cockpit-inventory-title', '.cockpit-link-filter .cockpit-checkbox', 'grid-template-columns: minmax(15rem, 1.7fr) repeat(3, minmax(8.5rem, 1fr)) auto auto;'] as $inventoryFilterBoundary) {
	if (strpos($process . $styles, $inventoryFilterBoundary) === false) cockpitAdminUiFail("Missing compact inventory filter boundary: {$inventoryFilterBoundary}.");
}
foreach (['$this->renderLinksTable', '$this->renderEditor(null)', 'href="#editor"', 'Most links: 302.', 'cockpit-links-section .cockpit-table-panel table', 'table-layout: fixed;', 'min-width: 920px;', "date('M j, Y · H:i'", 'cockpit-click-count uk-text-right', '.Inputfields.uk-grid-match > .InputfieldColumnWidth', 'height: auto !important;', 'align-self: flex-start;'] as $linksPageUxBoundary) {
	if (strpos($process . $styles, $linksPageUxBoundary) === false) cockpitAdminUiFail("Missing inventory-first Links UX boundary: {$linksPageUxBoundary}.");
}
if (strpos($process, 'cockpit-filter-count') !== false) cockpitAdminUiFail('Inventory count reintroduced as a filter-grid column.');
if (strpos($styles, '--pw-spacing') !== false) cockpitAdminUiFail('Undefined --pw-spacing token reintroduced.');
if (strpos($process, 'cockpit-admin-settings') === false || strpos($process, 'uk-subnav uk-subnav-pill cockpit-admin-nav-list') === false) {
	cockpitAdminUiFail('Primary navigation must use the Tickets-style pill navigation and separate settings control.');
}
if (strpos($process, 'uk-tab pw-module-tabs') !== false) cockpitAdminUiFail('Legacy tab navigation reintroduced.');
if (strpos($styles, '.ProcessCockpit.cockpit-workspace') === false) cockpitAdminUiFail('Custom CSS is not scoped to the Cockpit workspace.');
foreach (['.cockpit-admin > * { min-width: 0; }', '@media (max-width: 639px)', '.cockpit-table-panel table', '.cockpit-admin-nav-list'] as $responsiveBoundary) {
	if (strpos($styles, $responsiveBoundary) === false) cockpitAdminUiFail("Missing responsive containment boundary: {$responsiveBoundary}.");
}
foreach (['eval(', 'new Function(', 'document.write(', 'http://', 'https://'] as $forbiddenScript) {
	if (strpos($scripts, $forbiddenScript) !== false) cockpitAdminUiFail("Forbidden script construct: {$forbiddenScript}.");
}
if (strpos($scripts, 'drawLineChart') === false || strpos($scripts, 'drawDonutChart') === false) {
	cockpitAdminUiFail('Dependency-free chart renderers are missing.');
}
foreach ([
	"\$section(\$this->_('Links')",
	"\$section(\$this->_('Routing')",
	"\$section(\$this->_('Analytics')",
	"\$section(\$this->_('Integrations')",
	"\$section(\$this->_('Data & privacy')",
	"Inputfield::skipLabelHeader",
	"Inputfield::collapsedYes",
	"'warning' : 'primary'",
	"'danger'",
] as $configBoundary) {
	if (strpos($module, $configBoundary) === false) cockpitAdminUiFail("Missing config UI boundary: {$configBoundary}.");
}
if (substr_count($process, 'collapse_info=1') < 3) cockpitAdminUiFail('Cockpit settings links do not collapse module information consistently.');
foreach ([
	'configurePageChrome($view, $managedLink)',
	"'overview' => [\$this->_('Dashboard'), '']",
	"\$this->breadcrumb(\$baseUrl, \$this->_('Cockpit'))",
	"\$this->browserTitle(sprintf(\$this->_('%s — Cockpit'), \$label))",
	"'?view=links&link=' . (int)\$managedLink['id']",
] as $pageChromeBoundary) {
	if (strpos($process, $pageChromeBoundary) === false) cockpitAdminUiFail("Missing page chrome boundary: {$pageChromeBoundary}.");
}
foreach ([
	'configureSettingsPageChrome()',
	"\$process->headline(\$this->_('Cockpit settings'))",
	"\$process->breadcrumb(\$workspaceUrl, \$this->_('Cockpit'))",
	"\$process->breadcrumb(\$settingsUrl, \$this->_('Settings'))",
] as $settingsChromeBoundary) {
	if (strpos($module, $settingsChromeBoundary) === false) cockpitAdminUiFail("Missing settings chrome boundary: {$settingsChromeBoundary}.");
}

echo "Admin UI static tests passed\n";
