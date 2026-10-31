<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * BT138 — THIS APP'S data dictionary catalog (white-label standard, "Data dictionary"). The engine (DataDict.php), the
 * routes (routes/datadict.php) and the screen (Administration → Data) are the same in every app; only this file differs.
 *
 * When a build adds a table, it adds its line here in the same build: the test fails while any table of a fresh
 * installation is not described (report "undescribed").
 *
 *   area   the part of the app the table belongs to (groups the list on the screen)
 *   kind   ref (reference list; the only kind that may be editable) · main · detail · link · log · system
 */
if (!function_exists('itops_dd_app')) {
    /** prefix of this app's tables; folders whose PHP files are searched for table names; files skipped in that search;
     *  the key/value settings table whose key-like values are hidden */
    function itops_dd_app(): array {
        return ['prefix' => 'itops_', 'code_dirs' => ['api/lib', 'api/routes', ''], 'code_skip' => ['Migrations', 'DataDict', 'DataDictCatalog'],
                'settings' => ['itops_settings', 'skey', 'sval']];   // [table, key column, value column]
    }

    /** This app's own secret rules: true = hidden, false = shown, null = let the engine decide. */
    function itops_dd_app_secret(string $table, string $c): ?bool {
        if ($table === 'itops_recycle' && $c === 'data') return true;   // BT141: a deleted record as kept (credentials keep their wrapped values)
        return null;
    }

    /** table => [area, kind, what it is for] */
    function itops_dd_catalog(): array {
        return [
            'itops_recycle' => ['Maintenance', 'system', 'Deleted items (BT141): each deleted record with the rows that belong to it and the names of its files, kept until it is put back or deleted for good (Administration → Deleted items).'],
            'itops_alerts' => ['Monitoring', 'log', 'Alerts raised by the app\'s own checks (server, jobs, integrations) and their state.'],
            'itops_api_keys' => ['Integrations', 'system', 'API keys for other systems that call this app (the key itself is kept only as a hash).'],
            'itops_audit_log' => ['Audit', 'log', 'The Audit Log: every change and refusal, with who, when, the value before and after. Never changed or purged.'],
            'itops_auth_throttle' => ['Security', 'system', 'Failed sign-in counters used to slow down password guessing.'],
            'itops_cron_months' => ['Scheduled jobs', 'log', 'Monthly summary of the scheduled jobs: one line per job and month.'],
            'itops_cron_runs' => ['Scheduled jobs', 'log', 'Every run of a scheduled job: steps, duration, result (kept for the current month).'],
            'itops_db_snapshots' => ['Maintenance', 'log', 'One row per day with the size of every table, for the growth chart on Settings → Maintenance.'],
            'itops_departments' => ['Organization', 'ref', 'Departments people, categories and records belong to.'],
            'itops_device_inventory' => ['Microsoft 365', 'main', 'Every device seen signing in with a work account (Entra devices and the sign-in log).'],
            'itops_email_templates' => ['Email', 'system', 'The email templates as changed in Settings → Email templates (English and Spanish).'],
            'itops_event_log' => ['Logs', 'log', 'The event log: technical messages (warnings, errors) from every part of the app.'],
            'itops_lm_billing' => ['License Hub', 'main', 'How each license is billed (monthly or annual, price).'],
            'itops_lm_charges' => ['License Hub', 'detail', 'Charges made for licenses, per period.'],
            'itops_lm_clients' => ['License Hub', 'main', 'Client profiles of the License Hub.'],
            'itops_lm_installs' => ['License Hub', 'main', 'Installations that check in with this License Hub (their token only as a hash).'],
            'itops_lm_licenses' => ['License Hub', 'main', 'Licenses issued by the License Manager.'],
            'itops_lm_links' => ['License Hub', 'system', 'Link codes not claimed yet.'],
            'itops_lm_payments' => ['License Hub', 'detail', 'Payments recorded against charges.'],
            'itops_lm_prices' => ['License Hub', 'system', 'The price list of plans and extra modules.'],
            'itops_lm_releases' => ['License Hub', 'main', 'Update bundles offered to installations, with their channel.'],
            'itops_m365_dev_requests' => ['Microsoft 365', 'main', 'Requests to retire, wipe or fresh-start a device, waiting for a second person.'],
            'itops_m365_policy_backups' => ['Microsoft 365', 'log', 'Backups of Intune and app-protection policies, to restore or copy them.'],
            'itops_m365_role_changes' => ['Microsoft 365', 'log', 'Admin role changes made from the app (kept so they can be put back).'],
            'itops_m365_templates' => ['Microsoft 365', 'main', 'Onboarding templates: Microsoft 365 fields, licenses, groups and manager.'],
            'itops_mail_log' => ['Email', 'log', 'Every email the app sent or tried to send.'],
            'itops_migrations' => ['Maintenance', 'system', 'Which database updates were applied, and when.'],
            'itops_notifications' => ['Notifications', 'detail', 'The bell: notifications for each person, read or not.'],
            'itops_ob_runs' => ['Onboarding', 'main', 'Onboarding and offboarding records: the person, the template, the state.'],
            'itops_ob_run_tasks' => ['Onboarding', 'detail', 'The steps of an onboarding or offboarding, with who did them and the result.'],
            'itops_ob_templates' => ['Onboarding', 'main', 'Onboarding and offboarding templates.'],
            'itops_ob_template_tasks' => ['Onboarding', 'detail', 'The steps of a template.'],
            'itops_pc_people' => ['Devices', 'main', 'Personal computers protected by Defender: invitation, agreement, the computer\'s check, withdrawal.'],
            'itops_risk_actions' => ['Security', 'detail', 'Actions taken on a risky person (messages, proposals).'],
            'itops_risk_followups' => ['Security', 'detail', 'Follow-ups of a risky person: deadline, escalation.'],
            'itops_risk_people' => ['Security', 'detail', 'The risky people found by an assessment run.'],
            'itops_risk_runs' => ['Security', 'log', 'Runs of the risky-people assessment.'],
            'itops_roles' => ['Organization', 'system', 'Roles and their permissions (changed in Roles & Permissions).'],
            'itops_sa_ai_suggestions' => ['Security assessment', 'detail', 'The AI planning assistant\'s suggestions for a draft plan (shown only).'],
            'itops_sa_ca_changes' => ['Security assessment', 'log', 'Conditional Access changes made from the app, with the policy as it was.'],
            'itops_sa_change_requests' => ['Security assessment', 'main', 'Changes waiting for a second person.'],
            'itops_sa_drift' => ['Security assessment', 'log', 'Rules that passed in one scan and fail or warn in the next.'],
            'itops_sa_plans' => ['Security assessment', 'main', 'Security plans built from an assessment, and the decisions on them.'],
            'itops_sa_plan_items' => ['Security assessment', 'detail', 'The items of a security plan.'],
            'itops_sa_results' => ['Security assessment', 'detail', 'The result of each rule in an assessment run.'],
            'itops_sa_runs' => ['Security assessment', 'log', 'Security assessment runs.'],
            'itops_sessions' => ['Security', 'system', 'Signed-in sessions (the token only as a hash).'],
            'itops_settings' => ['Settings', 'system', 'Settings of the installation (secrets kept encrypted and never shown here).'],
            'itops_terms_acceptances' => ['Legal', 'log', 'Who accepted which version of the terms of use, and when.'],
            'itops_users' => ['Organization', 'main', 'People who can sign in: name, email, role, department, status (passwords and sign-in secrets never shown).'],
            'itops_webhooks' => ['Integrations', 'system', 'Webhooks: where events are sent (secrets kept encrypted).'],
            'itops_webhook_deliveries' => ['Integrations', 'log', 'Every webhook delivery and its answer.'],
        ];
    }

    /** The columns that may be changed in the grid: table => [column, …]. Only reference tables. */
    function itops_dd_editable(): array {
        return [
            'itops_departments' => ['name', 'description', 'color', 'icon', 'status'],
        ];
    }

    /** Groups of tables with the same shape or purpose, with what merging them would mean (the report). */
    function itops_dd_families(): array {
        return [   // 0001-A005: the families of this product's tables
            ['Run logs', ['itops_cron_runs', 'itops_risk_runs', 'itops_sa_runs'],
             'All record "something ran". Their columns differ a lot (steps, findings, sizes) and each page reads its own. Merging would not save much. Keep.', 'keep'],
            ['Event and mail logs', ['itops_event_log', 'itops_mail_log', 'itops_webhook_deliveries', 'itops_alerts'],
             'Different readers and retention. Keep them apart; they are trimmed by the daily cleanup.', 'keep'],
            ['Audit Log', ['itops_audit_log'], 'Never merged or changed: it is the record of every change.', 'keep'],
        ];
    }

}
