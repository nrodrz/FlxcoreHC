<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT18: which access area (permission module) a setting key belongs to.
 * The Settings page has one tab per area; Roles & Permissions grants each area separately.
 *   settings  → General (connection, security, redirects, scheduler, anything unlisted)
 *   branding  → Branding (name, logo, favicon, navigation icons)
 *   email_cfg → Email + Email templates (outbound mail, intake mailbox, notification switches)
 *   sla_cfg   → SLA & Hours (SLA alerts, digests, business hours)
 * Maintenance (database updates) is not a setting key — it stays Super Admin only.
 */
if (!function_exists('itops_setting_area')) {
    function itops_setting_area(string $key): string {
        $k = strtolower($key);
        if (preg_match('/^(app_name|app_tagline|signin_default|app_logo_url|app_favicon_url|app_install_enabled|app_short_name|app_theme_color|app_wipe_signout|nav_emoji|org_name|org_|doc_)/', $k)) return 'branding';
        if (preg_match('/^(mail_|email_|smtp_|imap_|tpl_|auto_tick)/', $k)) return 'email_cfg';
        if (preg_match('/^(sla_|business_hours)/', $k)) return 'sla_cfg';
        return 'settings';
    }
    function itops_setting_area_label(string $area): string {
        return ['settings' => 'Settings · General', 'branding' => 'Settings · Branding',
                'email_cfg' => 'Settings · Email', 'sla_cfg' => 'Settings · SLA & Hours'][$area] ?? $area;
    }
}
