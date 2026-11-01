<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */

declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Navigation icons.
 *
 * Icon geometry is embedded rather than loaded from a sprite or CDN, so there is no extra request,
 * no build step, and nothing to break on a shared host. Agencies can reassign the icon on any
 * navigation group or item, which matters for white-label deployments where the stock vocabulary
 * ("Book", "Network") may not match how a particular agency talks about its business.
 *
 * Icons are Tabler Icons by Pawel Kuna, MIT licensed — see assets/vendor/tabler-icons-LICENSE.txt.
 */
final class IconService
{
    /** Where each navigation entry gets its icon from by default. Keys are stable slot names. */
    public const DEFAULTS = [
        'nav.dashboard'    => 'layout-grid',
        'nav.schedule'     => 'calendar',
        'nav.clients'      => 'user',
        'nav.book'         => 'book',
        'nav.compliance'   => 'circle-check',
        'nav.organization' => 'settings',
        'nav.network'      => 'affiliate',
        'nav.billing'      => 'cash',
        'nav.platform'     => 'star',
        'nav.producers'    => 'users',
        'nav.credentials'  => 'certificate',
        'nav.tasks'        => 'list-check',
        'nav.reports'      => 'chart-bar',
        'nav.calendar'     => 'calendar-event',
        'nav.documents'    => 'file-text',
        'nav.training'     => 'school',
    ];

    /** Human labels for the slots an agency can reassign, in the order they appear in the menu. */
    public const SLOT_LABELS = [
        'nav.dashboard'    => 'Dashboard',
        'nav.schedule'     => 'Schedule (group)',
        'nav.clients'      => 'Clients (group)',
        'nav.compliance'   => 'Compliance (group)',
        'nav.organization' => 'Organization (group)',
        'nav.billing'      => 'Billing',
        'nav.platform'     => 'Platform admin',
        'nav.producers'    => 'Providers',
        'nav.credentials'  => 'Credentials',
        'nav.tasks'        => 'Tasks',
        'nav.reports'      => 'Reports',
        'nav.calendar'     => 'Calendar',
        'nav.documents'    => 'Documents',
        'nav.training'     => 'Training',
    ];

    /** Legacy short names still used in views, mapped onto catalogue icons. */
    private const ALIASES = [
        'grid' => 'layout-grid', 'cal' => 'calendar', 'clock' => 'clock', 'user' => 'user',
        'card' => 'id', 'badge' => 'certificate', 'book' => 'book', 'cash' => 'cash',
        'flag' => 'flag', 'check' => 'circle-check', 'task' => 'list-check', 'chart' => 'chart-bar',
        'list' => 'list', 'share' => 'affiliate', 'people' => 'users', 'star' => 'star',
        'building' => 'building', 'gear' => 'settings', 'pen' => 'pencil', 'shield' => 'shield-lock',
        'palette' => 'settings-2', 'briefcase' => 'briefcase', 'download' => 'download',
    ];

