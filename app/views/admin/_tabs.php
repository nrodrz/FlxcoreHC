<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Platform console navigation (v1.149). Grouped into four areas and rendered by the shared
 * grouped-nav partial — compact rail on desktop, one select on mobile.
 * Callers set $adminTab (kept for compatibility) but the active item derives from the URL.
 */
$__path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (BASE_PATH !== '' && str_starts_with($__path, BASE_PATH)) $__path = substr($__path, strlen(BASE_PATH));
$B = BASE_PATH;
$at = fn(string $p) => $__path === $B . $p || $__path === $p || str_starts_with($__path, ($B ?: '') . $p . '/');
$navTitle = 'PLATFORM CONSOLE';
$navIcon  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>';
$pend = (int) ($pendingCount ?? 0);
$navGroups = [
    'Customers' => [
        ['Tenants', $B . '/admin/tenants', $at('/admin/tenants') || $__path === $B . '/admin' || $__path === '/admin'],
        ['Approvals' . ($pend > 0 ? ' (' . $pend . ')' : ''), $B . '/admin/approvals', $at('/admin/approvals')],
        ['Users', $B . '/admin/users', $at('/admin/users')],
        ['Invoices', $B . '/admin/invoices', $at('/admin/invoices')],
    ],
    'Content & data' => [
        ['Platform data', $B . '/admin/platform', $at('/admin/platform')],
        ['Regulatory sources', $B . '/admin/regulatory', $at('/admin/regulatory')],
        ['Translations', $B . '/admin/translations', $at('/admin/translations')],
    ],
    'Operations' => [
        ['System & updates', $B . '/admin/system', $at('/admin/system')],
        ['Database', $B . '/admin/database', $at('/admin/database')],
        ['Email', $B . '/admin/email', $at('/admin/email')],
        ['Errors', $B . '/admin/errors', $at('/admin/errors')],
        ['Audit log', $B . '/admin/audit', $at('/admin/audit')],
    ],
    'Reference' => [
        ['Security posture', $B . '/admin/security', $at('/admin/security')],
        ['Changelog', $B . '/admin/changelog', $at('/admin/changelog')],
        ['Capabilities', $B . '/admin/capabilities', $at('/admin/capabilities')],
        ['Test plan', $B . '/admin/test-plan', $at('/admin/test-plan')],
        ['Demo accounts', $B . '/admin/demo-accounts', $at('/admin/demo-accounts')],
    ],
];
require FLXCOREHC_APP . '/views/_hub_nav.php';
