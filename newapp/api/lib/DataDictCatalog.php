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
        if ($table === 'itops_credentials' && !in_array($c, ['id', 'name', 'description', 'category_id', 'department_id', 'system_url', 'environment', 'owner_id', 'rotation_days', 'last_rotated_at', 'next_rotation_date', 'status', 'created_at', 'updated_at', 'series_id', 'term_no', 'renewed_from_id', 'renewed_to_id', 'renewal_note', 'ended_at', 'end_reason', 'valid_from', 'valid_until'], true)) return true;
        return null;
    }

    /** table => [area, kind, what it is for] */
    function itops_dd_catalog(): array {
        return [
            'itops_access_log' => ['Audit', 'log', 'Who revealed, copied or rotated a credential (kind cred) or opened a document (kind doc), and when. Kept as history (BT138: one table for both).'],
            'itops_recycle' => ['Maintenance', 'system', 'Deleted items (BT141): each deleted record with the rows that belong to it and the names of its files, kept until it is put back or deleted for good (Administration → Deleted items).'],
            'itops_categories' => ['Organization', 'ref', 'Categories of Knowledge Base articles (kind kb), documents (doc, with how long they are kept), SOPs (sop) and credentials (cred). BT138: one table for the four lists; ticket categories have their own.'],
            'itops_alerts' => ['Monitoring', 'log', 'Alerts raised by the app\'s own checks (server, jobs, integrations) and their state.'],
            'itops_announcements' => ['Announcements', 'main', 'Announcements: text (and Spanish text), level, audience, channels, schedule and "I have read this".'],
            'itops_announcement_audience' => ['Announcements', 'link', 'Who an announcement is for when it is for chosen people: users, departments, roles.'],
            'itops_announcement_audience_ext' => ['Announcements', 'link', 'Recipients outside the app: Microsoft 365 groups, typed addresses, people found in Microsoft 365, everyone in Microsoft 365 and its domains.'],
            'itops_announcement_external' => ['Announcements', 'detail', 'One row per person outside the app an announcement went to: email state, personal link (hashed), read and confirmed.'],
            'itops_announcement_recipients' => ['Announcements', 'detail', 'One row per person with an account an announcement was published to: email state, seen, confirmed, reminded.'],
            'itops_api_keys' => ['Integrations', 'system', 'API keys for other systems that call this app (the key itself is kept only as a hash).'],
            'itops_assets' => ['Assets', 'main', 'Equipment and other assets: tag, type, who has it, where, status, purchase and warranty.'],
            'itops_asset_loans' => ['Assets', 'detail', 'Loans of assets to people: out, due back, returned.'],
            'itops_attachments' => ['Files', 'detail', 'Files attached to records (any kind of record), with who added them.'],
            'itops_audit_log' => ['Audit', 'log', 'The Audit Log: every change and refusal, with who, when, the value before and after. Never changed or purged.'],
            'itops_auth_throttle' => ['Security', 'system', 'Failed sign-in counters used to slow down password guessing.'],
            'itops_backup_events' => ['Backups', 'log', 'Check-ins from backup jobs (success, failure, size) for the Backups page and its alerts.'],
            'itops_backup_jobs' => ['Backups', 'main', 'The backup jobs watched: what is backed up, how often it should check in.'],
            'itops_budget_actuals' => ['Budget', 'detail', 'Actual IT spend per budget line and month.'],
            'itops_budget_lines' => ['Budget', 'main', 'IT budget lines per fiscal year and site.'],
            'itops_budget_sites' => ['Budget', 'ref', 'Sites (offices) the IT budget is split by.'],
            'itops_calendar_attendees' => ['Calendar', 'link', 'People invited to a calendar event and their answer.'],
            'itops_calendar_events' => ['Calendar', 'main', 'Calendar events (maintenance windows, meetings, deadlines).'],
            'itops_calendar_reminders_sent' => ['Calendar', 'log', 'Reminders already sent for events (so none is sent twice).'],
            'itops_calendar_reports' => ['Calendar', 'system', 'Saved calendar report settings.'],
            'itops_cases' => ['Cases & Projects', 'main', 'Cases and projects: work that needs several people, with tasks, approvals and a requester view.'],
            'itops_case_budget' => ['Cases & Projects', 'detail', 'Budget lines of a case or project.'],
            'itops_case_digest' => ['Cases & Projects', 'log', 'The daily case digest already sent to each person.'],
            'itops_case_phases' => ['Cases & Projects', 'detail', 'Phases and milestone dates of a project.'],
            'itops_case_svc' => ['Cases & Projects', 'detail', 'Problem and change details of a case (change management), equipment links.'],
            'itops_case_tasks' => ['Cases & Projects', 'detail', 'Tasks of a case: who, due date, state.'],
            'itops_case_task_checks' => ['Cases & Projects', 'detail', 'A short checklist inside a case task.'],
            'itops_case_templates' => ['Cases & Projects', 'main', 'Case templates: tasks with business-day offsets, people and what the requester sees.'],
            'itops_case_template_tasks' => ['Cases & Projects', 'detail', 'The tasks of a case template.'],
            'itops_case_updates' => ['Cases & Projects', 'detail', 'Updates (comments and changes) posted on a case.'],
            'itops_case_watchers' => ['Cases & Projects', 'link', 'People who follow a case.'],
            'itops_ci_links' => ['Cases & Projects', 'link', 'Configuration items (equipment, systems) linked to records.'],
            'itops_collector_runs' => ['Integration Audit', 'log', 'Runs of the integration collectors (what was read, when, result).'],
            'itops_contracts' => ['Contracts', 'main', 'Contracts with vendors: dates, renewal, value, owner.'],
            'itops_credentials' => ['Credentials', 'main', 'Shared credentials (passwords are kept encrypted and never shown here).'],
            'itops_cron_months' => ['Scheduled jobs', 'log', 'Monthly summary of the scheduled jobs: one line per job and month.'],
            'itops_cron_runs' => ['Scheduled jobs', 'log', 'Every run of a scheduled job: steps, duration, result (kept for the current month).'],
            'itops_db_snapshots' => ['Maintenance', 'log', 'One row per day with the size of every table, for the growth chart on Settings → Maintenance.'],
            'itops_departments' => ['Organization', 'ref', 'Departments people, categories and records belong to.'],
            'itops_device_inventory' => ['Microsoft 365', 'main', 'Every device seen signing in with a work account (Entra devices and the sign-in log).'],
            'itops_documents' => ['Documents', 'main', 'Documents: title, category, owner, versions, retention.'],
            'itops_email_templates' => ['Email', 'system', 'The email templates as changed in Settings → Email templates (English and Spanish).'],
            'itops_event_log' => ['Logs', 'log', 'The event log: technical messages (warnings, errors) from every part of the app.'],
            'itops_forms' => ['Forms', 'main', 'Forms (public or internal) and their fields.'],
            'itops_form_submissions' => ['Forms', 'detail', 'What people sent through a form.'],
            'itops_improvements' => ['Improvements', 'main', 'The improvement register: ideas, owner, state, benefit.'],
            'itops_integration_evidence' => ['Integration Audit', 'detail', 'Evidence collected for the integration audit.'],
            'itops_integration_findings' => ['Integration Audit', 'main', 'Findings of the integration audit.'],
            'itops_integration_observations' => ['Integration Audit', 'log', 'Raw observations of integrations (calls, volumes, errors).'],
            'itops_integration_obs_rollup' => ['Integration Audit', 'log', 'Daily roll-up of the observations.'],
            'itops_integration_registry' => ['Integration Audit', 'main', 'The register of integrations between systems.'],
            'itops_integration_schedules' => ['Integration Audit', 'system', 'When each integration is expected to run.'],
            'itops_integration_tables' => ['Integration Audit', 'detail', 'Tables of other systems an integration reads or writes.'],
            'itops_integration_unknowns' => ['Integration Audit', 'log', 'Integration traffic not matched to a registered integration.'],
            'itops_invoices' => ['Invoices', 'main', 'Invoices from vendors, with their lines tied to contracts.'],
            'itops_invoice_lines' => ['Invoices', 'detail', 'Lines of an invoice.'],
            'itops_jd_versions' => ['Job descriptions', 'detail', 'Earlier versions of a job description.'],
            'itops_job_descriptions' => ['Job descriptions', 'main', 'Job descriptions.'],
            'itops_kb_articles' => ['Knowledge Base', 'main', 'Knowledge Base articles.'],
            'itops_kb_gaps' => ['Knowledge Base', 'log', 'Questions Ask IT could not answer (articles to write).'],
            'itops_kb_votes' => ['Knowledge Base', 'detail', '"Was this helpful?" answers on articles.'],
            'itops_license_subs' => ['Licenses', 'main', 'Software subscriptions and seats the organization pays for.'],
            'itops_links' => ['Records', 'link', 'Links between any two records (a ticket to a case, a document to a vendor).'],
            'itops_lm_billing' => ['License Hub', 'main', 'How each license is billed (monthly or annual, price).'],
            'itops_lm_charges' => ['License Hub', 'detail', 'Charges made for licenses, per period.'],
            'itops_lm_clients' => ['License Hub', 'main', 'Client profiles of the License Hub.'],
            'itops_lm_installs' => ['License Hub', 'main', 'Installations that check in with this License Hub (their token only as a hash).'],
            'itops_lm_licenses' => ['License Hub', 'main', 'Licenses issued by the License Manager.'],
            'itops_lm_links' => ['License Hub', 'system', 'Link codes not claimed yet.'],
            'itops_lm_payments' => ['License Hub', 'detail', 'Payments recorded against charges.'],
            'itops_lm_prices' => ['License Hub', 'system', 'The price list of plans and extra modules.'],
            'itops_lm_releases' => ['License Hub', 'main', 'Update bundles offered to installations, with their channel.'],
            'itops_jf_submissions' => ['Company systems', 'log', 'Jotform submissions looked at by the forms check, each once, with the ticket made.'],
            'itops_m360_alerts' => ['Monitoring', 'log', 'Alerts received from 360 Monitoring.'],
            'itops_m365_dev_requests' => ['Microsoft 365', 'main', 'Requests to retire, wipe or fresh-start a device, waiting for a second person.'],
            'itops_m365_incident_tickets' => ['Microsoft 365', 'link', 'The ticket made for a Defender incident (one per incident).'],
            'itops_m365_policy_backups' => ['Microsoft 365', 'log', 'Backups of Intune and app-protection policies, to restore or copy them.'],
            'itops_m365_role_changes' => ['Microsoft 365', 'log', 'Admin role changes made from the app (kept so they can be put back).'],
            'itops_m365_templates' => ['Microsoft 365', 'main', 'Onboarding templates: Microsoft 365 fields, licenses, groups and manager.'],
            'itops_mail_log' => ['Email', 'log', 'Every email the app sent or tried to send.'],
            'itops_major_reviews' => ['Cases & Projects', 'main', 'Reviews after a major incident.'],
            'itops_migrations' => ['Maintenance', 'system', 'Which database updates were applied, and when.'],
            'itops_notifications' => ['Notifications', 'detail', 'The bell: notifications for each person, read or not.'],
            'itops_ob_runs' => ['Onboarding', 'main', 'Onboarding and offboarding records: the person, the template, the state.'],
            'itops_ob_run_tasks' => ['Onboarding', 'detail', 'The steps of an onboarding or offboarding, with who did them and the result.'],
            'itops_ob_templates' => ['Onboarding', 'main', 'Onboarding and offboarding templates.'],
            'itops_ob_template_tasks' => ['Onboarding', 'detail', 'The steps of a template.'],
            'itops_outlook_links' => ['Calendar', 'link', 'Which calendar event matches which Outlook event (two-way sync).'],
            'itops_outlook_users' => ['Calendar', 'system', 'Each person\'s Outlook calendar sync: on or off, last sync.'],
            'itops_ip_geo' => ['Servers', 'system', 'Location and network (country, region, city, ISP, host name) of the addresses seen by the web server, looked up only when switched on in Server performance → Settings; kept 30 days, then looked up again.'],
            'itops_server_daily' => ['Servers', 'log', 'One row a day of the server\'s performance: average and highest CPU (and when), each hour\'s CPU, load, memory, swap, database, web, network and disk traffic, disk space, spikes, restarts and the busiest accounts and addresses. Kept 3 years by default (Server performance → Settings).'],
            'itops_server_digest' => ['Servers', 'log', 'Hourly snapshots of the 200 queries that cost the database most (from the database\'s own statistics, read with the monitoring user): the query with its values replaced by ?, runs, total and longest time, rows read and sent, runs without an index. Kept 30 days.'],
            'itops_server_errlog' => ['Servers', 'log', 'The error logs of the server\'s accounts, read through WHM every 10 minutes: one row per kind of error, account, file and hour, with how many times it happened, when last, and up to 3 example lines (passwords and keys masked). Kept 30 days.'],
            'itops_ip_seen' => ['Servers', 'log', 'Every address the web server\'s status page showed: when it was first and last seen and how often. Marks the "new" places on the map of Server performance. Kept 90 days after the last time.'],
            'itops_net_hour' => ['Servers', 'log', 'One row an hour of the web traffic: addresses, new ones, countries (and those new outside the allowed ones), sign-in-page askers, the network with most addresses, the usual number for that hour, and the level (good, busy, high, critical) with why. Kept 400 days.'],
            'itops_signin_flags' => ['Servers', 'log', 'Sign-ins to this app from outside the allowed countries (worked or failed): who, the address, its country, city and network, when; whether an exception allowed it and whether the administrators were alerted. Kept a year.'],
            'itops_server_perf' => ['Servers', 'log', 'Performance samples of the server this app runs on (CPU, memory, load, disks, network, processes, who was asking the web server, the database\'s connections with masked queries), one per scheduled run; removed after the days kept (30 by default).'],
            'itops_pbx_cdr' => ['Company systems', 'log', 'Call records of the office phone system (PBX), read every five minutes for its reports; removed after the days kept (400 by default).'],
            'itops_phone_calls' => ['Company systems', 'log', 'Missed calls looked at by the missed-call check, each once, with the ticket or note made.'],
            'itops_pc_people' => ['Devices', 'main', 'Personal computers protected by Defender: invitation, agreement, the computer\'s check, withdrawal.'],
            'itops_people_links' => ['Company systems', 'main', 'Which person has which account in which company system (read or recorded).'],
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
            'itops_scorecard_entries' => ['Scorecard', 'detail', 'Weekly or monthly values of each measurable.'],
            'itops_scorecard_measurables' => ['Scorecard', 'ref', 'The measurables of the scorecard: goal, owner, unit.'],
            'itops_server_metric_samples' => ['Integration Audit', 'log', 'Samples of server metrics (load, disk) over time.'],
            'itops_sessions' => ['Security', 'system', 'Signed-in sessions (the token only as a hash).'],
            'itops_settings' => ['Settings', 'system', 'Settings of the installation (secrets kept encrypted and never shown here).'],
            'itops_signature_requests' => ['Signatures', 'main', 'Requests to sign a document.'],
            'itops_signature_signers' => ['Signatures', 'detail', 'The people who sign a document, and when they signed.'],
            'itops_sla_alerts' => ['Tickets', 'log', 'Service-level warnings already sent (so none is sent twice).'],
            'itops_sops' => ['SOPs', 'main', 'Standard operating procedures.'],
            'itops_sop_acks' => ['SOPs', 'detail', 'Who confirmed reading which version of an SOP.'],
            'itops_sop_audience' => ['SOPs', 'link', 'Who an SOP is for.'],
            'itops_sop_versions' => ['SOPs', 'detail', 'Earlier versions of an SOP.'],
            'itops_std_domains' => ['Standards & Audits', 'ref', 'The domains of the standards (groups of requirements).'],
            'itops_std_evidence' => ['Standards & Audits', 'detail', 'Evidence attached to a requirement.'],
            'itops_std_requirements' => ['Standards & Audits', 'main', 'The requirements of the standards and their state.'],
            'itops_terms_acceptances' => ['Legal', 'log', 'Who accepted which version of the terms of use, and when.'],
            'itops_tickets' => ['Tickets', 'main', 'Tickets: requests and incidents, with requester, category, priority, assignee, state and dates.'],
            'itops_ticket_attachments' => ['Tickets', 'detail', 'Files attached to tickets.'],
            'itops_ticket_categories' => ['Tickets', 'ref', 'Ticket categories, with their service levels and default assignee.'],
            'itops_ticket_comments' => ['Tickets', 'detail', 'Comments on tickets (public replies and internal notes).'],
            'itops_ticket_csat' => ['Tickets', 'detail', 'Satisfaction ratings of closed tickets.'],
            'itops_ticket_related_groups' => ['Tickets', 'link', 'Groups of related tickets.'],
            'itops_uptime_checks' => ['Uptime', 'log', 'Every check of an uptime monitor.'],
            'itops_uptime_incidents' => ['Uptime', 'log', 'Outages found by the uptime monitors.'],
            'itops_uptime_monitors' => ['Uptime', 'main', 'Uptime monitors: websites and hosting accounts watched.'],
            'itops_users' => ['Organization', 'main', 'People who can sign in: name, email, role, department, status (passwords and sign-in secrets never shown).'],
            'itops_vendors' => ['Vendors', 'ref', 'Vendors: contact, support, service level, risk tier.'],
            'itops_vendor_contacts' => ['Vendors', 'ref', 'Contacts at each vendor.'],
            'itops_webhooks' => ['Integrations', 'system', 'Webhooks: where events are sent (secrets kept encrypted).'],
            'itops_webhook_deliveries' => ['Integrations', 'log', 'Every webhook delivery and its answer.'],
        ];
    }

    /** The columns that may be changed in the grid: table => [column, …]. Only reference tables. */
    function itops_dd_editable(): array {
        return [
            'itops_departments' => ['name', 'description', 'color', 'icon', 'status'],
            'itops_ticket_categories' => ['name', 'description', 'sla_hours', 'sla_response_hours', 'sort_order', 'icon', 'status'],
            'itops_categories' => ['name', 'icon', 'color', 'retention_days', 'sort_order'],
            'itops_budget_sites' => ['name', 'address', 'region', 'notes', 'active', 'sort_order'],
            'itops_vendors' => ['name', 'website', 'primary_contact_name', 'primary_contact_email', 'primary_contact_phone', 'account_manager', 'support_url', 'support_phone', 'support_email', 'sla_response_hours', 'risk_tier', 'category', 'notes', 'status'],
            'itops_vendor_contacts' => ['name', 'title', 'email', 'phone', 'role', 'is_primary', 'notes'],
            'itops_std_domains' => ['name', 'description', 'sort_order', 'notes'],
            'itops_scorecard_measurables' => ['name', 'unit', 'sort_order', 'active'],
        ];
    }

    /** Groups of tables with the same shape or purpose, with what merging them would mean (the report). */
    function itops_dd_families(): array {
        return [
            ['Category lists', ['itops_categories', 'itops_ticket_categories'],
             'Merged in BT138: the Knowledge Base, document, SOP and credential categories are one table (its kind column says which). Ticket categories stay apart: they carry service levels, routing and ticket types.', 'keep'],
            ['Access logs', ['itops_access_log'],
             'Merged in BT138: credentials revealed and documents opened are one log (kind cred or doc).', 'keep'],
            ['Run logs', ['itops_cron_runs', 'itops_collector_runs', 'itops_risk_runs', 'itops_sa_runs', 'itops_backup_events'],
             'All record "something ran". Their columns differ a lot (steps, findings, sizes) and each page reads its own. Merging would not save much. Keep.', 'keep'],
            ['Event and mail logs', ['itops_event_log', 'itops_mail_log', 'itops_webhook_deliveries', 'itops_alerts'],
             'Different readers and retention. Keep them apart; they are trimmed by the daily cleanup.', 'keep'],
            ['Audit Log', ['itops_audit_log'], 'Never merged or changed: it is the record of every change.', 'keep'],
            ['Announcement recipients', ['itops_announcement_recipients', 'itops_announcement_external'],
             'People with an account and people outside the app. They hold different things (bell vs personal link). Keep.', 'keep'],
        ];
    }

}