    /** The pickable catalogue, grouped for the chooser. */
    public const CATALOG = [
        'General' => [
            'layout-grid' => '<path d="M4 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" /> <path d="M14 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" /> <path d="M4 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" /> <path d="M14 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" />',
            'home' => '<path d="M5 12l-2 0l9 -9l9 9l-2 0" /> <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" /> <path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />',
            'star' => '<path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873l-6.158 -3.245" />',
            'bookmark' => '<path d="M18 7v14l-6 -4l-6 4v-14a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4" />',
            'flag' => '<path d="M5 5a5 5 0 0 1 7 0a5 5 0 0 0 7 0v9a5 5 0 0 1 -7 0a5 5 0 0 0 -7 0v-9" /> <path d="M5 21v-7" />',
            'pin' => '<path d="M15 4.5l-4 4l-4 1.5l-1.5 1.5l7 7l1.5 -1.5l1.5 -4l4 -4" /> <path d="M9 15l-4.5 4.5" /> <path d="M14.5 4l5.5 5.5" />',
            'tag' => '<path d="M6.5 7.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M3 6v5.172a2 2 0 0 0 .586 1.414l7.71 7.71a2.41 2.41 0 0 0 3.408 0l5.592 -5.592a2.41 2.41 0 0 0 0 -3.408l-7.71 -7.71a2 2 0 0 0 -1.414 -.586h-5.172a3 3 0 0 0 -3 3" />',
            'folder' => '<path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" />',
            'folders' => '<path d="M9 3h3l2 2h5a2 2 0 0 1 2 2v7a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2" /> <path d="M17 16v2a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2h2" />',
            'archive' => '<path d="M3 6a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2" /> <path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-10" /> <path d="M10 12l4 0" />',
            'clipboard' => '<path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /> <path d="M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" />',
            'clipboard-check' => '<path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /> <path d="M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" /> <path d="M9 14l2 2l4 -4" />',
            'clipboard-list' => '<path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /> <path d="M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" /> <path d="M9 12l.01 0" /> <path d="M13 12l2 0" /> <path d="M9 16l.01 0" /> <path d="M13 16l2 0" />',
            'notes' => '<path d="M5 5a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2l0 -14" /> <path d="M9 7l6 0" /> <path d="M9 11l6 0" /> <path d="M9 15l4 0" />',
            'note' => '<path d="M13 20l7 -7" /> <path d="M13 20v-6a1 1 0 0 1 1 -1h6v-7a2 2 0 0 0 -2 -2h-12a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7" />',
            'file' => '<path d="M14 3v4a1 1 0 0 0 1 1h4" /> <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" />',
            'files' => '<path d="M15 3v4a1 1 0 0 0 1 1h4" /> <path d="M18 17h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h4l5 5v7a2 2 0 0 1 -2 2" /> <path d="M16 17v2a2 2 0 0 1 -2 2h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h2" />',
            'file-text' => '<path d="M14 3v4a1 1 0 0 0 1 1h4" /> <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /> <path d="M9 9l1 0" /> <path d="M9 13l6 0" /> <path d="M9 17l6 0" />',
            'file-check' => '<path d="M14 3v4a1 1 0 0 0 1 1h4" /> <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /> <path d="M9 15l2 2l4 -4" />',
            'file-description' => '<path d="M14 3v4a1 1 0 0 0 1 1h4" /> <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /> <path d="M9 17h6" /> <path d="M9 13h6" />',
            'inbox' => '<path d="M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12" /> <path d="M4 13h3l3 3h4l3 -3h3" />',
            'mail' => '<path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10" /> <path d="M3 7l9 6l9 -6" />',
            'send' => '<path d="M10 14l11 -11" /> <path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5" />',
            'bell' => '<path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" /> <path d="M9 17v1a3 3 0 0 0 6 0v-1" />',
            'search' => '<path d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /> <path d="M21 21l-6 -6" />',
            'filter' => '<path d="M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227" />',
            'adjustments' => '<path d="M4 10a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M6 4v4" /> <path d="M6 12v8" /> <path d="M10 16a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M12 4v10" /> <path d="M12 18v2" /> <path d="M16 7a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M18 4v1" /> <path d="M18 9v11" />',
        ],
        'People' => [
            'user' => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /> <path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />',
            'users' => '<path d="M5 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /> <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /> <path d="M16 3.13a4 4 0 0 1 0 7.75" /> <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />',
            'user-plus' => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /> <path d="M16 19h6" /> <path d="M19 16v6" /> <path d="M6 21v-2a4 4 0 0 1 4 -4h4" />',
            'user-check' => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /> <path d="M6 21v-2a4 4 0 0 1 4 -4h4" /> <path d="M15 19l2 2l4 -4" />',
            'user-shield' => '<path d="M6 21v-2a4 4 0 0 1 4 -4h2" /> <path d="M22 16c0 4 -2.5 6 -3.5 6s-3.5 -2 -3.5 -6c1 0 2.5 -.5 3.5 -1.5c1 1 2.5 1.5 3.5 1.5" /> <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />',
            'user-star' => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /> <path d="M6 21v-2a4 4 0 0 1 4 -4h.5" /> <path d="M17.8 20.817l-2.172 1.138a.392 .392 0 0 1 -.568 -.41l.415 -2.411l-1.757 -1.707a.389 .389 0 0 1 .217 -.665l2.428 -.352l1.086 -2.193a.392 .392 0 0 1 .702 0l1.086 2.193l2.428 .352a.39 .39 0 0 1 .217 .665l-1.757 1.707l.414 2.41a.39 .39 0 0 1 -.567 .411l-2.172 -1.138" />',
            'id' => '<path d="M3 7a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v10a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -10" /> <path d="M7 10a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M15 8l2 0" /> <path d="M15 12l2 0" /> <path d="M7 16l10 0" />',
            'id-badge-2' => '<path d="M7 12h3v4h-3l0 -4" /> <path d="M10 6h-6a1 1 0 0 0 -1 1v12a1 1 0 0 0 1 1h16a1 1 0 0 0 1 -1v-12a1 1 0 0 0 -1 -1h-6" /> <path d="M10 4a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v3a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1l0 -3" /> <path d="M14 16h2" /> <path d="M14 12h4" />',
            'address-book' => '<path d="M20 6v12a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2" /> <path d="M10 16h6" /> <path d="M11 11a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M4 8h3" /> <path d="M4 12h3" /> <path d="M4 16h3" />',
            'friends' => '<path d="M5 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M5 22v-5l-1 -1v-4a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4l-1 1v5" /> <path d="M15 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M15 22v-4h-2l2 -6a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1l2 6h-2v4" />',
            'briefcase' => '<path d="M3 9a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2l0 -9" /> <path d="M8 7v-2a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v2" /> <path d="M12 12l0 .01" /> <path d="M3 13a20 20 0 0 0 18 0" />',
            'building' => '<path d="M3 21l18 0" /> <path d="M9 8l1 0" /> <path d="M9 12l1 0" /> <path d="M9 16l1 0" /> <path d="M14 8l1 0" /> <path d="M14 12l1 0" /> <path d="M14 16l1 0" /> <path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16" />',
            'building-bank' => '<path d="M3 21l18 0" /> <path d="M3 10l18 0" /> <path d="M5 6l7 -3l7 3" /> <path d="M4 10l0 11" /> <path d="M20 10l0 11" /> <path d="M8 14l0 3" /> <path d="M12 14l0 3" /> <path d="M16 14l0 3" />',
            'building-store' => '<path d="M3 21l18 0" /> <path d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1h-18l2 -4h14l2 4" /> <path d="M5 21l0 -10.15" /> <path d="M19 21l0 -10.15" /> <path d="M9 21v-4a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v4" />',
            'building-community' => '<path d="M8 9l5 5v7h-5v-4m0 4h-5v-7l5 -5m1 1v-6a1 1 0 0 1 1 -1h10a1 1 0 0 1 1 1v17h-8" /> <path d="M13 7l0 .01" /> <path d="M17 7l0 .01" /> <path d="M17 11l0 .01" /> <path d="M17 15l0 .01" />',
            'affiliate' => '<path d="M5.931 6.936l1.275 4.249m5.607 5.609l4.251 1.275" /> <path d="M11.683 12.317l5.759 -5.759" /> <path d="M4 5.5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0 -3 0" /> <path d="M17 5.5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0 -3 0" /> <path d="M17 18.5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0 -3 0" /> <path d="M4 15.5a4.5 4.5 0 1 0 9 0a4.5 4.5 0 1 0 -9 0" />',
            'network' => '<path d="M6 9a6 6 0 1 0 12 0a6 6 0 0 0 -12 0" /> <path d="M12 3c1.333 .333 2 2.333 2 6s-.667 5.667 -2 6" /> <path d="M12 3c-1.333 .333 -2 2.333 -2 6s.667 5.667 2 6" /> <path d="M6 9h12" /> <path d="M3 20h7" /> <path d="M14 20h7" /> <path d="M10 20a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M12 15v3" />',
            'sitemap' => '<path d="M3 17a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2l0 -2" /> <path d="M15 17a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2l0 -2" /> <path d="M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2l0 -2" /> <path d="M6 15v-1a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v1" /> <path d="M12 9l0 3" />',
            'hierarchy' => '<path d="M10 5a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M3 19a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M17 19a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M6.5 17.5l5.5 -4.5l5.5 4.5" /> <path d="M12 7l0 6" />',
            'share' => '<path d="M3 12a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M15 6a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M15 18a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M8.7 10.7l6.6 -3.4" /> <path d="M8.7 13.3l6.6 3.4" />',
        ],
        'Compliance' => [
            'shield' => '<path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" />',
            'shield-check' => '<path d="M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06" /> <path d="M15 19l2 2l4 -4" />',
            'shield-lock' => '<path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /> <path d="M11 11a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M12 12l0 2.5" />',
            'shield-half' => '<path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /> <path d="M12 3v18" />',
            'certificate' => '<path d="M12 15a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /> <path d="M13 17.5v4.5l2 -1.5l2 1.5v-4.5" /> <path d="M10 19h-5a2 2 0 0 1 -2 -2v-10c0 -1.1 .9 -2 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -1 1.73" /> <path d="M6 9l12 0" /> <path d="M6 12l3 0" /> <path d="M6 15l2 0" />',
            'license' => '<path d="M15 21h-9a3 3 0 0 1 -3 -3v-1h10v2a2 2 0 0 0 4 0v-14a2 2 0 1 1 2 2h-2m2 -4h-11a3 3 0 0 0 -3 3v11" /> <path d="M9 7l4 0" /> <path d="M9 11l4 0" />',
            'award' => '<path d="M6 9a6 6 0 1 0 12 0a6 6 0 1 0 -12 0" /> <path d="M12 15l3.4 5.89l1.598 -3.233l3.598 .232l-3.4 -5.889" /> <path d="M6.802 12l-3.4 5.89l3.598 -.233l1.598 3.232l3.4 -5.889" />',
            'medal' => '<path d="M12 4v3m-4 -3v6m8 -6v6" /> <path d="M12 18.5l-3 1.5l.5 -3.5l-2 -2l3 -.5l1.5 -3l1.5 3l3 .5l-2 2l.5 3.5l-3 -1.5" />',
            'checkbox' => '<path d="M9 11l3 3l8 -8" /> <path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" />',
            'circle-check' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M9 12l2 2l4 -4" />',
            'rosette-discount-check' => '<path d="M5 7.2a2.2 2.2 0 0 1 2.2 -2.2h1a2.2 2.2 0 0 0 1.55 -.64l.7 -.7a2.2 2.2 0 0 1 3.12 0l.7 .7c.412 .41 .97 .64 1.55 .64h1a2.2 2.2 0 0 1 2.2 2.2v1c0 .58 .23 1.138 .64 1.55l.7 .7a2.2 2.2 0 0 1 0 3.12l-.7 .7a2.2 2.2 0 0 0 -.64 1.55v1a2.2 2.2 0 0 1 -2.2 2.2h-1a2.2 2.2 0 0 0 -1.55 .64l-.7 .7a2.2 2.2 0 0 1 -3.12 0l-.7 -.7a2.2 2.2 0 0 0 -1.55 -.64h-1a2.2 2.2 0 0 1 -2.2 -2.2v-1a2.2 2.2 0 0 0 -.64 -1.55l-.7 -.7a2.2 2.2 0 0 1 0 -3.12l.7 -.7a2.2 2.2 0 0 0 .64 -1.55v-1" /> <path d="M9 12l2 2l4 -4" />',
            'lock' => '<path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" />',
            'lock-check' => '<path d="M11.5 21h-4.5a2 2 0 0 1 -2 -2v-6a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v.5" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /> <path d="M15 19l2 2l4 -4" />',
            'key' => '<path d="M16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1 -4.069 0l-.301 -.301l-6.558 6.558a2 2 0 0 1 -1.239 .578l-.175 .008h-1.172a1 1 0 0 1 -.993 -.883l-.007 -.117v-1.172a2 2 0 0 1 .467 -1.284l.119 -.13l.414 -.414h2v-2h2v-2l2.144 -2.144l-.301 -.301a2.877 2.877 0 0 1 0 -4.069l2.643 -2.643a2.877 2.877 0 0 1 4.069 0" /> <path d="M15 9h.01" />',
            'fingerprint' => '<path d="M18.9 7a8 8 0 0 1 1.1 5v1a6 6 0 0 0 .8 3" /> <path d="M8 11a4 4 0 0 1 8 0v1a10 10 0 0 0 2 6" /> <path d="M12 11v2a14 14 0 0 0 2.5 8" /> <path d="M8 15a18 18 0 0 0 1.8 6" /> <path d="M4.9 19a22 22 0 0 1 -.9 -7v-1a8 8 0 0 1 12 -6.95" />',
            'scale' => '<path d="M7 20l10 0" /> <path d="M6 6l6 -1l6 1" /> <path d="M12 3l0 17" /> <path d="M9 12l-3 -6l-3 6a3 3 0 0 0 6 0" /> <path d="M21 12l-3 -6l-3 6a3 3 0 0 0 6 0" />',
            'gavel' => '<path d="M13 10l7.383 7.418c.823 .82 .823 2.148 0 2.967a2.11 2.11 0 0 1 -2.976 0l-7.407 -7.385" /> <path d="M6 9l4 4" /> <path d="M13 10l-4 -4" /> <path d="M3 21h7" /> <path d="M6.793 15.793l-3.586 -3.586a1 1 0 0 1 0 -1.414l2.293 -2.293l.5 .5l3 -3l-.5 -.5l2.293 -2.293a1 1 0 0 1 1.414 0l3.586 3.586a1 1 0 0 1 0 1.414l-2.293 2.293l-.5 -.5l-3 3l.5 .5l-2.293 2.293a1 1 0 0 1 -1.414 0" />',
            'books' => '<path d="M5 5a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1l0 -14" /> <path d="M9 5a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1l0 -14" /> <path d="M5 8h4" /> <path d="M9 16h4" /> <path d="M13.803 4.56l2.184 -.53c.562 -.135 1.133 .19 1.282 .732l3.695 13.418a1.02 1.02 0 0 1 -.634 1.219l-.133 .041l-2.184 .53c-.562 .135 -1.133 -.19 -1.282 -.732l-3.695 -13.418a1.02 1.02 0 0 1 .634 -1.219l.133 -.041" /> <path d="M14 9l4 -1" /> <path d="M16 16l3.923 -.98" />',
            'book' => '<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /> <path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /> <path d="M3 6l0 13" /> <path d="M12 6l0 13" /> <path d="M21 6l0 13" />',
            'school' => '<path d="M22 9l-10 -4l-10 4l10 4l10 -4v6" /> <path d="M6 10.6v5.4a6 3 0 0 0 12 0v-5.4" />',
        ],
        'Time & work' => [
            'calendar' => '<path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12" /> <path d="M16 3v4" /> <path d="M8 3v4" /> <path d="M4 11h16" /> <path d="M11 15h1" /> <path d="M12 15v3" />',
            'calendar-event' => '<path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12" /> <path d="M16 3l0 4" /> <path d="M8 3l0 4" /> <path d="M4 11l16 0" /> <path d="M8 15h2v2h-2l0 -2" />',
            'calendar-check' => '<path d="M11.5 21h-5.5a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v6" /> <path d="M16 3v4" /> <path d="M8 3v4" /> <path d="M4 11h16" /> <path d="M15 19l2 2l4 -4" />',
            'calendar-time' => '<path d="M11.795 21h-6.795a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v4" /> <path d="M14 18a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /> <path d="M15 3v4" /> <path d="M7 3v4" /> <path d="M3 11h16" /> <path d="M18 16.496v1.504l1 1" />',
            'clock' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /> <path d="M12 7v5l3 3" />',
            'clock-hour-4' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M12 12l3 2" /> <path d="M12 7v5" />',
            'hourglass' => '<path d="M6.5 7h11" /> <path d="M6.5 17h11" /> <path d="M6 20v-2a6 6 0 1 1 12 0v2a1 1 0 0 1 -1 1h-10a1 1 0 0 1 -1 -1" /> <path d="M6 4v2a6 6 0 1 0 12 0v-2a1 1 0 0 0 -1 -1h-10a1 1 0 0 0 -1 1" />',
            'alarm' => '<path d="M5 13a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /> <path d="M12 10l0 3l2 0" /> <path d="M7 4l-2.75 2" /> <path d="M17 4l2.75 2" />',
            'history' => '<path d="M12 8l0 4l2 2" /> <path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5" />',
            'progress' => '<path d="M10 20.777a8.942 8.942 0 0 1 -2.48 -.969" /> <path d="M14 3.223a9.003 9.003 0 0 1 0 17.554" /> <path d="M4.579 17.093a8.961 8.961 0 0 1 -1.227 -2.592" /> <path d="M3.124 10.5c.16 -.95 .468 -1.85 .9 -2.675l.169 -.305" /> <path d="M6.907 4.579a8.954 8.954 0 0 1 3.093 -1.356" />',
            'list' => '<path d="M9 6l11 0" /> <path d="M9 12l11 0" /> <path d="M9 18l11 0" /> <path d="M5 6l0 .01" /> <path d="M5 12l0 .01" /> <path d="M5 18l0 .01" />',
            'list-check' => '<path d="M3.5 5.5l1.5 1.5l2.5 -2.5" /> <path d="M3.5 11.5l1.5 1.5l2.5 -2.5" /> <path d="M3.5 17.5l1.5 1.5l2.5 -2.5" /> <path d="M11 6l9 0" /> <path d="M11 12l9 0" /> <path d="M11 18l9 0" />',
            'list-details' => '<path d="M13 5h8" /> <path d="M13 9h5" /> <path d="M13 15h8" /> <path d="M13 19h5" /> <path d="M3 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" /> <path d="M3 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4" />',
            'checklist' => '<path d="M9.615 20h-2.615a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8" /> <path d="M14 19l2 2l4 -4" /> <path d="M9 8h4" /> <path d="M9 12h2" />',
            'subtask' => '<path d="M6 9l6 0" /> <path d="M4 5l4 0" /> <path d="M6 5v11a1 1 0 0 0 1 1h5" /> <path d="M12 8a1 1 0 0 1 1 -1h6a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-6a1 1 0 0 1 -1 -1l0 -2" /> <path d="M12 16a1 1 0 0 1 1 -1h6a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-6a1 1 0 0 1 -1 -1l0 -2" />',
            'timeline' => '<path d="M4 16l6 -7l5 5l5 -6" /> <path d="M14 14a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M9 9a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M3 16a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M19 8a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />',
            'route' => '<path d="M3 19a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /> <path d="M19 7a2 2 0 1 0 0 -4a2 2 0 0 0 0 4" /> <path d="M11 19h5.5a3.5 3.5 0 0 0 0 -7h-8a3.5 3.5 0 0 1 0 -7h4.5" />',
            'map-pin' => '<path d="M9 11a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /> <path d="M17.657 16.657l-4.243 4.243a2 2 0 0 1 -2.827 0l-4.244 -4.243a8 8 0 1 1 11.314 0" />',
            'world' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /> <path d="M3.6 9h16.8" /> <path d="M3.6 15h16.8" /> <path d="M11.5 3a17 17 0 0 0 0 18" /> <path d="M12.5 3a17 17 0 0 1 0 18" />',
        ],
        'Money' => [
            'cash' => '<path d="M7 15h-3a1 1 0 0 1 -1 -1v-8a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v3" /> <path d="M7 10a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v8a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1l0 -8" /> <path d="M12 14a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />',
            'coin' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /> <path d="M14.8 9a2 2 0 0 0 -1.8 -1h-2a2 2 0 1 0 0 4h2a2 2 0 1 1 0 4h-2a2 2 0 0 1 -1.8 -1" /> <path d="M12 7v10" />',
            'credit-card' => '<path d="M3 8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -8" /> <path d="M3 10l18 0" /> <path d="M7 15l.01 0" /> <path d="M11 15l2 0" />',
            'receipt' => '<path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2m4 -14h6m-6 4h6m-2 4h2" />',
            'receipt-2' => '<path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2" /> <path d="M14 8h-2.5a1.5 1.5 0 0 0 0 3h1a1.5 1.5 0 0 1 0 3h-2.5m2 0v1.5m0 -9v1.5" />',
            'wallet' => '<path d="M17 8v-3a1 1 0 0 0 -1 -1h-10a2 2 0 0 0 0 4h12a1 1 0 0 1 1 1v3m0 4v3a1 1 0 0 1 -1 1h-12a2 2 0 0 1 -2 -2v-12" /> <path d="M20 12v4h-4a2 2 0 0 1 0 -4h4" />',
            'businessplan' => '<path d="M11 6a5 3 0 1 0 10 0a5 3 0 1 0 -10 0" /> <path d="M11 6v4c0 1.657 2.239 3 5 3s5 -1.343 5 -3v-4" /> <path d="M11 10v4c0 1.657 2.239 3 5 3s5 -1.343 5 -3v-4" /> <path d="M11 14v4c0 1.657 2.239 3 5 3s5 -1.343 5 -3v-4" /> <path d="M7 9h-2.5a1.5 1.5 0 0 0 0 3h1a1.5 1.5 0 0 1 0 3h-2.5" /> <path d="M5 15v1m0 -8v1" />',
            'report-money' => '<path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /> <path d="M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" /> <path d="M14 11h-2.5a1.5 1.5 0 0 0 0 3h1a1.5 1.5 0 0 1 0 3h-2.5" /> <path d="M12 17v1m0 -8v1" />',
            'currency-dollar' => '<path d="M16.7 8a3 3 0 0 0 -2.7 -2h-4a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-4a3 3 0 0 1 -2.7 -2" /> <path d="M12 3v3m0 12v3" />',
            'pig-money' => '<path d="M15 11v.01" /> <path d="M5.173 8.378a3 3 0 1 1 4.656 -1.377" /> <path d="M16 4v3.803a6.019 6.019 0 0 1 2.658 3.197h1.341a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-1.342c-.336 .95 -.907 1.8 -1.658 2.473v2.027a1.5 1.5 0 0 1 -3 0v-.583a6.04 6.04 0 0 1 -1 .083h-4a6.04 6.04 0 0 1 -1 -.083v.583a1.5 1.5 0 0 1 -3 0v-2l0 -.027a6 6 0 0 1 4 -10.473h2.5l4.5 -3" />',
            'invoice' => '<path d="M14 3v4a1 1 0 0 0 1 1h4" /> <path d="M19 12v7a1.78 1.78 0 0 1 -3.1 1.4a1.65 1.65 0 0 0 -2.6 0a1.65 1.65 0 0 1 -2.6 0a1.65 1.65 0 0 0 -2.6 0a1.78 1.78 0 0 1 -3.1 -1.4v-14a2 2 0 0 1 2 -2h7l5 5v4.25" />',
            'chart-bar' => '<path d="M3 13a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -6" /> <path d="M15 9a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -10" /> <path d="M9 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -14" /> <path d="M4 20h14" />',
            'chart-line' => '<path d="M4 19l16 0" /> <path d="M4 15l4 -6l4 2l4 -5l4 4" />',
            'chart-pie' => '<path d="M10 3.2a9 9 0 1 0 10.8 10.8a1 1 0 0 0 -1 -1h-6.8a2 2 0 0 1 -2 -2v-7a.9 .9 0 0 0 -1 -.8" /> <path d="M15 3.5a9 9 0 0 1 5.5 5.5h-4.5a1 1 0 0 1 -1 -1v-4.5" />',
            'chart-dots' => '<path d="M3 3v18h18" /> <path d="M7 9a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M17 7a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M12 15a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M10.16 10.62l2.34 2.88" /> <path d="M15.088 13.328l2.837 -4.586" />',
            'trending-up' => '<path d="M3 17l6 -6l4 4l8 -8" /> <path d="M14 7l7 0l0 7" />',
            'report' => '<path d="M8 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h5.697" /> <path d="M18 14v4h4" /> <path d="M18 11v-4a2 2 0 0 0 -2 -2h-2" /> <path d="M8 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" /> <path d="M14 18a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /> <path d="M8 11h4" /> <path d="M8 15h3" />',
            'presentation' => '<path d="M3 4l18 0" /> <path d="M4 4v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-10" /> <path d="M12 16l0 4" /> <path d="M9 20l6 0" /> <path d="M8 12l3 -3l2 2l3 -3" />',
            'graph' => '<path d="M4 18v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2" /> <path d="M7 14l3 -3l2 2l3 -3l2 2" />',
        ],
        'System' => [
            'settings' => '<path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065" /> <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />',
            'settings-2' => '<path d="M19.875 6.27a2.225 2.225 0 0 1 1.125 1.948v7.284c0 .809 -.443 1.555 -1.158 1.948l-6.75 4.27a2.269 2.269 0 0 1 -2.184 0l-6.75 -4.27a2.225 2.225 0 0 1 -1.158 -1.948v-7.285c0 -.809 .443 -1.554 1.158 -1.947l6.75 -3.98a2.33 2.33 0 0 1 2.25 0l6.75 3.98h-.033" /> <path d="M9 12a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />',
            'tool' => '<path d="M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5" />',
            'tools' => '<path d="M3 21h4l13 -13a1.5 1.5 0 0 0 -4 -4l-13 13v4" /> <path d="M14.5 5.5l4 4" /> <path d="M12 8l-5 -5l-4 4l5 5" /> <path d="M7 8l-1.5 1.5" /> <path d="M16 12l5 5l-4 4l-5 -5" /> <path d="M16 17l-1.5 1.5" />',
            'adjustments-horizontal' => '<path d="M12 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M4 6l8 0" /> <path d="M16 6l4 0" /> <path d="M6 12a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M4 12l2 0" /> <path d="M10 12l10 0" /> <path d="M15 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /> <path d="M4 18l11 0" /> <path d="M19 18l1 0" />',
            'database' => '<path d="M4 6a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" /> <path d="M4 6v6a8 3 0 0 0 16 0v-6" /> <path d="M4 12v6a8 3 0 0 0 16 0v-6" />',
            'server' => '<path d="M3 7a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3" /> <path d="M3 15a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -2" /> <path d="M7 8l0 .01" /> <path d="M7 16l0 .01" />',
            'cloud' => '<path d="M6.657 18c-2.572 0 -4.657 -2.007 -4.657 -4.483c0 -2.475 2.085 -4.482 4.657 -4.482c.393 -1.762 1.794 -3.2 3.675 -3.773c1.88 -.572 3.956 -.193 5.444 1c1.488 1.19 2.162 3.007 1.77 4.769h.99c1.913 0 3.464 1.56 3.464 3.486c0 1.927 -1.551 3.487 -3.465 3.487h-11.878" />',
            'device-desktop' => '<path d="M3 5a1 1 0 0 1 1 -1h16a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-16a1 1 0 0 1 -1 -1v-10" /> <path d="M7 20h10" /> <path d="M9 16v4" /> <path d="M15 16v4" />',
            'device-mobile' => '<path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14" /> <path d="M11 4h2" /> <path d="M12 17v.01" />',
            'plug' => '<path d="M9.785 6l8.215 8.215l-2.054 2.054a5.81 5.81 0 1 1 -8.215 -8.215l2.054 -2.054" /> <path d="M4 20l3.5 -3.5" /> <path d="M15 4l-3.5 3.5" /> <path d="M20 9l-3.5 3.5" />',
            'api' => '<path d="M4 13h5" /> <path d="M12 16v-8h3a2 2 0 0 1 2 2v1a2 2 0 0 1 -2 2h-3" /> <path d="M20 8v8" /> <path d="M9 16v-5.5a2.5 2.5 0 0 0 -5 0v5.5" />',
            'code' => '<path d="M7 8l-4 4l4 4" /> <path d="M17 8l4 4l-4 4" /> <path d="M14 4l-4 16" />',
            'terminal' => '<path d="M5 7l5 5l-5 5" /> <path d="M12 19l7 0" />',
            'refresh' => '<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /> <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />',
            'download' => '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /> <path d="M7 11l5 5l5 -5" /> <path d="M12 4l0 12" />',
            'upload' => '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /> <path d="M7 9l5 -5l5 5" /> <path d="M12 4l0 12" />',
            'trash' => '<path d="M4 7l16 0" /> <path d="M10 11l0 6" /> <path d="M14 11l0 6" /> <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /> <path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" />',
            'pencil' => '<path d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4" /> <path d="M13.5 6.5l4 4" />',
            'plus' => '<path d="M12 5l0 14" /> <path d="M5 12l14 0" />',
            'dots' => '<path d="M4 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M11 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M18 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />',
            'menu-2' => '<path d="M4 6l16 0" /> <path d="M4 12l16 0" /> <path d="M4 18l16 0" />',
            'layout-sidebar' => '<path d="M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12" /> <path d="M9 4l0 16" />',
        ],
    ];

