<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT149 — Jotform beyond its team members (those go through SystemConnect.php / SaasUsers.php): the forms of the
 * account with who owns each, and the forms you choose whose new submissions become tickets. Read only: the key needs
 * "Read Access" only, and nothing is ever written to Jotform (no webhook is created there; the app asks every ten
 * minutes instead: one call per form switched on, 144 a day, inside even the Starter plan's 1,000 calls a day).
 *
 *   API       GET /v1/user/forms?offset&limit (content: id, username = the owner, title, status, created_at,
 *             updated_at, count, new, last_submission, url) · GET /v1/form/{id}/submissions?offset&limit&filter&orderby
 *             (content: id, form_id, created_at, status, answers {qid: {name, text, type, answer, prettyFormat}}).
 *             Header "APIKEY", never in an address. The US, EU and HIPAA servers as the connection says.
 *   settings  itops_settings.jotform_tickets = {forms: {form id: {on, title, category_id, priority, baseline, on_since,
 *             by}}, last_poll, last_result}
 *   table     itops_jf_submissions: every submission looked at, once (UNIQUE form + submission), with what was done.
 *
 * Rules to keep:
 *   - Submissions sent before a form was switched on never make tickets: switching on records the newest submission
 *     id there is (the baseline), and only higher ids count. No clock or time zone is involved.
 *   - A submission is looked at once. Deleted submissions are passed over.
 *   - The ticket carries the answers as the form shows them; files and signatures are named, not copied.
 *   - When the form has an email answer that belongs to an active person of the app, that person is the requester;
 *     otherwise the person who switched the form on, with the submitter kept as the ticket's contact.
 */
require_once __DIR__ . '/SystemConnect.php';

if (!class_exists('ItopsJotform')) {
    }

if (!function_exists('itops_jf_client')) {



    function itops_jf_table(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS itops_jf_submissions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id VARCHAR(24) NOT NULL, submission_id VARCHAR(32) NOT NULL,
            form_title VARCHAR(190) NOT NULL DEFAULT '', submitted_at VARCHAR(25) NOT NULL DEFAULT '', submitter VARCHAR(190) NOT NULL DEFAULT '', action VARCHAR(10) NOT NULL DEFAULT '',
            reason VARCHAR(160) NOT NULL DEFAULT '', ticket_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_jf_sub (form_id, submission_id), INDEX idx_jf_ticket (ticket_id), INDEX idx_jf_created (created_at))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }





}
