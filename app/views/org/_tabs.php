<?php
/**
 * Organization hub navigation (v1.149). The 14 surfaces are grouped into five logical areas and
 * rendered by the shared grouped-nav partial: a compact rail on desktop, a single select on mobile
 * (the old single-row tab bar wrapped badly once the area grew past ~8 tabs).
 */
if (!FlxCoreHC\TeamService::canManage($ctx)) return;
$__path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (BASE_PATH !== '' && str_starts_with($__path, BASE_PATH)) $__path = substr($__path, strlen(BASE_PATH));
$B = BASE_PATH;
$navTitle = 'ORGANIZATION';
$navIcon  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>';
$navGroups = [
    'Setup' => [
        ['Profile', $B . '/settings', str_starts_with($__path, '/settings')],
        [$ctx->vertical === 'healthcare' ? 'Operating jurisdictions' : 'Lines of business', $B . '/organization/lines', str_starts_with($__path, '/organization/lines')],
        ['Tracked documents', $B . '/organization/documents-tracked', str_starts_with($__path, '/organization/documents-tracked')],
        ['Menu icons', $B . '/organization/icons', str_starts_with($__path, '/organization/icons')],
        ['Addresses', $B . '/organization/addresses', str_starts_with($__path, '/organization/addresses')],
        ['Custom fields', $B . '/organization/custom-fields', str_starts_with($__path, '/organization/custom-fields')],
        ['Email delivery', $B . '/organization/email', str_starts_with($__path, '/organization/email')],
    ],
    'People & access' => [
        ['Team', $B . '/team', str_starts_with($__path, '/team')],
        ['Roles', $B . '/organization/roles', str_starts_with($__path, '/organization/roles')],
        ['Security', $B . '/organization/security', str_starts_with($__path, '/organization/security')],
    ],
    'Compliance' => [
        ['Compliance rules', $B . '/organization/compliance', str_starts_with($__path, '/organization/compliance')],
        ['Payers', $B . '/organization/payers', $__path === '/organization/payers'],
        ['Regulations', $B . '/organization/sources', $__path === '/organization/sources'],
    ],
    'Sharing & oversight' => [
        ['Linked providers', $B . '/organization/links', $__path === '/organization/links'],
        ['Activity', $B . '/audit/pulse', $__path === '/audit/pulse'],
        ['Audit log', $B . '/audit', str_starts_with($__path, '/audit') && $__path !== '/audit/pulse'],
    ],
    'Billing' => [
        ['Plan & invoices', $B . '/billing', str_starts_with($__path, '/billing')],
    ],
];
require FLXCOREHC_APP . '/views/_hub_nav.php';