    /** Flatten the catalogue to name => path. */
    public static function all(): array
    {
        static $flat = null;
        if ($flat === null) {
            $flat = [];
            foreach (self::CATALOG as $icons) $flat += $icons;
        }
        return $flat;
    }

    /** Decode a tenant's icon overrides, ignoring anything not in the catalogue. */
    public static function overrides(?string $json): array
    {
        if (!is_string($json) || $json === '') return [];
        $raw = json_decode($json, true);
        if (!is_array($raw)) return [];
        $all = self::all();
        $out = [];
        foreach ($raw as $slot => $icon) {
            if (!is_string($slot) || !is_string($icon)) continue;
            if (!isset(self::SLOT_LABELS[$slot]) || !isset($all[$icon])) continue;
            $out[$slot] = $icon;
        }
        return $out;
    }

    /** Validate and encode overrides for storage. Slots left at the default are not stored. */
    public static function encode(array $in): ?string
    {
        $all = self::all();
        $out = [];
        foreach (self::SLOT_LABELS as $slot => $_) {
            $icon = (string) ($in[$slot] ?? '');
            if ($icon === '' || !isset($all[$icon])) continue;
            if ((self::DEFAULTS[$slot] ?? '') === $icon) continue;
            $out[$slot] = $icon;
        }
        return $out === [] ? null : json_encode($out);
    }

    /**
     * Render an icon as inline SVG.
     * $name may be a slot ('nav.billing'), a catalogue name ('cash'), or a legacy alias.
     */
    public static function svg(string $name, array $overrides = [], int $size = 20): string
    {
        $icon = null;
        if (isset(self::SLOT_LABELS[$name])) {
            $icon = $overrides[$name] ?? (self::DEFAULTS[$name] ?? null);
        }
        $icon ??= self::ALIASES[$name] ?? $name;
        $path = self::all()[$icon] ?? '';
        if ($path === '') return '';
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none"'
             . ' stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"'
             . ' aria-hidden="true">' . $path . '</svg>';
    }
}
