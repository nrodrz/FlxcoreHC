<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
require_once __DIR__ . '/Brand.php';
/**
 * OpsHub — Email templates (English + Spanish).
 *
 * Every email the system sends has a KEY in the catalog below with built-in default subject/body
 * in English and Spanish. Admins can override the text per language (opsh_email_templates table)
 * and switch individual emails off; "Reset" deletes the override so the default applies again.
 *
 *   opsh_email($key, $to, $vars, $opts)   → render in the recipient's language and send (logged)
 *   opsh_tpl_render($key, $lang, $vars, $override = null) → ['subject','text','html']
 *   opsh_tpl_validate($key, $subject, $body) → [] or list of problems
 *
 * TEMPLATE SYNTAX (deliberately small):
 *   {{placeholder}}        value for this email (see each template's "vars"); unknown names are rejected on save
 *   **bold**               bold text
 *   [label](https://…)     link; bare https:// URLs become links automatically
 *   - item                 bullet list line
 *   blank line             new paragraph
 * Values are HTML-escaped, and so is the template text itself: no raw HTML can be injected.
 *
 * LANGUAGE: a user's own "Email language" (users.language) wins; people without an account (form
 * submitters, email senders) get the organisation default (mail_default_lang: en | es | both).
 * "both" sends the English text followed by the Spanish text in one email.
 */

if (!function_exists('opsh_tpl_catalog')) {

function opsh_tpl_common_vars(): array {
    return [
        'greeting'       => ['Hello Alex,', 'Greeting with the recipient\'s name in their language ("Hello Alex," / "Hola Alex,")'],
        'recipient_name' => ['Alex Sample', 'Recipient\'s name (may be empty for people without an account)'],
        'org_name'       => ['Example Co.', 'Organisation name (Connection → Branding)'],
        'helpdesk_name'  => ['IT Help Desk', 'From name (Connection → Email)'],
        'helpdesk_email' => ['itops@example.org', 'From address (Connection → Email)'],
        'app_url'        => ['https://ops.example.com', 'Link to the app'],
        'app_name'       => [opsh_app_name(), 'The app name set under Settings → Branding → App name'],
        'portal_link'    => ['Follow it online: sign in to ' . opsh_app_name() . ' to see the status, reply to the team or add files — Open TK-2026-0142 in ' . opsh_app_name(), 'Sign-in box with a link straight to the ticket. Only filled in for people who have an account in the app (empty for outside requesters). On its own line.'],
    ];
}

/** Ticket emails that get the "follow it online" box (added automatically if an edited template leaves it out). */
function opsh_tpl_portal_keys(): array { return ['ticket_ack_email', 'ticket_ack_form', 'ticket_reply', 'ticket_status', 'ticket_resolved']; }

/** Link that opens a ticket in the app (the app keeps it through sign-in). */
function opsh_tpl_ticket_url(int $ticketId): string { return defined('APP_URL') ? rtrim(APP_URL, '/') . '/#tickets/' . $ticketId : ''; }

/** The "follow it online" paragraph (markdown), per language. */
function opsh_tpl_portal_link(string $url, string $ticketNumber): array {
    if ($url === '') return ['en' => '', 'es' => ''];
    $an = opsh_app_name();   // BT20
    return ['en' => "**Follow it online:** sign in to $an to see the status and every update, reply to the person working on it, or add files — [Open " . ($ticketNumber !== '' ? $ticketNumber : 'your ticket') . " in $an]($url)",
            'es' => "**Sígala en línea:** inicie sesión en $an para ver el estado y cada actualización, responder a quien la atiende o añadir archivos — [Abrir " . ($ticketNumber !== '' ? $ticketNumber : 'su ticket') . " en $an]($url)"];
}

/** key => [group, label, description, recipients, master-setting|null, vars{name=>[sample,help]}, required-in-subject[], en[subj,body], es[subj,body]] */
function opsh_tpl_catalog(): array {
    $T = ['ticket_number' => ['TK-2026-0142', 'Ticket number — keep it in the subject so replies thread onto the ticket'],
          'subject'       => ['Laptop will not connect to Wi-Fi', 'Ticket subject'],
          'link'          => ['https://ops.example.com', 'Link to the app']];
    $L = ['link' => ['https://ops.example.com', 'Link to the app']];
    return [
    // ───────────────────────────── Tickets ─────────────────────────────
    'ticket_ack_email' => ['Tickets', 'Request received (by email)', 'Sent to someone who emails the help-desk mailbox, confirming a ticket was opened.',
        'The sender', 'mail_ack',
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'requester_email' => ['alex@example.org', 'Sender address']],
        ['ticket_number'],
        ['[{{ticket_number}}] We received your request: {{subject}}',
         "{{greeting}}\n\nThanks for contacting {{helpdesk_name}}. Your request has been logged as **{{ticket_number}}** and our team will follow up shortly.\n\n**Subject:** {{subject}}\n\nTo add information, just reply to this email and keep **{{ticket_number}}** in the subject line.\n\n{{portal_link}}"],
        ['[{{ticket_number}}] Recibimos su solicitud: {{subject}}',
         "{{greeting}}\n\nGracias por comunicarse con {{helpdesk_name}}. Su solicitud quedó registrada como **{{ticket_number}}** y nuestro equipo le dará seguimiento en breve.\n\n**Asunto:** {{subject}}\n\nPara añadir información, responda a este correo y mantenga **{{ticket_number}}** en la línea de asunto.\n\n{{portal_link}}"]],
    'ticket_ack_form' => ['Tickets', 'Request received (web page / form)', 'Sent to someone who submits the public ticket page or an intake form.',
        'The submitter', null,
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'form_name' => ['New hire equipment request', 'Name of the form (empty for the ticket page)']],
        ['ticket_number'],
        ['[{{ticket_number}}] We received your request: {{subject}}',
         "{{greeting}}\n\nThanks for your submission. It has been logged as **{{ticket_number}}** and our team will follow up shortly.\n\n**Subject:** {{subject}}\n\nIf you reply to this email, please keep **{{ticket_number}}** in the subject line.\n\n{{portal_link}}"],
        ['[{{ticket_number}}] Recibimos su solicitud: {{subject}}',
         "{{greeting}}\n\nGracias por su envío. Quedó registrado como **{{ticket_number}}** y nuestro equipo le dará seguimiento en breve.\n\n**Asunto:** {{subject}}\n\nSi responde a este correo, mantenga **{{ticket_number}}** en la línea de asunto.\n\n{{portal_link}}"]],
    'ticket_confirm_form' => ['Tickets', 'Was this you? (request sent with your address)', 'BT87: sent instead of "Request received" when someone submits the public ticket page or an intake form with the address of a person of the app. The request is filed in their name only when they confirm it.',
        'The address given', null,
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'confirm_link' => ['https://opshub.example.org/confirm.html?t=…', 'The page where the person confirms the request (it lasts 30 days)']],
        ['ticket_number'],
        ['[{{ticket_number}}] Please confirm your request',
         "{{greeting}}\n\nA request was sent from our help page with your email address and logged as **{{ticket_number}}** (subject: {{subject}}).\n\n**Was this you?** Confirm it here so it is filed under your account: {{confirm_link}}\n\nIf you did not send it, you can ignore this email — nothing is filed under your account and the help desk will review it."],
        ['[{{ticket_number}}] Confirme su solicitud',
         "{{greeting}}\n\nSe envió una solicitud desde nuestra página de ayuda con su dirección de correo, registrada como **{{ticket_number}}** (asunto: {{subject}}).\n\n**¿Fue usted?** Confírmela aquí para que quede en su cuenta: {{confirm_link}}\n\nSi no la envió, puede ignorar este correo: no se registra nada en su cuenta y el equipo de soporte la revisará."]],
    'ticket_reply' => ['Tickets', 'Reply from the help desk', 'Sent to the requester when an agent posts a reply on their ticket.',
        'The requester', 'mail_notify_reply',
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'agent_name' => ['Jordan Lee', 'Who replied'], 'comment' => ["I've reset your network profile — please restart and try again.", 'The reply text']],
        ['ticket_number'],
        ['[{{ticket_number}}] Update on your request: {{subject}}',
         "{{greeting}}\n\nThere's an update on your request **{{ticket_number}}** from {{agent_name}}:\n\n{{comment}}\n\nReply to this email (keep **{{ticket_number}}** in the subject) to respond.\n\n{{portal_link}}"],
        ['[{{ticket_number}}] Actualización de su solicitud: {{subject}}',
         "{{greeting}}\n\nHay una actualización en su solicitud **{{ticket_number}}** de parte de {{agent_name}}:\n\n{{comment}}\n\nResponda a este correo (mantenga **{{ticket_number}}** en el asunto) para contestar.\n\n{{portal_link}}"]],
    'ticket_status' => ['Tickets', 'Status changed', 'Sent to the requester when their ticket moves to a new status (other than resolved / closed).',
        'The requester', 'mail_notify_status',
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'status' => ['In progress', 'New status, in the recipient\'s language'], 'agent_name' => ['Jordan Lee', 'Who changed it']],
        ['ticket_number'],
        ['[{{ticket_number}}] Status updated to {{status}}: {{subject}}',
         "{{greeting}}\n\nYour request **{{ticket_number}}** — {{subject}} — is now **{{status}}**.\n\nReply to this email (keep **{{ticket_number}}** in the subject) to add information.\n\n{{portal_link}}"],
        ['[{{ticket_number}}] Estado actualizado a {{status}}: {{subject}}',
         "{{greeting}}\n\nSu solicitud **{{ticket_number}}** — {{subject}} — ahora está **{{status}}**.\n\nResponda a este correo (mantenga **{{ticket_number}}** en el asunto) para añadir información.\n\n{{portal_link}}"]],
    'ticket_resolved' => ['Tickets', 'Resolved / closed', 'Sent to the requester when their ticket is resolved or closed.',
        'The requester', 'mail_notify_status',
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'status' => ['Resolved', 'Resolved or Closed, in the recipient\'s language'], 'agent_name' => ['Jordan Lee', 'Who resolved it'],
         'resolution_note' => ['What we did: Cleared the saved Office sign-in and signed you in again.', 'BT75: the resolution note, as a line starting "What we did:" (empty when there is none)'],
         'reopen_line' => ['Not fixed? Reply to this email within 3 business days and the ticket reopens. After that it closes on its own.', 'BT75: how to reopen, with the business days set under SLA & Hours (for Closed: how to get more help)'],
         'csat_line' => ['**How did we do?** [Happy with how it was handled](https://opshub.example.org/rate.html?t=…&r=happy) · [Not happy](https://opshub.example.org/rate.html?t=…&r=unhappy)', 'BT81: two links to rate the ticket (empty when ratings are off in Settings → SLA & Hours)']],
        ['ticket_number'],
        ['[{{ticket_number}}] {{status}}: {{subject}}',
         "{{greeting}}\n\nYour request **{{ticket_number}}** — {{subject}} — has been marked **{{status}}**.\n\n{{resolution_note}}\n\n{{reopen_line}}\n\n{{csat_line}}\n\n{{portal_link}}"],
        ['[{{ticket_number}}] {{status}}: {{subject}}',
         "{{greeting}}\n\nSu solicitud **{{ticket_number}}** — {{subject}} — fue marcada como **{{status}}**.\n\n{{resolution_note}}\n\n{{reopen_line}}\n\n{{csat_line}}\n\n{{portal_link}}"]],
    'ticket_assigned' => ['Tickets', 'Assigned to an agent', 'Sent to the agent a ticket is assigned to.',
        'The assignee', 'mail_notify_assign',
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'assigned_by' => ['Jordan Lee', 'Who assigned it'], 'priority' => ['High', 'Priority, in the recipient\'s language'], 'link' => $T['link']],
        ['ticket_number'],
        ['[{{ticket_number}}] Assigned to you: {{subject}}',
         "{{greeting}}\n\nTicket **{{ticket_number}}** has been assigned to you by {{assigned_by}}.\n\n**Subject:** {{subject}}\n**Priority:** {{priority}}\n\n[Open {{app_name}}]({{link}}) to work it."],
        ['[{{ticket_number}}] Asignado a usted: {{subject}}',
         "{{greeting}}\n\n{{assigned_by}} le asignó el ticket **{{ticket_number}}**.\n\n**Asunto:** {{subject}}\n**Prioridad:** {{priority}}\n\n[Abra {{app_name}}]({{link}}) para atenderlo."]],
    'ticket_backup' => ['Tickets', 'Backup on a ticket', 'Sent to the backup person of a ticket (from the category routing or chosen on the ticket). The backup also receives replies and deadline alerts.',
        'The backup', 'mail_notify_assign',
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'owner_name' => ['Ana Torres', 'Who owns the ticket'], 'assigned_by' => ['automatic routing', 'Who set it'], 'priority' => ['High', 'Priority, in the recipient\'s language'], 'link' => $T['link']],
        ['ticket_number'],
        ['[{{ticket_number}}] You are the backup: {{subject}}',
         "{{greeting}}\n\nYou are the **backup** on ticket **{{ticket_number}}** (owner: {{owner_name}}). You will also receive its replies and deadline alerts — step in if the owner is away.\n\n**Subject:** {{subject}}\n**Priority:** {{priority}}\n\n[Open {{app_name}}]({{link}})."],
        ['[{{ticket_number}}] Usted es respaldo: {{subject}}',
         "{{greeting}}\n\nUsted es el **respaldo** del ticket **{{ticket_number}}** (responsable: {{owner_name}}). También recibirá sus respuestas y alertas de plazo — intervenga si el responsable no está.\n\n**Asunto:** {{subject}}\n**Prioridad:** {{priority}}\n\n[Abra {{app_name}}]({{link}})."]],
    'ticket_new_unassigned' => ['Tickets', 'New ticket — nobody assigned (to the alert list)', 'Sent to the "new ticket" alert list (Ticket Categories → Default routing; Super Admins if the list is empty) when a new ticket could not be assigned to anyone — or for every new ticket if that option is on.',
        'The alert list', null,
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'requester' => ['Alex Sample <alex@example.org>', 'Who opened it'], 'channel' => ['Email', 'How it arrived: the app, Email, Public ticket page, Intake form'], 'priority' => ['Medium', 'Priority'], 'owner_status' => ['Nobody is assigned yet', 'Assignment status'], 'link' => $T['link']],
        ['ticket_number'],
        ['[{{ticket_number}}] New ticket: {{subject}}',
         "{{greeting}}\n\nA new ticket arrived.\n\n**Ticket:** {{ticket_number}}\n**Subject:** {{subject}}\n**From:** {{requester}} ({{channel}})\n**Priority:** {{priority}}\n**Assignment:** {{owner_status}}\n\n[Open {{app_name}}]({{link}}) to assign it. Tip: give its category an owner and a backup under Ticket Categories so this is automatic."],
        ['[{{ticket_number}}] Nuevo ticket: {{subject}}',
         "{{greeting}}\n\nLlegó un ticket nuevo.\n\n**Ticket:** {{ticket_number}}\n**Asunto:** {{subject}}\n**De:** {{requester}} ({{channel}})\n**Prioridad:** {{priority}}\n**Asignación:** {{owner_status}}\n\n[Abra {{app_name}}]({{link}}) para asignarlo. Consejo: asigne un responsable y un respaldo a su categoría en Categorías de tickets para que sea automático."]],
    'ticket_requester_reply' => ['Tickets', 'Requester replied (to the agent)', 'Sent to the assigned agent when the requester (or anyone else on the ticket) adds a comment — in the app or by replying to an email.',
        'The assignee', null,
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'author_name' => ['Alex Sample', 'Who wrote the comment'], 'comment' => ["I restarted but it still won't connect. Can you call me?", 'The comment text'], 'link' => $T['link']],
        ['ticket_number'],
        ['[{{ticket_number}}] New reply from {{author_name}}: {{subject}}',
         "{{greeting}}\n\n{{author_name}} added a reply to ticket **{{ticket_number}}**:\n\n{{comment}}\n\n[Open the ticket in {{app_name}}]({{link}}) to answer."],
        ['[{{ticket_number}}] Nueva respuesta de {{author_name}}: {{subject}}',
         "{{greeting}}\n\n{{author_name}} respondió en el ticket **{{ticket_number}}**:\n\n{{comment}}\n\n[Abra el ticket en {{app_name}}]({{link}}) para contestar."]],
    // ───────────────────────────── Cases (BT77) ─────────────────────────────
    'case_assigned' => ['Cases', 'Case given to you', 'Sent to the person who becomes the owner of a case (when someone else makes them the owner).',
        'The case owner', null,
        ['case_number' => ['CS-2026-0001', 'Case number'], 'case_title' => ['Onboard Jordan Ellis (Billing)', 'Case title'], 'assigned_by' => ['Dana Whitfield', 'Who made you the owner'],
         'target' => ['Oct 23, 2026', 'Target date, in the recipient\'s language (empty when none)'], 'link' => $L['link']],
        ['case_number'],
        ['[{{case_number}}] You own this case: {{case_title}}',
         "{{greeting}}\n\n{{assigned_by}} made you the owner of case **{{case_number}}** — {{case_title}}.\n\n**Target date:** {{target}}\n\n[Open the case in {{app_name}}]({{link}}) to plan its tasks."],
        ['[{{case_number}}] Usted es responsable de este caso: {{case_title}}',
         "{{greeting}}\n\n{{assigned_by}} lo nombró responsable del caso **{{case_number}}** — {{case_title}}.\n\n**Fecha objetivo:** {{target}}\n\n[Abra el caso en {{app_name}}]({{link}}) para planificar sus tareas."]],
    'case_task_assigned' => ['Cases', 'Case task given to you', 'Sent to the person a case task (or a meeting) is given to, and to the requester when a step is added for them.',
        'The task\'s person', null,
        ['case_number' => ['CS-2026-0001', 'Case number'], 'case_title' => ['Onboard Jordan Ellis (Billing)', 'Case title'], 'task' => ['Image laptop LT-104', 'The task'],
         'due' => ['Oct 16, 2026', 'Due date or meeting time, in the recipient\'s language'], 'after' => ['It starts after "Approve the billing licence"; you will be told when it can start.', 'What it waits for (empty when it can start now)'],
         'assigned_by' => ['Dana Whitfield', 'Who added it'], 'link' => $L['link']],
        ['case_number'],
        ['[{{case_number}}] For you: {{task}}',
         "{{greeting}}\n\n{{assigned_by}} gave you a task in case **{{case_number}}** — {{case_title}}:\n\n**{{task}}**\n**Due:** {{due}}\n\n{{after}}\n\n[Open it in {{app_name}}]({{link}})."],
        ['[{{case_number}}] Para usted: {{task}}',
         "{{greeting}}\n\n{{assigned_by}} le asignó una tarea en el caso **{{case_number}}** — {{case_title}}:\n\n**{{task}}**\n**Fecha:** {{due}}\n\n{{after}}\n\n[Ábrala en {{app_name}}]({{link}})."]],
    'case_task_unblocked' => ['Cases', 'Your case task can start', 'Sent to the person of a task when the task it waited for is done.',
        'The task\'s person', null,
        ['case_number' => ['CS-2026-0001', 'Case number'], 'case_title' => ['Onboard Jordan Ellis (Billing)', 'Case title'], 'task' => ['Create the Microsoft 365 account', 'The task'],
         'done_task' => ['Approve the billing licence', 'The task that was finished'], 'due' => ['Oct 22, 2026', 'Due date, in the recipient\'s language'], 'link' => $L['link']],
        ['case_number'],
        ['[{{case_number}}] You can start: {{task}}',
         "{{greeting}}\n\n\"{{done_task}}\" is done, so your task in case **{{case_number}}** — {{case_title}} — can start:\n\n**{{task}}**\n**Due:** {{due}}\n\n[Open it in {{app_name}}]({{link}})."],
        ['[{{case_number}}] Ya puede empezar: {{task}}',
         "{{greeting}}\n\n\"{{done_task}}\" está terminada, así que su tarea en el caso **{{case_number}}** — {{case_title}} — puede empezar:\n\n**{{task}}**\n**Fecha:** {{due}}\n\n[Ábrala en {{app_name}}]({{link}})."]],
    'case_approval_needed' => ['Cases', 'Approval waiting for you', 'Sent to the approver when an approval in a case is ready for their answer.',
        'The approver', null,
        ['case_number' => ['CS-2026-0001', 'Case number'], 'case_title' => ['Onboard Jordan Ellis (Billing)', 'Case title'], 'task' => ['Approve the billing-system licence', 'What to approve'],
         'due' => ['Oct 19, 2026', 'By when, in the recipient\'s language'], 'owner_name' => ['Dana Whitfield', 'Who owns the case'], 'link' => $L['link']],
        ['case_number'],
        ['[{{case_number}}] Approval needed: {{task}}',
         "{{greeting}}\n\nYour approval is needed in case **{{case_number}}** — {{case_title}}:\n\n**{{task}}**\n**By:** {{due}}\n\n[Open the case in {{app_name}}]({{link}}) and choose Approve or Reject. Questions: {{owner_name}} owns the case."],
        ['[{{case_number}}] Se necesita su aprobación: {{task}}',
         "{{greeting}}\n\nSe necesita su aprobación en el caso **{{case_number}}** — {{case_title}}:\n\n**{{task}}**\n**Para:** {{due}}\n\n[Abra el caso en {{app_name}}]({{link}}) y elija Aprobar o Rechazar. Preguntas: {{owner_name}} es responsable del caso."]],
    'case_digest' => ['Cases', 'Daily case digest', 'Sent each business morning to people with case work: their tasks and approvals (late, due today, coming up), today\'s meetings, and for owners and watchers how their cases are going. Nothing is sent to someone with nothing to report.',
        'People with case work', null,
        ['date' => ['Oct 15, 2026', 'Date of the digest'], 'count' => ['3', 'How many tasks and approvals wait for the recipient'],
         'report' => ["**Waiting on you**\n- Late: Image laptop LT-104 (CS-2026-0001, due Oct 14)\n\n**Your cases**\n- CS-2026-0001 Onboard Jordan Ellis — on track, 1 of 4 done", 'The generated lists, in the recipient\'s language'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Your cases today — {{date}}', "{{greeting}}\n\n{{report}}\n\n[Open Cases in {{app_name}}]({{link}})"],
        ['[{{app_name}}] Sus casos hoy — {{date}}', "{{greeting}}\n\n{{report}}\n\n[Abra Casos en {{app_name}}]({{link}})"]],
    // ───────────────────────────── SLA ─────────────────────────────
    'sla_breach' => ['SLA & digests', 'SLA breached', 'Sent to the assignee (or admins if unassigned) when a response or resolution SLA is missed.',
        'Assignee or admins', null,
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'sla_type' => ['Response', 'Response or Resolution, in the recipient\'s language'], 'priority' => ['High', 'Priority'], 'due' => ['Sep 24, 2026 10:00 AM', 'When it was due'], 'link' => $T['link']],
        ['ticket_number'],
        ['[{{app_name}}] {{sla_type}} SLA breached — {{ticket_number}}',
         "{{greeting}}\n\nThe **{{sla_type}}** SLA for ticket **{{ticket_number}}** was due {{due}} and has been breached.\n\n**Subject:** {{subject}}\n**Priority:** {{priority}}\n\n[Open the ticket in {{app_name}}]({{link}}) to take action."],
        ['[{{app_name}}] SLA de {{sla_type}} incumplido — {{ticket_number}}',
         "{{greeting}}\n\nEl SLA de **{{sla_type}}** del ticket **{{ticket_number}}** vencía {{due}} y no se cumplió.\n\n**Asunto:** {{subject}}\n**Prioridad:** {{priority}}\n\n[Abra el ticket en {{app_name}}]({{link}}) para tomar acción."]],
    'sla_warning' => ['SLA & digests', 'SLA due soon', 'Sent to the assignee (or admins) shortly before a response or resolution SLA is due.',
        'Assignee or admins', null,
        ['ticket_number' => $T['ticket_number'], 'subject' => $T['subject'], 'sla_type' => ['Resolution', 'Response or Resolution'], 'priority' => ['High', 'Priority'], 'due' => ['Sep 24, 2026 16:00', 'When it is due'], 'link' => $T['link']],
        ['ticket_number'],
        ['[{{app_name}}] {{sla_type}} SLA due soon — {{ticket_number}}',
         "{{greeting}}\n\nThe **{{sla_type}}** SLA for ticket **{{ticket_number}}** is due **{{due}}**.\n\n**Subject:** {{subject}}\n**Priority:** {{priority}}\n\n[Open the ticket in {{app_name}}]({{link}})."],
        ['[{{app_name}}] SLA de {{sla_type}} por vencer — {{ticket_number}}',
         "{{greeting}}\n\nEl SLA de **{{sla_type}}** del ticket **{{ticket_number}}** vence **{{due}}**.\n\n**Asunto:** {{subject}}\n**Prioridad:** {{priority}}\n\n[Abra el ticket en {{app_name}}]({{link}})."]],
    'digest_sla_weekly' => ['SLA & digests', 'Weekly SLA digest', 'Weekly SLA compliance summary for administrators.',
        'Administrators', null,
        ['date' => ['Sep 28, 2026', 'Date of the digest'], 'report' => ["Tickets created: 18\nTickets resolved: 21\nResponse SLA compliance: 94%", 'The generated summary (in the recipient\'s language)'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Weekly SLA digest — {{date}}', "{{greeting}}\n\nHere is the SLA summary for the last 7 days:\n\n{{report}}\n\n[Open Reports in {{app_name}}]({{link}})"],
        ['[{{app_name}}] Resumen semanal de SLA — {{date}}', "{{greeting}}\n\nEste es el resumen de SLA de los últimos 7 días:\n\n{{report}}\n\n[Abra Reportes en {{app_name}}]({{link}})"]],
    'digest_ops_weekly' => ['SLA & digests', 'Weekly operations digest', 'Weekly roll-up of tickets, onboarding, alerts, KB and equipment for administrators.',
        'Administrators', null,
        ['date' => ['Sep 28, 2026', 'Date of the digest'], 'report' => ["Open tickets: 12 (SLA-breached: 1)\nEquipment on loan: 9 (overdue: 0)", 'The generated summary'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Weekly operations digest — {{date}}', "{{greeting}}\n\nHere is this week's operations summary:\n\n{{report}}\n\n[Open {{app_name}}]({{link}})"],
        ['[{{app_name}}] Resumen semanal de operaciones — {{date}}', "{{greeting}}\n\nEste es el resumen de operaciones de esta semana:\n\n{{report}}\n\n[Abra {{app_name}}]({{link}})"]],
    'digest_alerts_daily' => ['SLA & digests', 'Daily alert digest', 'Daily list of open monitoring alerts for administrators.',
        'Administrators', null,
        ['date' => ['Sep 24, 2026', 'Date of the digest'], 'alert_count' => ['3', 'Number of open alerts'], 'report' => ["- [ERROR] Backup failed (whm, since 2026-09-23 02:00)", 'The list of open alerts'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Daily alert digest — {{alert_count}} open', "{{greeting}}\n\nThere are **{{alert_count}}** open alert(s):\n\n{{report}}\n\n[Open Server Guardian]({{link}})"],
        ['[{{app_name}}] Resumen diario de alertas — {{alert_count}} abiertas', "{{greeting}}\n\nHay **{{alert_count}}** alerta(s) abierta(s):\n\n{{report}}\n\n[Abra Server Guardian]({{link}})"]],
    'uptime_alert' => ['SLA & digests', 'Uptime alert', 'Sent to administrators when a monitored website or cPanel account goes down, comes back, or its certificate is about to expire (IT Operations → Uptime).',
        'Administrators', null,
        ['monitor' => ['example.org', 'The monitor'], 'url' => ['https://example.org/', 'Its address'], 'state' => ['down', 'down / back up after 12 minutes / certificate expires …'], 'state_es' => ['caído', 'The state in Spanish'], 'detail' => ['Since Oct 10, 2026 3:46 PM. No answer.', 'What happened'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] {{monitor}} is {{state}}', "{{greeting}}\n\n**{{monitor}}** ({{url}}) is **{{state}}**.\n\n{{detail}}\n\n[Open Uptime in {{app_name}}]({{link}})"],
        ['[{{app_name}}] {{monitor}}: {{state_es}}', "{{greeting}}\n\n**{{monitor}}** ({{url}}): **{{state_es}}**.\n\n{{detail}}\n\n[Abra Disponibilidad en {{app_name}}]({{link}})"]],
    'server_security_alert' => ['SLA & digests', 'Server security alert', 'Sent to administrators, when switched on in Server performance → Details → Security, for a sign-in to vTiger or this app from outside the allowed countries, and for unusual traffic on the server (BT164).',
        'Administrators', null,
        ['subject_line' => ['Sign-in from Colombia: Ana Rivera', 'What was found'], 'detail' => ['Ana Rivera (ana) — vTiger — from Bogotá, Colombia, network Claro — 203.0.113.9 — 10/29/2026 3:12 PM', 'The details, one finding per line'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] {{subject_line}}', "{{greeting}}\n\n**{{subject_line}}**\n\n{{detail}}\n\n[Open Server performance in {{app_name}}]({{link}})"],
        ['[{{app_name}}] {{subject_line}}', "{{greeting}}\n\n**{{subject_line}}**\n\n{{detail}}\n\n[Abra Rendimiento del servidor en {{app_name}}]({{link}})"]],
    'backup_alert' => ['SLA & digests', 'Backup alert', 'Sent to administrators when a monitored backup fails, is late or is turned off, and when it works again (IT Operations → Backups).',
        'Administrators', null,
        ['backup' => ['WHM backups', 'The backup'], 'state' => ['late', 'failed / late / turned off / working again'], 'state_es' => ['atrasado', 'The state in Spanish'], 'detail' => ['No successful backup since Oct 9, 2026 3:12 AM (expected every 24 h + 120 min grace).', 'What happened'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Backup {{state}}: {{backup}}', "{{greeting}}\n\nThe backup **{{backup}}** is **{{state}}**.\n\n{{detail}}\n\n[Open Backups in {{app_name}}]({{link}})"],
        ['[{{app_name}}] Respaldo {{state_es}}: {{backup}}', "{{greeting}}\n\nEl respaldo **{{backup}}**: **{{state_es}}**.\n\n{{detail}}\n\n[Abra Respaldos en {{app_name}}]({{link}})"]],
    'cron_job_alert' => ['SLA & digests', 'Scheduled job alert', 'Sent to administrators when a scheduled job (cron) stops running, fails, or recovers (Logs → Scheduled jobs).',
        'Administrators', null,
        ['job' => ['Main scheduler (cron-sla.php)', 'The scheduled job'], 'state' => ['late — last ran 2 hours ago', 'What is wrong (or "running again")'], 'detail' => ['Expected every 15 minutes. Last run: Oct 10, 2026 9:15 AM, finished OK.', 'Details for the person who fixes it'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Scheduled job: {{job}} — {{state}}', "{{greeting}}\n\nThe scheduled job **{{job}}** is **{{state}}**.\n\n{{detail}}\n\n[Open Scheduled jobs in {{app_name}}]({{link}})"],
        ['[{{app_name}}] Tarea programada: {{job}} — {{state}}', "{{greeting}}\n\nLa tarea programada **{{job}}** está **{{state}}**.\n\n{{detail}}\n\n[Abra Tareas programadas en {{app_name}}]({{link}})"]],
    // ───────────────────────────── Reminders ─────────────────────────────
    'calendar_reminder' => ['Reminders', 'Calendar event reminder', 'Sent to the organizer and attendees of a calendar event at the reminder times set on the event.',
        'Organizer and attendees', null,
        ['event_title' => ['Server maintenance window', 'Event title'], 'event_type' => ['Maintenance window', 'Kind of event'], 'when' => ['Sat, Oct 17, 2026 10:00 PM – 11:30 PM', 'Date and time'], 'location' => ['Main Office', 'Where (may be empty)'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Reminder: {{event_title}} — {{when}}', "{{greeting}}\n\nReminder: **{{event_title}}** ({{event_type}})\n\n**When:** {{when}}\n**Where:** {{location}}\n\n[Open the calendar in {{app_name}}]({{link}})"],
        ['[{{app_name}}] Recordatorio: {{event_title}} — {{when}}', "{{greeting}}\n\nRecordatorio: **{{event_title}}** ({{event_type}})\n\n**Cuándo:** {{when}}\n**Dónde:** {{location}}\n\n[Abra el calendario en {{app_name}}]({{link}})"]],
    'contract_renewal_due' => ['Reminders', 'Contract renewal coming up', 'Sent to the contract owner when a contract enters its renewal-notice window.',
        'Contract owner (or admins)', null,
        ['contract_name' => ['Microsoft 365 E3 subscription', 'Contract title'], 'ref' => ['CT-0005-R1', 'Term reference'], 'end_date' => ['Sep 30, 2027', 'Term end date'], 'days_left' => ['60', 'Days until it ends'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Contract renewal due in {{days_left}} day(s): {{contract_name}}',
         "{{greeting}}\n\nThe contract **{{contract_name}}** ({{ref}}) ends on **{{end_date}}** — {{days_left}} day(s) from now.\n\nStart the renewal review in [{{app_name}}]({{link}}) under Contracts → Terms & renewals."],
        ['[{{app_name}}] Renovación de contrato en {{days_left}} día(s): {{contract_name}}',
         "{{greeting}}\n\nEl contrato **{{contract_name}}** ({{ref}}) vence el **{{end_date}}**, dentro de {{days_left}} día(s).\n\nInicie la revisión de renovación en [{{app_name}}]({{link}}), en Contratos → Términos y renovaciones."]],
    'contract_expired' => ['Reminders', 'Contract expired', 'Sent to the contract owner when a contract term passes its end date.',
        'Contract owner (or admins)', null,
        ['contract_name' => ['Microsoft 365 E3 subscription', 'Contract title'], 'ref' => ['CT-0005', 'Term reference'], 'end_date' => ['Sep 30, 2026', 'Term end date'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Contract expired: {{contract_name}}',
         "{{greeting}}\n\nThe contract **{{contract_name}}** ({{ref}}) expired on **{{end_date}}**.\n\nRenew it or close the term in [{{app_name}}]({{link}})."],
        ['[{{app_name}}] Contrato vencido: {{contract_name}}',
         "{{greeting}}\n\nEl contrato **{{contract_name}}** ({{ref}}) venció el **{{end_date}}**.\n\nRenuévelo o cierre el término en [{{app_name}}]({{link}})."]],
    'cred_rotation_due' => ['Reminders', 'Credential rotation coming up', 'Sent to the credential owner 14 days before a rotation is due.',
        'Credential owner (or admins)', null,
        ['credential_name' => ['Anthropic Claude API', 'Credential name'], 'due_date' => ['Oct 1, 2026', 'Rotation due date'], 'days_left' => ['14', 'Days remaining'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Credential rotation due in {{days_left}} day(s): {{credential_name}}',
         "{{greeting}}\n\nThe credential **{{credential_name}}** should be rotated by **{{due_date}}** ({{days_left}} day(s) away).\n\nRotate it and update the record in [{{app_name}}]({{link}})."],
        ['[{{app_name}}] Rotación de credencial en {{days_left}} día(s): {{credential_name}}',
         "{{greeting}}\n\nLa credencial **{{credential_name}}** debe rotarse antes del **{{due_date}}** (faltan {{days_left}} día(s)).\n\nRótela y actualice el registro en [{{app_name}}]({{link}})."]],
    'cred_rotation_overdue' => ['Reminders', 'Credential rotation overdue', 'Sent to the credential owner when a rotation date has passed.',
        'Credential owner (or admins)', null,
        ['credential_name' => ['Anthropic Claude API', 'Credential name'], 'due_date' => ['Sep 1, 2026', 'Rotation due date'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Credential rotation overdue: {{credential_name}}',
         "{{greeting}}\n\nThe credential **{{credential_name}}** was due for rotation on **{{due_date}}**.\n\nPlease rotate it and update the record in [{{app_name}}]({{link}})."],
        ['[{{app_name}}] Rotación de credencial vencida: {{credential_name}}',
         "{{greeting}}\n\nLa credencial **{{credential_name}}** debía rotarse el **{{due_date}}**.\n\nRótela y actualice el registro en [{{app_name}}]({{link}})."]],
    'std_review_due' => ['Reminders', 'Review due soon', 'Sent to the requirement owner 14 days before a review is due.',
        'Requirement owner (or admins)', null,
        ['requirement' => ['2.1 Health and safety inspections', 'requirement'], 'due_date' => ['Oct 8, 2026', 'Review due date'], 'days_left' => ['14', 'Days remaining'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Review due soon: {{requirement}}',
         "{{greeting}}\n\nThe standards review for **{{requirement}}** is due on **{{due_date}}** ({{days_left}} day(s)).\n\n[Open Compliance in {{app_name}}]({{link}})."],
        ['[{{app_name}}] Revisión próxima: {{requirement}}',
         "{{greeting}}\n\nLa revisión de acreditación de **{{requirement}}** vence el **{{due_date}}** (en {{days_left}} día(s)).\n\n[Abra Cumplimiento en {{app_name}}]({{link}})."]],
    'std_review_overdue' => ['Reminders', 'Review overdue', 'Sent to the requirement owner when a review date has passed.',
        'Requirement owner (or admins)', null,
        ['requirement' => ['2.1 Health and safety inspections', 'requirement'], 'due_date' => ['Sep 10, 2026', 'Review due date'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Review overdue: {{requirement}}',
         "{{greeting}}\n\nThe standards review for **{{requirement}}** was due on **{{due_date}}** and is overdue.\n\n[Open Compliance in {{app_name}}]({{link}})."],
        ['[{{app_name}}] Revisión vencida: {{requirement}}',
         "{{greeting}}\n\nLa revisión de acreditación de **{{requirement}}** vencía el **{{due_date}}** y está atrasada.\n\n[Abra Cumplimiento en {{app_name}}]({{link}})."]],
    'sop_review_due' => ['Reminders', 'SOP review due soon', 'Sent to the SOP owner 14 days before its next review date (admins when it has no owner).',
        'SOP owner (or admins)', null,
        ['sop_title' => ['Incident Response Runbook', 'SOP title'], 'due_date' => ['Oct 13, 2026', 'Review due date'], 'days_left' => ['14', 'Days remaining'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] SOP review due soon: {{sop_title}}',
         "{{greeting}}\n\nThe SOP **{{sop_title}}** is due for review on **{{due_date}}** ({{days_left}} day(s)). You are its owner: check it is still correct, update it if needed, and set the next review date.\n\n[Open SOPs in {{app_name}}]({{link}})."],
        ['[{{app_name}}] Revisión de SOP próxima: {{sop_title}}',
         "{{greeting}}\n\nEl SOP **{{sop_title}}** debe revisarse el **{{due_date}}** (en {{days_left}} día(s)). Usted es el responsable: verifique que siga correcto, actualícelo si hace falta y fije la próxima fecha de revisión.\n\n[Abra los SOP en {{app_name}}]({{link}})."]],
    'sop_review_overdue' => ['Reminders', 'SOP review overdue', 'Sent to the SOP owner when its review date has passed (admins when it has no owner).',
        'SOP owner (or admins)', null,
        ['sop_title' => ['Incident Response Runbook', 'SOP title'], 'due_date' => ['Sep 13, 2026', 'Review due date'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] SOP review overdue: {{sop_title}}',
         "{{greeting}}\n\nThe SOP **{{sop_title}}** was due for review on **{{due_date}}** and is overdue. You are its owner.\n\n[Open SOPs in {{app_name}}]({{link}})."],
        ['[{{app_name}}] Revisión de SOP vencida: {{sop_title}}',
         "{{greeting}}\n\nEl SOP **{{sop_title}}** debía revisarse el **{{due_date}}** y está atrasado. Usted es el responsable.\n\n[Abra los SOP en {{app_name}}]({{link}})."]],
    'sop_ack_reminder' => ['Reminders', 'SOP acknowledgment reminder (all SOPs)', 'Sent by "Remind everyone" on the SOP compliance screen to people with unacknowledged SOPs.',
        'Staff with pending SOPs', null,
        ['count' => ['3', 'Number of SOPs waiting'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] SOP acknowledgment reminder',
         "{{greeting}}\n\nYou have **{{count}}** SOP(s) waiting for your acknowledgment. Please review and acknowledge them in [{{app_name}}]({{link}})."],
        ['[{{app_name}}] Recordatorio de confirmación de SOP',
         "{{greeting}}\n\nTiene **{{count}}** SOP pendiente(s) de su confirmación de lectura. Revíselos y confírmelos en [{{app_name}}]({{link}})."]],
    'sop_ack_request' => ['Reminders', 'Please acknowledge an SOP', 'Sent by "Remind" on one SOP to the people in its audience who haven\'t acknowledged its current version.',
        'Staff who haven\'t acknowledged', null,
        ['sop_title' => ['Incident Response Runbook', 'SOP title'], 'version' => ['4', 'Current version'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Please acknowledge SOP: {{sop_title}}',
         "{{greeting}}\n\nThe SOP **{{sop_title}}** (version {{version}}) needs your acknowledgment in [{{app_name}}]({{link}})."],
        ['[{{app_name}}] Confirme la lectura del SOP: {{sop_title}}',
         "{{greeting}}\n\nEl SOP **{{sop_title}}** (versión {{version}}) requiere su confirmación de lectura en [{{app_name}}]({{link}})."]],
    // ───────────────────────────── Security rollout (Business Premium) ─────────────────────────────
    'rollout_announce' => ['Security rollout', 'Rollout: leadership announcement', 'Sent to all staff about 2 weeks before kickoff, signed by a leader. Explains why, what changes, that personal devices are voluntary and never managed, the sign-in choices and the timeline.',
        'All staff', null,
        ['start_date' => ['Oct 12, 2026', 'Kickoff date — the day Phase 1 (sign-in approval setup) begins'], 'sender_name' => ['Ana Torres, Executive Director', 'Leader signing the announcement (name and title)']],
        [],
        ["Coming soon: stronger protection for your {{org_name}} account",
         "{{greeting}}\n\nStarting **{{start_date}}**, {{org_name}} is upgrading to **Microsoft 365 Business Premium**. It adds the security tools we need to protect the people we serve, our funding and our accreditation — and to protect you from scams and stolen passwords.\n\n**The one thing everyone does**\n\n- Add a second step to your work sign-in (called MFA), so a stolen password alone can't open your account. You choose how: the free **Microsoft Authenticator** app, a **security key** that {{org_name}} provides (no phone needed), or a text message.\n\n**What else changes**\n\n- Company computers get connected to our management system so they stay updated and protected.\n- On a personal computer, we recommend working in the web browser.\n\n**Your personal phone and computer are your choice**\n\n- Using a personal phone or computer for work is **voluntary**. You are never required to install anything on a personal device.\n- Personal devices are **never** enrolled or managed by {{org_name}}. IT cannot see personal files, photos, messages, browsing or location.\n- If you choose to use the Outlook or Teams app on your phone, protection applies only inside those work apps.\n\n**Timeline**\n\n- Weeks 1–2: set up your sign-in approval (about 10 minutes).\n- Weeks 3–8: company computers are connected in small groups.\n- Weeks 8–9: the new rules take full effect.\n\nYou don't need to do anything today. Each step will come in its own short email with clear instructions and a deadline from {{helpdesk_name}}.\n\nQuestions or concerns? Just reply to this email and {{helpdesk_name}} will help.\n\nThank you for helping keep {{org_name}} safe.\n\n— {{sender_name}}"],
        ["Próximamente: más protección para su cuenta de {{org_name}}",
         "{{greeting}}\n\nA partir del **{{start_date}}**, {{org_name}} pasará a **Microsoft 365 Business Premium**. Incluye las herramientas de seguridad que necesitamos para proteger a las personas que atendemos, nuestros fondos y nuestra acreditación, y para protegerle a usted de estafas y contraseñas robadas.\n\n**Lo único que todos haremos**\n\n- Agregar un segundo paso al iniciar sesión en el trabajo (llamado MFA), para que una contraseña robada no baste para abrir su cuenta. Usted elige cómo: la app gratuita **Microsoft Authenticator**, una **llave de seguridad** que {{org_name}} le entrega (no necesita teléfono) o un mensaje de texto.\n\n**Qué más cambia**\n\n- Las computadoras de la empresa se conectarán a nuestro sistema de administración para mantenerse actualizadas y protegidas.\n- En una computadora personal, recomendamos trabajar en el navegador web.\n\n**Su teléfono y su computadora personales son decisión suya**\n\n- Usar un teléfono o una computadora personal para el trabajo es **voluntario**. Nunca se le pedirá instalar nada en un dispositivo personal.\n- Los dispositivos personales **nunca** se inscriben ni son administrados por {{org_name}}. TI no puede ver archivos, fotos, mensajes, navegación ni ubicación personales.\n- Si decide usar la app de Outlook o Teams en su teléfono, la protección aplica solo dentro de esas apps del trabajo.\n\n**Calendario**\n\n- Semanas 1–2: configure la aprobación de inicio de sesión (unos 10 minutos).\n- Semanas 3–8: las computadoras de la empresa se conectan en grupos pequeños.\n- Semanas 8–9: las nuevas reglas entran en pleno vigor.\n\nHoy no necesita hacer nada. Cada paso llegará en su propio correo breve de {{helpdesk_name}}, con instrucciones claras y una fecha límite.\n\n¿Preguntas o inquietudes? Responda a este correo y {{helpdesk_name}} le ayudará.\n\nGracias por ayudar a mantener seguro a {{org_name}}.\n\n— {{sender_name}}"]],
    'rollout_manager_brief' => ['Security rollout', 'Rollout: manager briefing', 'Sent to managers and supervisors before the all-staff announcement: their role, talking points (including that personal devices are voluntary), the timeline and how to escalate.',
        'Managers and supervisors', null,
        ['start_date' => ['Oct 12, 2026', 'Kickoff date — the day Phase 1 (sign-in approval setup) begins']],
        [],
        ["For managers: your part in the Microsoft 365 security rollout",
         "{{greeting}}\n\nOn **{{start_date}}** we start rolling out Microsoft 365 Business Premium security at {{org_name}}. Your team will take its cues from you, so here is what we're asking of managers.\n\n**Your role**\n\n- Go first: set up your sign-in approval in week 1, so you can speak from experience.\n- Give your team 10–15 minutes of work time for each step.\n- Tell us who works on a personal computer or on a company computer running Windows Home, so we put them in the right group.\n- Tell us who would prefer a **security key** instead of using their personal phone — we'll provide one.\n- Send anyone who is stuck to {{helpdesk_name}} — you don't need to fix anything yourself.\n\n**Talking points**\n\n- This protects our clients' information, our funding and each of us from scams and stolen passwords.\n- The only required step is the second sign-in step (MFA). People choose how: Authenticator app, a company security key, or a text message.\n- Using a personal phone or computer for work is voluntary. Please don't ask anyone to install work apps on a personal device.\n- Personal devices are never enrolled. IT cannot see personal files, photos, messages, browsing or location.\n- Only approve a sign-in you started yourself. IT will never ask for your password or an approval code.\n\n**Please avoid** expecting replies to email or Teams after hours from staff who don't use work apps on their phone. If hourly staff do work after hours, it may count as paid time — check with HR.\n\n**Timeline**\n\n- Weeks 1–2: everyone sets up sign-in approval; then it becomes required.\n- Weeks 3–4: a small pilot group tests company computers.\n- Weeks 5–8: company computers are connected in waves of 10–20 people a week.\n- Weeks 8–9: the rules for company computers take full effect (7 days to fix a problem before access is limited).\n\n**How to escalate:** reply to this email for anything about the rollout. If someone can't sign in, lost their phone or lost their security key, contact {{helpdesk_name}} right away at {{helpdesk_email}} — we can give them a temporary pass the same day.\n\nThank you for leading by example."],
        ["Para supervisores: su papel en la implementación de seguridad de Microsoft 365",
         "{{greeting}}\n\nEl **{{start_date}}** comenzamos a implementar la seguridad de Microsoft 365 Business Premium en {{org_name}}. Su equipo seguirá su ejemplo, así que esto es lo que les pedimos a los supervisores.\n\n**Su papel**\n\n- Dé el primer paso: configure su aprobación de inicio de sesión en la semana 1 para poder hablar desde su experiencia.\n- Dé a su equipo de 10 a 15 minutos de tiempo de trabajo para cada paso.\n- Avísenos quién trabaja en una computadora personal o en una computadora de la empresa con Windows Home, para ubicarle en el grupo correcto.\n- Avísenos quién prefiere una **llave de seguridad** en lugar de usar su teléfono personal; se la proporcionaremos.\n- Envíe a quien tenga problemas con {{helpdesk_name}}; usted no necesita resolver nada.\n\n**Puntos clave para su equipo**\n\n- Esto protege la información de nuestros clientes, nuestros fondos y a cada uno de nosotros de estafas y contraseñas robadas.\n- El único paso obligatorio es el segundo paso de inicio de sesión (MFA). Cada persona elige cómo: la app Authenticator, una llave de seguridad de la empresa o un mensaje de texto.\n- Usar un teléfono o una computadora personal para el trabajo es voluntario. Por favor, no le pida a nadie instalar apps del trabajo en un dispositivo personal.\n- Los dispositivos personales nunca se inscriben. TI no puede ver archivos, fotos, mensajes, navegación ni ubicación personales.\n- Apruebe solo un inicio de sesión que usted mismo(a) inició. TI nunca le pedirá su contraseña ni un código de aprobación.\n\n**Evite** esperar respuestas por correo o Teams fuera del horario de parte de quienes no usan apps del trabajo en su teléfono. Si el personal por hora trabaja fuera de horario, ese tiempo puede ser tiempo pagado; consulte con Recursos Humanos.\n\n**Calendario**\n\n- Semanas 1–2: todos configuran la aprobación de inicio de sesión; luego se vuelve obligatoria.\n- Semanas 3–4: un grupo piloto pequeño prueba las computadoras de la empresa.\n- Semanas 5–8: las computadoras de la empresa se conectan en grupos de 10 a 20 personas por semana.\n- Semanas 8–9: las reglas para las computadoras de la empresa entran en pleno vigor (7 días para corregir un problema antes de limitar el acceso).\n\n**Cómo escalar:** responda a este correo para cualquier asunto de la implementación. Si alguien no puede iniciar sesión, perdió su teléfono o su llave de seguridad, comuníquese de inmediato con {{helpdesk_name}} en {{helpdesk_email}}; podemos darle un pase temporal el mismo día.\n\nGracias por dar el ejemplo."]],
    // BT106: risky people follow-up (Security assessment → Risky people)
    'risk_user_notice' => ['Risky people follow-up', 'Your account needs attention', 'Sent automatically to a person the security assessment finds at risk (when the follow-up is on), with their own reasons, the steps to fix them and the deadline.',
        'The person at risk', null,
        ['deadline' => ['Oct 30, 2026', 'Date to fix it by'], 'body_md' => ["- **No multifactor sign-in**: Set up multifactor sign-in at https://aka.ms/mfasetup.", 'The reasons found for this person, each with its steps (filled in automatically)']],
        [],
        ["Action needed by {{deadline}}: protect your work account",
         "{{greeting}}\n\nOur regular security check found something on your work account that makes it easier to break into. Please fix it by **{{deadline}}**:\n\n{{body_md}}\n\nIt usually takes a few minutes. If something is not clear, just reply to this email or contact IT.\n\nThank you for helping keep everyone's accounts safe."],
        ["Acción requerida antes del {{deadline}}: proteja su cuenta de trabajo",
         "{{greeting}}\n\nNuestra revisión de seguridad periódica encontró algo en su cuenta de trabajo que facilita que alguien entre en ella. Corríjalo a más tardar el **{{deadline}}**:\n\n{{body_md}}\n\nNormalmente toma unos minutos. Si algo no está claro, responda a este correo o comuníquese con TI.\n\nGracias por ayudar a mantener seguras las cuentas de todos."]],
    'risk_user_reminder' => ['Risky people follow-up', 'Reminder: your account still needs attention', 'Sent automatically a few days after the first email when the security assessment still finds the same reasons.',
        'The person at risk', null,
        ['deadline' => ['Oct 30, 2026', 'Date to fix it by'], 'body_md' => ["- **No multifactor sign-in**: Set up multifactor sign-in at https://aka.ms/mfasetup.", 'The reasons still found (filled in automatically)']],
        [],
        ["Reminder: please protect your work account by {{deadline}}",
         "{{greeting}}\n\nA few days ago we asked you to fix something on your work account. Our check still finds:\n\n{{body_md}}\n\nPlease do it by **{{deadline}}**. After that date your manager and IT are told, so IT can help you finish it. Reply to this email if you need help."],
        ["Recordatorio: proteja su cuenta de trabajo antes del {{deadline}}",
         "{{greeting}}\n\nHace unos días le pedimos corregir algo en su cuenta de trabajo. Nuestra revisión todavía encuentra:\n\n{{body_md}}\n\nHágalo a más tardar el **{{deadline}}**. Después de esa fecha se informa a su gerente y a TI, para que TI le ayude a terminarlo. Responda a este correo si necesita ayuda."]],
    'risk_manager_escalation' => ['Risky people follow-up', 'Manager: a team member\'s account is at risk', 'Sent automatically to the person\'s manager (from Microsoft 365) when the deadline passes and the account is still at risk.',
        'The person\'s manager', null,
        ['person_name' => ['Alex Sample', 'The team member'], 'deadline' => ['Oct 30, 2026', 'The deadline that passed'], 'body_md' => ["- **No multifactor sign-in**", 'The reasons still found (filled in automatically)']],
        [],
        ["{{person_name}}'s work account still needs attention",
         "{{greeting}}\n\nOur security check asked **{{person_name}}** to fix the following on their work account by {{deadline}}, and it is still open:\n\n{{body_md}}\n\nIT has opened a ticket to help. Please make sure {{person_name}} has a few minutes to finish it; IT will contact them."],
        ["La cuenta de trabajo de {{person_name}} todavía necesita atención",
         "{{greeting}}\n\nNuestra revisión de seguridad le pidió a **{{person_name}}** corregir lo siguiente en su cuenta de trabajo a más tardar el {{deadline}}, y sigue pendiente:\n\n{{body_md}}\n\nTI abrió un ticket para ayudar. Asegúrese de que {{person_name}} tenga unos minutos para terminarlo; TI se comunicará con esa persona."]],
    'mfa_setup_request' => ['Security rollout', 'Rollout: set up sign-in approval (MFA)', 'Phase 1 (weeks 1–2). Asks all staff to set up the second sign-in step by a deadline, with a choice: Authenticator app, a company security key (no phone) or a text message.',
        'All staff', null,
        ['deadline' => ['Oct 9, 2026', 'Date the step must be finished by (shown in the recipient\'s language)']],
        [],
        ["Action needed: set up your sign-in approval by {{deadline}}",
         "{{greeting}}\n\nAs announced, the first step of our security upgrade is here. Please set up a **second sign-in step** by **{{deadline}}**. It takes about 10 minutes and protects your account even if someone steals your password.\n\n**Choose ONE option — whichever suits you**\n\n**Option 1 — Microsoft Authenticator app (quickest)**\n\n1. On your phone, install **Microsoft Authenticator** (free) from the App Store or Google Play.\n2. On your computer, go to [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo) and sign in with your **work email and password**.\n3. Choose **Add sign-in method** → **Microsoft Authenticator** and follow the steps to scan the QR code.\n4. Microsoft sends a test: type the number shown on your computer into the app and approve.\n\nThe app only shows sign-in prompts. It gives {{org_name}} no access to your phone.\n\n**Option 2 — A security key from {{org_name}} (no phone needed)**\n\nReply to this email saying **\"security key please\"**. {{helpdesk_name}} will give you a small USB key and set it up with you in about 10 minutes. You'll plug it in and touch it when you sign in.\n\n**Option 3 — Text message or phone call**\n\nAt [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo), choose **Add sign-in method** → **Phone** and enter a number. You'll get a code by text or a call. This works on any phone, but it's the least secure option — we may ask you to switch later.\n\n**Good to know:** only approve a sign-in you started yourself. IT will never ask for your password or an approval code.\n\nStuck or not sure which to pick? Just reply to this email and {{helpdesk_name}} will help."],
        ["Acción requerida: configure su aprobación de inicio de sesión a más tardar el {{deadline}}",
         "{{greeting}}\n\nComo se anunció, llegó el primer paso de nuestra mejora de seguridad. Configure un **segundo paso de inicio de sesión** a más tardar el **{{deadline}}**. Toma unos 10 minutos y protege su cuenta aunque alguien robe su contraseña.\n\n**Elija UNA opción, la que le resulte mejor**\n\n**Opción 1: app Microsoft Authenticator (la más rápida)**\n\n1. En su teléfono, instale **Microsoft Authenticator** (gratis) desde App Store o Google Play.\n2. En su computadora, entre a [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo) e inicie sesión con su **correo y contraseña del trabajo**.\n3. Elija **Agregar método de inicio de sesión** → **Microsoft Authenticator** y siga los pasos para escanear el código QR.\n4. Microsoft envía una prueba: escriba en la app el número que aparece en su computadora y apruebe.\n\nLa app solo muestra avisos de inicio de sesión. No le da a {{org_name}} ningún acceso a su teléfono.\n\n**Opción 2: una llave de seguridad de {{org_name}} (no necesita teléfono)**\n\nResponda a este correo con **\"llave de seguridad, por favor\"**. {{helpdesk_name}} le entregará una pequeña llave USB y la configurará con usted en unos 10 minutos. Al iniciar sesión, la conecta y la toca.\n\n**Opción 3: mensaje de texto o llamada**\n\nEn [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo), elija **Agregar método de inicio de sesión** → **Teléfono** y escriba un número. Recibirá un código por mensaje de texto o por llamada. Funciona con cualquier teléfono, pero es la opción menos segura; es posible que más adelante le pidamos cambiarla.\n\n**Importante:** apruebe solo un inicio de sesión que usted mismo(a) inició. TI nunca le pedirá su contraseña ni un código de aprobación.\n\n¿Tiene problemas o no sabe cuál elegir? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    'mfa_enforce_notice' => ['Security rollout', 'Rollout: sign-in approval becomes required', 'Phase 1. Sent to all staff 3 days before MFA is required for everyone and old sign-in methods (legacy authentication) are blocked.',
        'All staff', null,
        ['start_date' => ['Oct 26, 2026', 'Date MFA becomes required for everyone']],
        [],
        ["Starting {{start_date}}: work sign-ins need a second step",
         "{{greeting}}\n\nFrom **{{start_date}}**, signing in to your {{org_name}} account will require your second sign-in step — the Authenticator app, your security key, or a text-message code, whichever you set up.\n\n**Your one action:** if you haven't set one up yet, do it now at [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo), or reply to this email to get a security key (no phone needed). If you already did, you're all set.\n\n**What to expect**\n\n- You won't be asked every time — mostly on a new device or browser, or after some time has passed.\n- Very old email apps that can't show this step will stop connecting. Use Outlook on the web or the current Outlook app instead.\n- If you haven't set up a method by then, you'll be asked to set one up at your next sign-in before you can continue.\n\n**Never approve a sign-in you didn't start.** If you get an unexpected prompt, deny it and tell us right away.\n\nNew phone, lost phone or key, or can't sign in? Reply to this email or write to {{helpdesk_email}} and {{helpdesk_name}} will help — we can give you a temporary pass the same day."],
        ["A partir del {{start_date}}: los inicios de sesión del trabajo requieren un segundo paso",
         "{{greeting}}\n\nA partir del **{{start_date}}**, iniciar sesión en su cuenta de {{org_name}} requerirá su segundo paso de inicio de sesión: la app Authenticator, su llave de seguridad o un código por mensaje de texto, el que haya configurado.\n\n**Su única acción:** si todavía no configuró uno, hágalo ahora en [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo), o responda a este correo para recibir una llave de seguridad (no necesita teléfono). Si ya lo hizo, no necesita hacer nada más.\n\n**Qué esperar**\n\n- No se le pedirá cada vez; sobre todo en un dispositivo o navegador nuevo, o después de un tiempo.\n- Las apps de correo muy antiguas que no pueden mostrar este paso dejarán de conectarse. Use Outlook en la web o la app de Outlook actual.\n- Si para esa fecha no configuró un método, se le pedirá hacerlo en su próximo inicio de sesión antes de continuar.\n\n**Nunca apruebe un inicio de sesión que usted no inició.** Si recibe un aviso inesperado, rechácelo y avísenos de inmediato.\n\n¿Teléfono nuevo, perdió su teléfono o su llave, o no puede iniciar sesión? Responda a este correo o escriba a {{helpdesk_email}} y {{helpdesk_name}} le ayudará; podemos darle un pase temporal el mismo día."]],
    'personal_computer_notice' => ['Security rollout', 'Rollout: working from a personal computer', 'For staff who use a personal computer (and company PCs on Windows Home until upgraded). Recommends the browser. If you turn on the "browser-only" rule, add the date in the note.',
        'Staff who work on a personal computer, and company Home PCs until upgraded', null,
        ['note' => ['From Nov 16, work files will open in the browser only on personal computers.', 'Optional line — use it when you turn on the "browser-only" rule, with the date (write it in plain words)']],
        [],
        ["Using a personal computer for work? The safe way to do it",
         "{{greeting}}\n\nOur records show you may use a personal computer for work. That's fine — it's your choice, and {{org_name}} will never enroll or manage it. IT can't see anything personal on it.\n\nThe flip side is that we can't protect work files saved on a personal computer. So the safest way to work there is **in the web browser**, where work files stay in Microsoft 365 instead of on your computer.\n\n**How to work in the browser**\n\n1. Open **Microsoft Edge** (recommended) or another up-to-date browser such as Chrome or Safari.\n2. Go to [microsoft365.com](https://www.microsoft365.com) and sign in with your **work email**, then complete your sign-in approval.\n3. Open **Outlook**, **Teams**, **OneDrive** or **SharePoint** from there. Tip: bookmark the page.\n\n**Please avoid** syncing work files with OneDrive or saving work attachments to a personal computer.\n\n{{note}}\n\nIf you set up OneDrive sync or desktop Outlook with your work account on a personal computer, please sign out of your work account there — reply and we'll walk you through it.\n\nIf your company computer runs Windows Home, this applies to it too until IT upgrades it.\n\nQuestions? Just reply to this email and {{helpdesk_name}} will help."],
        ["¿Usa una computadora personal para trabajar? La forma segura de hacerlo",
         "{{greeting}}\n\nNuestros registros indican que quizá usa una computadora personal para trabajar. Está bien: es su decisión, y {{org_name}} nunca la inscribirá ni la administrará. TI no puede ver nada personal en ella.\n\nPor eso mismo, no podemos proteger archivos del trabajo guardados en una computadora personal. La forma más segura de trabajar ahí es **en el navegador web**, donde los archivos del trabajo se quedan en Microsoft 365 y no en su computadora.\n\n**Cómo trabajar en el navegador**\n\n1. Abra **Microsoft Edge** (recomendado) u otro navegador actualizado, como Chrome o Safari.\n2. Entre a [microsoft365.com](https://www.microsoft365.com), inicie sesión con su **correo del trabajo** y complete su aprobación de inicio de sesión.\n3. Desde ahí abra **Outlook**, **Teams**, **OneDrive** o **SharePoint**. Consejo: guarde la página en favoritos.\n\n**Evite** sincronizar archivos del trabajo con OneDrive o guardar adjuntos del trabajo en una computadora personal.\n\n{{note}}\n\nSi configuró la sincronización de OneDrive u Outlook de escritorio con su cuenta del trabajo en una computadora personal, cierre la sesión de su cuenta del trabajo ahí; responda y le guiaremos.\n\nSi su computadora de la empresa tiene Windows Home, esto también aplica hasta que TI la actualice.\n\n¿Preguntas? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    'rollout_reminder' => ['Security rollout', 'Rollout: reminder — step not finished', 'Nudge for people who haven\'t finished their rollout step (Authenticator, company computer or phone apps).',
        'Staff with a pending step', null,
        ['task' => ['Set up Microsoft Authenticator', 'The pending step, short (e.g. "Set up Microsoft Authenticator", "Connect your company computer", "Set up the work apps on your phone"). Write it in the recipient\'s language.'], 'deadline' => ['Oct 9, 2026', 'Date the step must be finished by (shown in the recipient\'s language)']],
        [],
        ["Reminder: please finish your security setup by {{deadline}}",
         "{{greeting}}\n\nFriendly reminder — our records show this step isn't finished yet:\n\n**{{task}}**\n\nPlease complete it by **{{deadline}}** so your access isn't interrupted when the new rules start. It usually takes 10 minutes or less, and the instructions are in the earlier email from {{helpdesk_name}}.\n\nAlready done? Thank you — records can take a day to update, so you can ignore this.\n\nCan't find the instructions or got stuck? Just reply to this email and {{helpdesk_name}} will help."],
        ["Recordatorio: termine su configuración de seguridad a más tardar el {{deadline}}",
         "{{greeting}}\n\nUn recordatorio amable: nuestros registros indican que este paso aún no está terminado:\n\n**{{task}}**\n\nComplételo a más tardar el **{{deadline}}** para que su acceso no se interrumpa cuando entren en vigor las nuevas reglas. Normalmente toma 10 minutos o menos, y las instrucciones están en el correo anterior de {{helpdesk_name}}.\n\n¿Ya lo hizo? Gracias; los registros pueden tardar un día en actualizarse, así que puede ignorar este mensaje.\n\n¿No encuentra las instrucciones o tuvo problemas? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    'security_key_setup' => ['Security rollout', 'Rollout: your security key — set-up and use', 'For people who chose a security key instead of their phone. Send when the key is ready; IT sits with them and uses a Temporary Access Pass (Microsoft 365 → Accounts → the person → + Temporary access pass) for the first sign-in. Never put the pass itself in an email.',
        'Staff receiving a security key', null,
        ['note' => ['Come to the front office on Tuesday at 10:00.', 'When / where to collect the key (may be empty)']],
        [],
        ["Your security key is ready ({{org_name}})",
         "{{greeting}}\n\nYour security key is ready — thank you for choosing it. It's a small USB key that replaces the phone app: no phone needed, nothing to install on your personal devices.\n\n{{note}}\n\n**Setting it up (about 10 minutes, with {{helpdesk_name}})**\n\n1. We'll give you a **one-time temporary pass** in person. We never send it by email.\n2. On a computer, go to [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo), sign in with your work email and use the temporary pass when asked.\n3. Choose **Add sign-in method** → **Security key** → **USB device**.\n4. Plug in the key, create a **PIN for the key** (at least 4 digits — you choose it) and touch the key when it blinks.\n5. Give it a name, like \"My key\".\n\n**Using it**\n\n- When Microsoft asks you to approve a sign-in, plug in the key, enter its PIN and touch it.\n- It works in the web browser on any computer with a USB port, and in the Microsoft 365 apps on Windows.\n\n**Look after it:** keep it on your key ring or badge holder. If you lose it, tell us the same day — we'll block it and give you a new one. It holds no personal data and only works with your PIN.\n\nQuestions? Just reply to this email and {{helpdesk_name}} will help."],
        ["Su llave de seguridad está lista ({{org_name}})",
         "{{greeting}}\n\nSu llave de seguridad está lista; gracias por elegirla. Es una pequeña llave USB que reemplaza la app del teléfono: no necesita teléfono ni instalar nada en sus dispositivos personales.\n\n{{note}}\n\n**Cómo configurarla (unos 10 minutos, con {{helpdesk_name}})**\n\n1. Le daremos en persona un **pase temporal de un solo uso**. Nunca lo enviamos por correo.\n2. En una computadora, entre a [aka.ms/mysecurityinfo](https://aka.ms/mysecurityinfo), inicie sesión con su correo del trabajo y use el pase temporal cuando se lo pida.\n3. Elija **Agregar método de inicio de sesión** → **Llave de seguridad** → **Dispositivo USB**.\n4. Conecte la llave, cree un **PIN para la llave** (al menos 4 dígitos; usted lo elige) y toque la llave cuando parpadee.\n5. Póngale un nombre, por ejemplo \"Mi llave\".\n\n**Cómo usarla**\n\n- Cuando Microsoft le pida aprobar un inicio de sesión, conecte la llave, escriba su PIN y tóquela.\n- Funciona en el navegador web de cualquier computadora con puerto USB y en las apps de Microsoft 365 en Windows.\n\n**Cuídela:** llévela en su llavero o en el porta-credencial. Si la pierde, avísenos el mismo día; la bloquearemos y le daremos otra. No guarda datos personales y solo funciona con su PIN.\n\n¿Preguntas? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    'device_enforce_notice' => ['Security rollout', 'Rollout: company computer rules take effect', 'Phase 5. Compliance switches to "7-day grace then block" for COMPANY computers: one that doesn\'t meet the rules has 7 days to be fixed before it loses access to work apps. Personal devices are not affected.',
        'All staff', null,
        ['start_date' => ['Dec 7, 2026', 'Date the device rules start being enforced']],
        [],
        ["Starting {{start_date}}: company computers must meet our security rules",
         "{{greeting}}\n\nFrom **{{start_date}}**, company computers must meet our security rules to open work email, Teams and files. The rules are simple: security protection on, disk encryption on, and updates installed.\n\nIf a company computer falls out of line, you'll have **7 days** to fix it. After that, it loses access to work apps until it's fixed — the computer itself keeps working.\n\n**Your one action: check your company computer**\n\n1. Open the **Company Portal** app from the Start menu.\n2. Select this device and check its status.\n3. If something needs attention, follow the steps shown — most fixes are a restart or installing Windows updates.\n\n**Tip:** restart your computer at least once a week and leave it on and connected now and then so updates can install.\n\n**Nothing changes for personal devices.** They are never managed, and nothing new is required on them.\n\nCan't find Company Portal, or the status won't turn green? Just reply to this email and {{helpdesk_name}} will help."],
        ["A partir del {{start_date}}: las computadoras de la empresa deben cumplir nuestras reglas de seguridad",
         "{{greeting}}\n\nA partir del **{{start_date}}**, las computadoras de la empresa deben cumplir nuestras reglas de seguridad para abrir el correo, Teams y los archivos del trabajo. Las reglas son sencillas: protección de seguridad activa, cifrado del disco activo y actualizaciones instaladas.\n\nSi una computadora de la empresa deja de cumplir, tendrá **7 días** para corregirlo. Después, pierde el acceso a las apps del trabajo hasta que se corrija; la computadora en sí sigue funcionando.\n\n**Su única acción: revise su computadora de la empresa**\n\n1. Abra la app **Portal de empresa** (Company Portal) desde el menú Inicio.\n2. Seleccione este dispositivo y revise su estado.\n3. Si algo necesita atención, siga los pasos indicados; la mayoría se resuelve reiniciando o instalando las actualizaciones de Windows.\n\n**Consejo:** reinicie su computadora al menos una vez por semana y déjela encendida y conectada de vez en cuando para que se instalen las actualizaciones.\n\n**Nada cambia para los dispositivos personales.** Nunca se administran y no se requiere nada nuevo en ellos.\n\n¿No encuentra Portal de empresa o el estado no se pone en verde? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    'rollout_complete' => ['Security rollout', 'Rollout: complete — thank you', 'Sent to all staff when the rollout is finished: thanks, how to report phishing, the "approve only what you started" reminder and what to do with a new/lost phone or key.',
        'All staff', null,
        [],
        [],
        ["Thank you — our Microsoft 365 security upgrade is complete",
         "{{greeting}}\n\nOur Microsoft 365 security upgrade is done, and {{org_name}} is much better protected because of you. Thank you for your time and patience.\n\n**Keep these habits**\n\n- **Report suspicious email:** in Outlook, use the **Report** button and choose **Phishing**. Not sure? Reply to this email with your question — never click to find out.\n- **Approve only what you started.** If you're asked to approve a sign-in you didn't start, deny it and tell us right away.\n- **Getting a new phone?** If you use the Authenticator app or text messages, tell us before you reset the old one.\n- **Lost a phone, security key or company computer?** Tell us the same day.\n\nIT will never ask for your password or an approval code — by email, phone or text.\n\nAnything not working as it should? Just reply to this email and {{helpdesk_name}} will help."],
        ["Gracias: nuestra mejora de seguridad de Microsoft 365 está completa",
         "{{greeting}}\n\nNuestra mejora de seguridad de Microsoft 365 terminó, y {{org_name}} está mucho mejor protegido gracias a usted. Gracias por su tiempo y paciencia.\n\n**Mantenga estos hábitos**\n\n- **Reporte correos sospechosos:** en Outlook, use el botón **Reportar** y elija **Phishing** (suplantación de identidad). ¿No está seguro(a)? Responda a este correo con su pregunta; nunca haga clic para averiguarlo.\n- **Apruebe solo lo que usted inició.** Si se le pide aprobar un inicio de sesión que usted no inició, recházelo y avísenos de inmediato.\n- **¿Va a cambiar de teléfono?** Si usa la app Authenticator o mensajes de texto, avísenos antes de restablecer el anterior.\n- **¿Perdió un teléfono, una llave de seguridad o una computadora de la empresa?** Avísenos el mismo día.\n\nTI nunca le pedirá su contraseña ni un código de aprobación, ni por correo, teléfono o mensaje de texto.\n\n¿Algo no funciona como debería? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    // ───────────────────────────── People ─────────────────────────────
    'device_enroll_computer' => ['People & equipment', 'Set up your work computer (Intune)', 'Enrolment invitation for a COMPANY-OWNED Windows PC (requires Windows Pro/Enterprise/Education). Sent from Microsoft 365 → Devices → "Invite to enrol".',
        'The employee', null,
        ['note' => ['If anything fails, bring the laptop by the IT office.', 'Extra note typed when sending (may be empty)'], 'link' => $L['link']],
        [],
        ['Action needed: connect your work computer ({{org_name}})',
         "{{greeting}}\n\nWe're connecting company computers to our management system so they stay secure and get set up automatically. Please take 5 minutes to connect yours:\n\nIT will book a 30-minute slot with you (or reply to pick a time). The steps, for reference:\n\n1. Save your work and make sure OneDrive has finished syncing.\n2. Open **Settings** → **Accounts** → **Access work or school** → **Connect**.\n3. Under **Alternate actions**, choose **Join this device to Microsoft Entra ID** (do not type your email in the first box).\n4. Sign in with your **work email and password** and approve the prompt in Microsoft Authenticator. This step needs the computer's administrator password — IT will enter it if needed.\n5. Restart. At the sign-in screen choose **Other user** and sign in with your **work email**.\n\nYour new work sign-in starts with a fresh desktop; your old files are still on the computer and IT will help move them. After that the computer gets our security settings and updates automatically.\n\n{{note}}\n\nQuestions or something fails? Just reply to this email and {{helpdesk_name}} will help."],
        ['Acción requerida: conecte su computadora de trabajo ({{org_name}})',
         "{{greeting}}\n\nEstamos conectando las computadoras de la empresa a nuestro sistema de administración para mantenerlas seguras y configuradas automáticamente. Tome 5 minutos para conectar la suya:\n\nTI coordinará con usted una cita de 30 minutos (o responda para elegir un horario). Los pasos, como referencia:\n\n1. Guarde su trabajo y asegúrese de que OneDrive terminó de sincronizar.\n2. Abra **Configuración** → **Cuentas** → **Acceso a trabajo o escuela** → **Conectar**.\n3. En **Acciones alternativas**, elija **Unir este dispositivo a Microsoft Entra ID** (no escriba su correo en el primer cuadro).\n4. Inicie sesión con su **correo y contraseña del trabajo** y apruebe el aviso en Microsoft Authenticator. Este paso requiere la contraseña de administrador de la computadora; TI la ingresará si hace falta.\n5. Reinicie. En la pantalla de inicio elija **Otro usuario** e inicie sesión con su **correo del trabajo**.\n\nSu nueva sesión de trabajo empieza con un escritorio nuevo; sus archivos anteriores siguen en la computadora y TI le ayudará a moverlos. Después, la computadora recibe nuestra configuración de seguridad y las actualizaciones automáticamente.\n\n{{note}}\n\n¿Preguntas o algo falla? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    'pc_protect_invite' => ['People & equipment', 'Protect your own computer (Microsoft Defender)', 'For PERSONAL computers used for work — optional. Invites the person to protect their computer with Microsoft Defender, managed by IT, without enrolling it. Sent from Microsoft 365 → Devices → Personal computers.',
        'The employee', null,
        ['pc_link' => ['https://ops.example.com/pc.html?t=…', 'The person\'s own link (agree, check the computer, get the file)'], 'note' => ['Questions? Reply to this email.', 'Extra note typed when sending (may be empty)']],
        [],
        ['Optional: protect the computer you use for work ({{org_name}})',
         "{{greeting}}\n\nYou use your own computer for work. {{org_name}} offers to protect it with Microsoft Defender at no cost to you. It is your choice.\n\nWhat it means: IT keeps Microsoft Defender Antivirus and the Windows Firewall on and up to date on your computer, and is alerted if a threat is found. Your computer is NOT enrolled in company management: IT cannot install apps, wipe it, or read your files, photos or email. The page explains exactly what IT sees before you agree.\n\nOpen your personal page (it takes about 10 minutes):\n{{pc_link}}\n\nOn the page you read and agree, run a short check of your computer, and then follow two or three steps. If your computer runs Windows Home, the page shows you how to make sure the Defender already in Windows is on.\n\n{{note}}\n\nThe link is only for you; please do not forward it."],
        ['Opcional: proteja la computadora que usa para trabajar ({{org_name}})',
         "{{greeting}}\n\nUsted usa su propia computadora para trabajar. {{org_name}} le ofrece protegerla con Microsoft Defender sin costo para usted. Es su decisión.\n\nQué significa: TI mantiene Microsoft Defender Antivirus y el Firewall de Windows activos y actualizados en su computadora, y recibe una alerta si se encuentra una amenaza. Su computadora NO se inscribe en la administración de la empresa: TI no puede instalar aplicaciones, borrarla, ni leer sus archivos, fotos o correo. La página explica exactamente qué ve TI antes de que usted acepte.\n\nAbra su página personal (toma unos 10 minutos):\n{{pc_link}}\n\nEn la página usted lee y acepta, ejecuta una revisión corta de su computadora y luego sigue dos o tres pasos. Si su computadora tiene Windows Home, la página le muestra cómo asegurarse de que el Defender que ya trae Windows esté activo.\n\n{{note}}\n\nEl enlace es solo para usted; por favor no lo reenvíe."]],
    'device_enroll_phone' => ['People & equipment', 'Your choices for work on your phone', 'For PERSONAL phones — entirely optional. Explains the three choices (don\'t use the phone for work, the web browser, or the protected Outlook/Teams apps). Nothing is installed or managed by the company. Sent from Devices → "Invite to enrol" or Rollout.',
        'The employee', null,
        ['note' => ['You only need Outlook and Teams to start.', 'Extra note typed when sending (may be empty)'], 'link' => $L['link']],
        [],
        ["Work on your phone: your choices ({{org_name}})",
         "{{greeting}}\n\nUsing your personal phone for work is **completely optional**. {{org_name}} doesn't provide work phones, so we'll never require you to install anything on yours. Here are your choices:\n\n**Choice 1 — Don't use your phone for work.** That's fine. Use your computer for email, Teams and files.\n\n**Choice 2 — Use the phone's web browser.** Open [outlook.office.com](https://outlook.office.com) in Safari or Chrome and sign in with your work email. Nothing to install.\n\n**Choice 3 — Use the Outlook and Teams apps (the most convenient).**\n\n1. Install **Microsoft Outlook** (and **Teams** if you use it) from the App Store or Google Play.\n2. Sign in with your **work email**, complete your sign-in approval, and set a small **PIN for the app** if asked.\n\nWith choice 3, protection applies **only inside the work apps**: work data can't be copied into personal apps, and if you ever leave, only the work data inside those apps is removed. Your phone is never enrolled or managed, and IT can't see your photos, messages, location or anything personal.\n\n{{note}}\n\nQuestions? Just reply to this email and {{helpdesk_name}} will help."],
        ["Trabajo en su teléfono: sus opciones ({{org_name}})",
         "{{greeting}}\n\nUsar su teléfono personal para el trabajo es **totalmente opcional**. {{org_name}} no entrega teléfonos de trabajo, así que nunca le pediremos instalar nada en el suyo. Estas son sus opciones:\n\n**Opción 1: no usar el teléfono para el trabajo.** Está bien. Use su computadora para el correo, Teams y los archivos.\n\n**Opción 2: usar el navegador del teléfono.** Abra [outlook.office.com](https://outlook.office.com) en Safari o Chrome e inicie sesión con su correo del trabajo. No hay nada que instalar.\n\n**Opción 3: usar las apps de Outlook y Teams (la más cómoda).**\n\n1. Instale **Microsoft Outlook** (y **Teams** si lo usa) desde App Store o Google Play.\n2. Inicie sesión con su **correo del trabajo**, complete su aprobación de inicio de sesión y configure un pequeño **PIN para la app** si se lo pide.\n\nCon la opción 3, la protección aplica **solo dentro de las apps del trabajo**: los datos del trabajo no se pueden copiar a apps personales y, si algún día se va, solo se elimina la información del trabajo dentro de esas apps. Su teléfono nunca se inscribe ni se administra, y TI no puede ver sus fotos, mensajes, ubicación ni nada personal.\n\n{{note}}\n\n¿Preguntas? Responda a este correo y {{helpdesk_name}} le ayudará."]],
    'signature_request' => ['People & equipment', 'Signature request', 'Sent to someone asked to e-sign a document (SOP, job description, form).',
        'The signer', null,
        ['document' => ['Acceptable Use Policy', 'Document title'], 'message' => ['Please sign by Friday.', 'Optional message from the sender'], 'sign_link' => ['https://ops.example.com/sign.html?token=…', 'Private signing link'], 'sender_name' => ['Jordan Lee', 'Who requested it']],
        [],
        ['Signature requested: {{document}}',
         "{{greeting}}\n\n{{sender_name}} has asked you to sign **{{document}}**.\n\n{{message}}\n\n[Review and sign]({{sign_link}})\n\nThis link is unique to you. If you weren't expecting this, you can ignore this email."],
        ['Solicitud de firma: {{document}}',
         "{{greeting}}\n\n{{sender_name}} le pidió que firme **{{document}}**.\n\n{{message}}\n\n[Revisar y firmar]({{sign_link}})\n\nEste enlace es único para usted. Si no esperaba este mensaje, puede ignorarlo."]],
    'onboarding_sign' => ['People & equipment', 'Onboarding document to sign', 'Sent to a new hire for each document their onboarding asks them to sign.',
        'The new hire', null,
        ['person_name' => ['Alex Sample', 'New hire'], 'document' => ['Employee Handbook', 'Document title'], 'sign_link' => ['https://ops.example.com/sign.html?token=…', 'Private signing link']],
        [],
        ['Please sign: {{document}}',
         "{{greeting}}\n\nAs part of your onboarding, please review and sign **{{document}}**:\n\n[Review and sign]({{sign_link}})"],
        ['Por favor firme: {{document}}',
         "{{greeting}}\n\nComo parte de su proceso de incorporación, revise y firme **{{document}}**:\n\n[Revisar y firmar]({{sign_link}})"]],
    'onboarding_notice' => ['People & equipment', 'Onboarding task notification', 'Sent by an onboarding "Notify" step. The step\'s own message is inserted as {{message}}.',
        'Address set on the step', null,
        ['person_name' => ['Alex Sample', 'Person being on/offboarded'], 'task' => ['Order laptop', 'Task title'], 'message' => ['Please prepare a laptop for Alex\'s first day.', 'Message written on the onboarding step']],
        [],
        ['{{app_name}} onboarding: {{person_name}}', "{{message}}\n\n**Task:** {{task}}"],
        ['Incorporación en {{app_name}}: {{person_name}}', "{{message}}\n\n**Tarea:** {{task}}"]],
    'equipment_assigned' => ['People & equipment', 'Equipment assigned — accept & sign', 'Sent to the borrower when equipment is loaned with "send for signature".',
        'The borrower', null,
        ['asset_tag' => ['FS123rf', 'Asset tag'], 'asset_name' => ['Dell Latitude 5440', 'Make and model'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Equipment assigned — please accept & sign',
         "{{greeting}}\n\nEquipment has been assigned to you: **{{asset_tag}}** ({{asset_name}}).\n\nSign in to [{{app_name}}]({{link}}) and open **My Equipment** to review the loan agreement and sign for it."],
        ['[{{app_name}}] Equipo asignado — acepte y firme',
         "{{greeting}}\n\nSe le asignó el siguiente equipo: **{{asset_tag}}** ({{asset_name}}).\n\nInicie sesión en [{{app_name}}]({{link}}) y abra **Mi equipo** para revisar el acuerdo de préstamo y firmarlo."]],
    'announcement_new' => ['Announcements', 'New announcement', 'Sent to each person an announcement is published to, when "Email" is ticked on it.',
        'The people the announcement is for', null,
        ['title' => ['Office closed on Friday', 'Title of the announcement'], 'body_md' => ["The office is closed this **Friday**.\n\n- Phones go to voicemail\n- Tickets are answered on Monday", 'The announcement text'],
         'level' => ['Important', 'Announcement, Important or Urgent'], 'sender_name' => ['Jordan Lee', 'Who published it'],
         'ack_line' => ['Please open it and confirm that you have read it by Oct 10, 2026.', 'Only filled in when the announcement asks people to confirm they read it'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] {{level}}: {{title}}',
         "{{greeting}}\n\n**{{title}}**\n\n{{body_md}}\n\n{{ack_line}}\n\n[Open it in {{app_name}}]({{link}})\n\n— {{sender_name}}"],
        ['[{{app_name}}] {{level}}: {{title}}',
         "{{greeting}}\n\n**{{title}}**\n\n{{body_md}}\n\n{{ack_line}}\n\n[Ábralo en {{app_name}}]({{link}})\n\n— {{sender_name}}"]],
    'announcement_reminder' => ['Announcements', 'Announcement reminder', 'Sent to people who have not yet read (or confirmed) an announcement, when someone clicks "Remind" or an automatic reminder is due.',
        'People who have not read it yet', null,
        ['title' => ['Office closed on Friday', 'Title of the announcement'], 'body_md' => ["The office is closed this **Friday**.", 'The announcement text'],
         'level' => ['Important', 'Announcement, Important or Urgent'], 'sender_name' => ['Jordan Lee', 'Who published it'],
         'ack_line' => ['Please open it and confirm that you have read it by Oct 10, 2026.', 'Only filled in when the announcement asks people to confirm they read it'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Reminder: {{title}}',
         "{{greeting}}\n\nThis is a reminder about an announcement you have not opened yet.\n\n**{{title}}**\n\n{{body_md}}\n\n{{ack_line}}\n\n[Open it in {{app_name}}]({{link}})"],
        ['[{{app_name}}] Recordatorio: {{title}}',
         "{{greeting}}\n\nEste es un recordatorio de un anuncio que aún no ha abierto.\n\n**{{title}}**\n\n{{body_md}}\n\n{{ack_line}}\n\n[Ábralo en {{app_name}}]({{link}})"]],
    'announcement_external' => ['Announcements', 'Announcement to someone without an account', 'BT94: sent to each person in a Microsoft 365 group or at a typed address who has no account in the app. The link is personal: opening it counts as opened, and it has the "I have read this" button.',
        'People outside the app the announcement is for', null,
        ['title' => ['Office closed on Friday', 'Title of the announcement'], 'body_md' => ["The office is closed this **Friday**.\n\n- Phones go to voicemail\n- Tickets are answered on Monday", 'The announcement text'],
         'level' => ['Important', 'Announcement, Important or Urgent'], 'sender_name' => ['Jordan Lee', 'Who published it'],
         'reminder_line' => ['This is a reminder: you have not confirmed this announcement yet.', 'Only filled in on a reminder'],
         'ack_line' => ['Please open it and confirm that you have read it by Oct 10, 2026.', 'Only filled in when the announcement asks people to confirm they read it'],
         'link_label' => ['Open it and confirm you have read it', 'The words of the button'], 'link' => ['https://opshub.example.org/read.html?t=…', 'The personal link (no account needed)']],
        [],
        ['[{{app_name}}] {{level}}: {{title}}',
         "{{greeting}}\n\n{{reminder_line}}\n\n**{{title}}**\n\n{{body_md}}\n\n{{ack_line}}\n\n[{{link_label}}]({{link}})\n\nThis link is for you only.\n\n— {{sender_name}}"],
        ['[{{app_name}}] {{level}}: {{title}}',
         "{{greeting}}\n\n{{reminder_line}}\n\n**{{title}}**\n\n{{body_md}}\n\n{{ack_line}}\n\n[{{link_label}}]({{link}})\n\nEste enlace es solo para usted.\n\n— {{sender_name}}"]],
    'ticket_notice' => ['Tickets', 'Notice about a ticket', 'BT94: sent when someone uses "Notify" on a ticket to write to a Microsoft 365 group or to email addresses. It says what the ticket is about; it does not give access to the ticket.',
        'The group members and addresses chosen', null,
        ['title' => ['Printer on the 2nd floor is back', 'Title of the notice'], 'message' => ['You can print again. Jobs sent this morning may need to be sent again.', 'The message'],
         'ticket_number' => ['TKT-01042', 'The ticket number'], 'subject' => ['2nd floor printer offline', 'The ticket subject'], 'sender_name' => ['Jordan Lee', 'Who sent it']],
        [],
        ['[{{app_name}}] {{title}}',
         "{{greeting}}\n\n**{{title}}**\n\n{{message}}\n\nAbout: {{subject}} (help desk reference {{ticket_number}})\n\n— {{sender_name}}"],
        ['[{{app_name}}] {{title}}',
         "{{greeting}}\n\n**{{title}}**\n\n{{message}}\n\nSobre: {{subject}} (referencia de la mesa de ayuda {{ticket_number}})\n\n— {{sender_name}}"]],
    'admin_message' => ['People & equipment', 'Message from an administrator', 'Sent when someone uses "Notify" and ticks "also email".',
        'Chosen users', null,
        ['title' => ['Planned maintenance tonight', 'Title of the message'], 'message' => ['Email will be unavailable 10–11 pm.', 'The message'], 'sender_name' => ['Jordan Lee', 'Who sent it']],
        [],
        ['[{{app_name}}] {{title}}', "{{greeting}}\n\n{{message}}\n\n— {{sender_name}}"],
        ['[{{app_name}}] {{title}}', "{{greeting}}\n\n{{message}}\n\n— {{sender_name}}"]],    'onboarding_welcome' => ['People & equipment', 'Welcome — first-day information', 'Sent by the onboarding step "Send welcome email" to the new hire (their new mailbox, or another address set on the step).',
        'The new hire', null,
        ['person_name' => ['Alex Sample', 'New hire'], 'start_date' => ['Oct 1, 2026', 'Start date'], 'job_title' => ['Analyst', 'Job title'],
         'manager_name' => ['Bea Boss', 'Manager'], 'account' => ['alex@example.org', 'Their sign-in / email address'], 'message' => ['Parking is behind building B.', 'Extra text written on the template step'], 'link' => $L['link']],
        [],
        ['Welcome to {{org_name}}, {{person_name}}!', "{{greeting}}\n\nWelcome to **{{org_name}}**! We're glad to have you as our new {{job_title}}.\n\n- Start date: **{{start_date}}**\n- Your sign-in: **{{account}}** (your temporary password is given to you separately; you'll choose your own at first sign-in)\n- Your manager: {{manager_name}}\n\n{{message}}\n\nIf anything doesn't work on day one, just reply to this email or contact {{helpdesk_email}}.\n\n— {{helpdesk_name}}"],
        ['¡Bienvenido(a) a {{org_name}}, {{person_name}}!', "{{greeting}}\n\n¡Bienvenido(a) a **{{org_name}}**! Nos alegra tenerle como nuestro(a) nuevo(a) {{job_title}}.\n\n- Fecha de inicio: **{{start_date}}**\n- Su usuario: **{{account}}** (la contraseña temporal se le entrega por separado; elegirá la suya al iniciar sesión)\n- Su supervisor(a): {{manager_name}}\n\n{{message}}\n\nSi algo no funciona el primer día, responda a este correo o escriba a {{helpdesk_email}}.\n\n— {{helpdesk_name}}"]],
    'offboarding_summary' => ['People & equipment', 'Offboarding summary', 'Sent to whoever started (or scheduled) an offboarding and to the manager, when it completes or after a scheduled run.',
        'Requester + manager', null,
        ['person_name' => ['Alex Sample', 'Person who left'], 'status' => ['completed', 'completed / partly done, in the recipient\'s language'],
         'report' => ["- ✓ Block sign-in: Account disabled\n- ✓ Remove all licenses: 2 license(s) removed", 'Each step and its outcome'],
         'verification' => ['✓ Access verified removed in every system.', 'Result of the access check'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Offboarding of {{person_name}}: {{status}}', "{{greeting}}\n\nThe offboarding of **{{person_name}}** is **{{status}}**.\n\n{{report}}\n\n**Access check:** {{verification}}\n\n[Open the process in {{app_name}}]({{link}})"],
        ['[{{app_name}}] Salida de {{person_name}}: {{status}}', "{{greeting}}\n\nEl proceso de salida de **{{person_name}}** está **{{status}}**.\n\n{{report}}\n\n**Verificación de accesos:** {{verification}}\n\n[Abrir el proceso en {{app_name}}]({{link}})"]],
    'access_recert_alert' => ['People & equipment', 'Access re-check found a problem', 'Sent to administrators when "Verify offboarding" (the monthly access re-check or an automatic check) finds a departed person who still has access.',
        'Administrators', null,
        ['count' => ['1', 'How many people still have access'], 'report' => ["- Alex Sample: Microsoft 365: Sign-in blocked — alex@example.org can still sign in (account enabled)", 'The people and the failed checks'], 'link' => $L['link']],
        [],
        ['[{{app_name}}] Access re-check: {{count}} former employee(s) still have access', "{{greeting}}\n\nThe offboarding access check found **{{count}}** person(s) who should no longer have access:\n\n{{report}}\n\nOpen each process in {{app_name}} → Microsoft 365 → Accounts → History, fix the item and click **Re-check**.\n\n[Open {{app_name}}]({{link}})"],
        ['[{{app_name}}] Revisión de accesos: {{count}} ex empleado(s) aún tienen acceso', "{{greeting}}\n\nLa verificación de accesos encontró **{{count}}** persona(s) que ya no deberían tener acceso:\n\n{{report}}\n\nAbra cada proceso en {{app_name}} → Microsoft 365 → Accounts → History, corrija el punto y pulse **Re-check**.\n\n[Abrir {{app_name}}]({{link}})"]],
    ];
}

/** Default header/footer wrapped around every email, per language. */
function opsh_tpl_wrapper_defaults(): array {
    return [
        'en' => ['header' => '', 'footer' => "{{helpdesk_name}} · {{org_name}}\n{{helpdesk_email}}"],
        'es' => ['header' => '', 'footer' => "{{helpdesk_name}} · {{org_name}}\n{{helpdesk_email}}"],
    ];
}

function opsh_tpl_setting(string $k, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (getDB()->query("SELECT skey, sval FROM opsh_settings WHERE skey LIKE 'mail\\_%' OR skey LIKE 'tpl\\_%' OR skey IN ('app_name','branding_name','app_logo_url')")->fetchAll() as $r) $cache[$r['skey']] = $r['sval'];
        } catch (Throwable $e) {}
    }
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}

function opsh_tpl_enabled(string $key): bool {
    $cat = opsh_tpl_catalog(); if (!isset($cat[$key])) return false;
    if (opsh_tpl_setting('tpl_on_' . $key, '1') !== '1') return false;
    $master = $cat[$key][4];
    if ($master) {
        $mdef = $master === 'mail_notify_assign' ? '1' : '0';
        if (opsh_tpl_setting($master, $mdef) !== '1') return false;
    }
    return true;
}

/** Stored override for key+lang, or null. */
function opsh_tpl_override(string $key, string $lang): ?array {
    try {
        $st = getDB()->prepare('SELECT subject, body FROM opsh_email_templates WHERE tpl_key=? AND lang=?');
        $st->execute([$key, $lang]); $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }   // table not created yet → defaults
}

function opsh_tpl_wrapper(string $lang): array {
    $d = opsh_tpl_wrapper_defaults()[$lang] ?? opsh_tpl_wrapper_defaults()['en'];
    $h = opsh_tpl_setting('tpl_wrap_header_' . $lang); $f = opsh_tpl_setting('tpl_wrap_footer_' . $lang);
    return ['header' => $h !== null ? $h : $d['header'], 'footer' => $f !== null ? $f : $d['footer']];
}

/** Localised labels used for status / priority / SLA type values. */
function opsh_tpl_label(string $kind, string $v, string $lang): string {
    $M = [
      'status' => ['en' => ['open'=>'Open','in_progress'=>'In progress','pending'=>'Pending','on_hold'=>'On hold','resolved'=>'Resolved','closed'=>'Closed','cancelled'=>'Cancelled'],
                   'es' => ['open'=>'Abierta','in_progress'=>'En progreso','pending'=>'Pendiente','on_hold'=>'En espera','resolved'=>'Resuelta','closed'=>'Cerrada','cancelled'=>'Cancelada']],
      'priority' => ['en' => ['p1_critical'=>'Critical','p2_high'=>'High','p3_medium'=>'Medium','p4_low'=>'Low','p5_planned'=>'Planned'],
                     'es' => ['p1_critical'=>'Crítica','p2_high'=>'Alta','p3_medium'=>'Media','p4_low'=>'Baja','p5_planned'=>'Planificada']],
      'sla' => ['en' => ['response'=>'Response','resolution'=>'Resolution'], 'es' => ['response'=>'respuesta','resolution'=>'resolución']],
    ];
    return $M[$kind][$lang][$v] ?? ($M[$kind]['en'][$v] ?? ucwords(str_replace('_', ' ', $v)));
}

/** Human date in the recipient's language: "Sep 30, 2026" / "30 sep 2026" (+ time when present). */
function opsh_tpl_date(?string $d, string $lang): string {
    if (!$d) return '';
    $t = strtotime($d); if ($t === false) return (string)$d;
    $hasTime = strlen($d) > 10 && substr($d, 11, 5) !== '00:00';
    if ($lang === 'es') {
        $m = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int)date('n', $t) - 1];
        return date('j', $t) . ' ' . $m . ' ' . date('Y', $t) . ($hasTime ? ' ' . date('g:i A', $t) : '');
    }
    return date('m/d/Y', $t) . ($hasTime ? ' ' . date('g:i A', $t) : '');
}

/** Placeholders found in a text. */
function opsh_tpl_names(string $s): array {
    preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $s, $m);
    return array_values(array_unique($m[1]));
}

/** [] when OK, else a list of human-readable problems. */
function opsh_tpl_validate(string $key, string $subject, string $body): array {
    $cat = opsh_tpl_catalog(); if (!isset($cat[$key])) return ['Unknown template.'];
    $allowed = array_merge(array_keys(opsh_tpl_common_vars()), array_keys($cat[$key][5]));
    $err = [];
    if (trim($subject) === '') $err[] = 'The subject cannot be empty.';
    if (mb_strlen($subject) > 250) $err[] = 'The subject is longer than 250 characters.';
    if (trim($body) === '') $err[] = 'The message cannot be empty.';
    if (mb_strlen($body) > 20000) $err[] = 'The message is longer than 20,000 characters.';
    $bad = array_diff(array_merge(opsh_tpl_names($subject), opsh_tpl_names($body)), $allowed);
    if ($bad) $err[] = 'Unknown placeholder(s): ' . implode(', ', array_map(fn($n) => '{{' . $n . '}}', $bad)) . '. Use only the ones listed for this email.';
    foreach ($cat[$key][6] as $req) {
        if (!in_array($req, opsh_tpl_names($subject), true)) $err[] = 'The subject must contain {{' . $req . '}} — replies are matched to the ticket by that number.';
    }
    return $err;
}

/** Replace placeholders; $esc = HTML-escape values. Array values are per-language. */
function opsh_tpl_tidy(string $s): string {
    $lines = array_map(fn($l) => trim(preg_replace('/^\s*(·|\||,)\s*|\s*(·|\||,)\s*$/u', '', $l)), explode("\n", str_replace("\r", '', $s)));
    return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
}
function opsh_tpl_fill(string $tpl, array $vars, string $lang, bool $esc): string {
    return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($vars, $lang, $esc) {
        $v = $vars[$m[1]] ?? '';
        if (is_array($v)) $v = $v[$lang] ?? ($v['en'] ?? '');
        $v = (string)$v;
        return $esc ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;
    }, $tpl);
}

/** Markdown-lite → HTML on ALREADY-ESCAPED text. */
function opsh_tpl_md_html(string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/\n{3,}/', "\n\n", trim($s));
    $link = function ($m) {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!preg_match('#^(https?://|mailto:)#i', $url)) return $m[0];
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="color:#1D4D7A;font-weight:600">' . $m[1] . '</a>';
    };
    $out = [];
    foreach (explode("\n\n", $s) as $para) {
        $lines = explode("\n", $para);
        $isList = count(array_filter($lines, fn($l) => preg_match('/^\s*[-•]\s+/', $l))) === count($lines);
        $fmt = function ($t) use ($link) {
            $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', $link, $t);
            $t = opsh_tpl_autolink($t);
            return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
        };
        if ($isList) {
            $out[] = '<ul style="margin:0 0 14px 18px;padding:0">' . implode('', array_map(fn($l) => '<li style="margin:3px 0">' . $fmt(preg_replace('/^\s*[-•]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
        } else {
            $out[] = '<p style="margin:0 0 14px">' . implode('<br>', array_map($fmt, $lines)) . '</p>';
        }
    }
    return implode("\n", $out);
}

/** Link bare http(s) URLs in already-escaped HTML — only in text, never inside a tag or an existing link. */
function opsh_tpl_autolink(string $html): string {
    $parts = preg_split('#(<a\b[^>]*>.*?</a>|<[^>]+>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $p) {
        if ($p === '' || $p[0] === '<') continue;
        $parts[$i] = preg_replace('#\b(https?://[^\s<"\']*[^\s<"\'.,;:!?)\]])#i', '<a href="$1" style="color:#1D4D7A">$1</a>', $p);
    }
    return implode('', $parts);
}

/**
 * Template text → HTML. SECURITY: markdown is applied to the TEMPLATE only (written by admins); the
 * values (ticket subjects, comments, names — often typed by outsiders) are inserted afterwards,
 * escaped, so a value like "[Reset password](https://evil)" stays plain text. A placeholder used as a
 * link target, e.g. [Open]({{link}}), only accepts an http(s)/mailto value. {{report}} is generated
 * by the system and may contain "- " lists, so it is rendered as markdown (after escaping).
 */
function opsh_tpl_html(string $tpl, array $vars, string $lang): string {
    $val = function (string $n) use ($vars, $lang): string { $v = $vars[$n] ?? ''; if (is_array($v)) $v = $v[$lang] ?? ($v['en'] ?? ''); return (string)$v; };
    $esc = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $t = $esc($tpl);
    // 1) placeholders used as link targets
    $t = preg_replace_callback('/\]\(\s*\{\{\s*([A-Za-z0-9_]+)\s*\}\}\s*\)/', function ($m) use ($val, $esc) {
        $u = trim($val($m[1]));
        if (!preg_match('#^(https?://|mailto:)#i', $u)) return '](#)';
        return '](' . $esc(str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $u)) . ')';
    }, $t);
    // 2) markdown on the template text
    $t = opsh_tpl_md_html($t);
    // 3) values, escaped
    // "Follow it online" box: rendered as a callout with a button (value = "text — [label](url)")
    $t = preg_replace_callback('#<p style="margin:0 0 14px">\{\{\s*portal_link\s*\}\}</p>#', function () use ($val, $esc) {
        $v = trim($val('portal_link'));
        if ($v === '' || !preg_match('/^(.*?)\s*—?\s*\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)\s*$/su', $v, $m)) return $v === '' ? '' : opsh_tpl_md_html($esc($v));
        $color = opsh_tpl_setting('tpl_brand_color') ?: '#1D4D7A'; if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#1D4D7A';
        $intro = preg_replace('#^<p[^>]*>|</p>$#', '', opsh_tpl_md_html($esc(rtrim($m[1], " —-"))));
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 14px"><tr><td style="background:#F3F7FB;border:1px solid #D8E2EC;border-radius:8px;padding:14px 16px;font-size:14px;line-height:1.5;color:#1F2A36">'
             . $intro . '<br><a href="' . $esc($m[3]) . '" style="display:inline-block;margin-top:10px;background:' . $color . ';color:#ffffff;text-decoration:none;font-weight:600;padding:9px 16px;border-radius:6px">' . $esc($m[2]) . '</a></td></tr></table>';
    }, $t);
    $blockVars = ['report', 'portal_link', 'body_md', 'csat_line'];   // csat_line (BT81): system-made rating links   // body_md (BT28): announcement text, written by a role allowed to publish
    $t = preg_replace_callback('#<p style="margin:0 0 14px">\{\{\s*(' . implode('|', $blockVars) . ')\s*\}\}</p>#', fn($m) => opsh_tpl_md_html($esc($val($m[1]))), $t);
    $t = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($m) use ($val, $esc, $blockVars) {
        $v = $val($m[1]);
        if (in_array($m[1], $blockVars, true)) return preg_replace('#^<p[^>]*>|</p>$#', '', opsh_tpl_md_html($esc($v)));
        // BT124 (code review DESK-12): only addresses the system made become links; a web address inside a subject, a
        // comment or a name (often typed by someone outside) stays plain text
        if ($m[1] === 'link' || $m[1] === 'url' || preg_match('/_(link|url)$/', $m[1])) return opsh_tpl_autolink(nl2br($esc($v), false));
        return nl2br($esc($v), false);
    }, $t);
    // BT28: a paragraph that held only an empty optional value (e.g. {{ack_line}}) leaves no blank gap
    return preg_replace('#<p style="margin:0 0 14px">\s*</p>\n?#', '', $t);
}

/** Markdown-lite → readable plain text. */
function opsh_tpl_md_text(string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1: $2', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '$1', $s);
    return trim(preg_replace('/\n{3,}/', "\n\n", $s));
}

function opsh_tpl_common_values(array $vars, string $lang): array {
    $org = opsh_tpl_setting('app_name') ?: (opsh_tpl_setting('branding_name') ?: (function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub'));
    $name = trim((string)($vars['recipient_name'] ?? ''));
    $first = $name !== '' ? preg_split('/\s+/', $name)[0] : '';
    return [
        'org_name' => $org,
        'app_name' => (function_exists('opsh_app_name') ? opsh_app_name() : $org),   // BT20: {{app_name}} in every template
        'helpdesk_name' => opsh_tpl_setting('mail_from_name') ?: 'IT Help Desk',
        'helpdesk_email' => opsh_tpl_setting('mail_from_email') ?: '',
        'app_url' => defined('APP_URL') ? rtrim(APP_URL, '/') : '',
        'recipient_name' => $name,
        'greeting' => ['en' => $first !== '' ? "Hello $first," : 'Hello,', 'es' => $first !== '' ? "Hola $first," : 'Hola,'],
    ];
}

/** Render one language. $override = ['subject'=>..,'body'=>..] to preview unsaved edits. */
function opsh_tpl_render_one(string $key, string $lang, array $vars, ?array $override = null): array {
    $cat = opsh_tpl_catalog(); $def = $cat[$key];
    $src = $override ?: (opsh_tpl_override($key, $lang) ?: null);
    $d = $lang === 'es' ? $def[8] : $def[7];
    $subjectT = $src['subject'] ?? $d[0];
    $bodyT = $src['body'] ?? $d[1];
    if (in_array($key, opsh_tpl_portal_keys(), true) && strpos($bodyT, 'portal_link') === false) $bodyT = rtrim($bodyT) . "\n\n{{portal_link}}";
    $vars = array_merge(opsh_tpl_common_values($vars, $lang), $vars);
    if (!isset($vars['link']) || $vars['link'] === '') $vars['link'] = $vars['app_url'];
    $subject = trim(preg_replace('/\s+/', ' ', opsh_tpl_fill($subjectT, $vars, $lang, false)));
    $wrap = opsh_tpl_wrapper($lang);
    // Plain text: markdown applied to the template, then raw values filled in (no markup in plain text).
    $tvars = $vars;   // plain text: markdown inside block values (the portal link) becomes readable text
    foreach (['portal_link', 'body_md', 'csat_line'] as $bv) if (isset($tvars[$bv])) { $x = $tvars[$bv]; $tvars[$bv] = is_array($x) ? array_map('opsh_tpl_md_text', $x) : opsh_tpl_md_text((string)$x); }
    $text = trim(preg_replace('/\n{3,}/', "\n\n", opsh_tpl_fill(opsh_tpl_md_text(($wrap['header'] ? $wrap['header'] . "\n\n" : '') . $bodyT), $tvars, $lang, false)))
          . ($wrap['footer'] ? "\n\n--\n" . opsh_tpl_tidy(opsh_tpl_fill(opsh_tpl_md_text($wrap['footer']), $vars, $lang, false)) : '');
    $html = ($wrap['header'] ? opsh_tpl_html($wrap['header'], $vars, $lang) : '') . opsh_tpl_html($bodyT, $vars, $lang);
    $foot = '';
    if ($wrap['footer']) {   // drop separators left dangling by empty values ("IT Help Desk · " when there is no org name)
        $foot = opsh_tpl_html($wrap['footer'], $vars, $lang);
        $foot = preg_replace(['#(<p[^>]*>|<br>)\s*(·|\||,)\s*#u', '#\s*(·|\||,)\s*(<br>|</p>)#u', '#<br>\s*(<br>|</p>)#'], ['$1', '$2', '$1'], $foot);
    }
    // BT20: templates saved before {{app_name}} existed may still say "OpsHub" — show the client's name instead.
    return ['subject' => opsh_brand($subject), 'text' => opsh_brand($text), 'body_html' => opsh_brand($html), 'footer_html' => opsh_brand($foot), 'org' => $vars['org_name']];
}

/** Branded, Outlook-safe HTML shell around rendered content. */
function opsh_tpl_shell(string $org, string $contentHtml, string $footerHtml, string $lang): string {
    $color = opsh_tpl_setting('tpl_brand_color') ?: '#1D4D7A';
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#1D4D7A';
    $logo = opsh_tpl_setting('app_logo_url') ?: '';
    if ($logo && !preg_match('#^https?://#i', $logo) && defined('APP_URL')) $logo = rtrim(APP_URL, '/') . '/' . ltrim($logo, '/');
    $brand = ($logo && preg_match('#^https://#i', $logo) && opsh_tpl_setting('tpl_show_logo', '1') === '1')
        ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($org, ENT_QUOTES, 'UTF-8') . '" style="max-height:40px;max-width:200px;display:block">'
        : '<span style="font-size:18px;font-weight:700;color:#ffffff">' . htmlspecialchars($org, ENT_QUOTES, 'UTF-8') . '</span>';
    return '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
      . '<body style="margin:0;padding:0;background:#F2F5F8">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F5F8"><tr><td align="center" style="padding:24px 12px">'
      . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #DCE3EA">'
      . '<tr><td style="background:' . $color . ';padding:16px 24px">' . $brand . '</td></tr>'
      . '<tr><td style="padding:24px;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#1F2A36">' . $contentHtml . '</td></tr>'
      . ($footerHtml ? '<tr><td style="padding:14px 24px;border-top:1px solid #E6EBF0;font-family:Segoe UI,Arial,Helvetica,sans-serif;font-size:12.5px;line-height:1.5;color:#6B7785">' . $footerHtml . '</td></tr>' : '')
      . '</table></td></tr></table></body></html>';
}

/** Full render for a language mode (en | es | both). */
function opsh_tpl_render(string $key, string $mode, array $vars, ?array $override = null, ?string $overrideLang = null): array {
    $langs = $mode === 'both' ? ['en', 'es'] : [$mode === 'es' ? 'es' : 'en'];
    $parts = [];
    foreach ($langs as $l) $parts[$l] = opsh_tpl_render_one($key, $l, $vars, ($override && $overrideLang === $l) ? $override : null);
    $first = reset($parts);
    if (count($parts) === 1) {
        return ['subject' => $first['subject'], 'text' => $first['text'], 'html' => opsh_tpl_shell($first['org'], $first['body_html'], $first['footer_html'], $langs[0])];
    }
    $en = $parts['en']; $es = $parts['es'];
    $sep = '<div style="border-top:1px dashed #C9D3DD;margin:18px 0 16px;padding-top:6px;font-size:12px;color:#8793A0;letter-spacing:.04em">ESPAÑOL</div>';
    return ['subject' => $en['subject'],
            'text' => $en['text'] . "\n\n— — — ESPAÑOL — — —\n\n" . $es['text'],
            'html' => opsh_tpl_shell($en['org'], $en['body_html'] . $sep . $es['body_html'], $en['footer_html'], 'en')];
}

/** Per-language label / date pairs for placeholders whose value depends on the recipient's language. */
function opsh_tpl_l10n(string $kind, string $v): array { return ['en' => opsh_tpl_label($kind, $v, 'en'), 'es' => opsh_tpl_label($kind, $v, 'es')]; }
function opsh_tpl_d2(?string $d): array { return ['en' => opsh_tpl_date($d, 'en'), 'es' => opsh_tpl_date($d, 'es')]; }

/** Recipient language: the user's own setting, else the organisation default (en | es | both). */
function opsh_email_lang(?string $to, ?int $uid = null): string {
    try {
        $db = getDB();
        if ($uid) { $st = $db->prepare('SELECT language FROM opsh_users WHERE id=?'); $st->execute([$uid]); }
        elseif ($to) { $st = $db->prepare('SELECT language FROM opsh_users WHERE email=? LIMIT 1'); $st->execute([$to]); }
        if (isset($st)) { $l = $st->fetchColumn(); if ($l === 'en' || $l === 'es') return $l; }
    } catch (Throwable $e) {}
    $d = opsh_tpl_setting('mail_default_lang', 'en');
    return in_array($d, ['en', 'es', 'both'], true) ? $d : 'en';
}

/** Render + send a system email. Returns the mail_send result (or ['skipped'=>true] when disabled). */
function opsh_email(string $key, string $to, array $vars = [], array $opt = []): array {
    $cat = opsh_tpl_catalog();
    if (!isset($cat[$key])) return ['ok' => false, 'error' => 'Unknown email template ' . $key];
    // BT101: no email of a module that is not part of this installation (test sends included)
    require_once __DIR__ . '/Modules.php';
    if (($tm = opsh_tpl_module($key)) !== null && !opsh_module_on($tm)) return ['ok' => false, 'skipped' => true, 'error' => 'Email "' . $cat[$key][1] . '" belongs to ' . (opsh_modules_catalog()[$tm][0] ?? $tm) . ', which is not part of this installation'];
    if (empty($opt['force']) && !opsh_tpl_enabled($key)) return ['ok' => false, 'skipped' => true, 'error' => 'Email "' . $cat[$key][1] . '" is switched off'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid recipient'];
    // Outbound switched off: skip quietly (not logged) unless this is an explicit test send.
    if (empty($opt['force']) && opsh_tpl_setting('mail_out_enabled', '0') !== '1') return ['ok' => false, 'skipped' => true, 'error' => 'Outbound email is disabled'];
    if (!isset($vars['recipient_name'])) {
        try { $st = getDB()->prepare('SELECT display_name FROM opsh_users WHERE email=? LIMIT 1'); $st->execute([$to]); $vars['recipient_name'] = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) {}
    }
    // Ticket emails: links open that ticket in the app; people with an OpsHub account also get the "follow it online" box.
    if (!empty($opt['ticket_id'])) {
        $turl = opsh_tpl_ticket_url((int)$opt['ticket_id']);
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        if (!isset($vars['link']) || $vars['link'] === '' || rtrim((string)$vars['link'], '/') === $base) $vars['link'] = $turl;
        if (!isset($vars['portal_link'])) {
            $hasAccount = false;
            try {
                $st = getDB()->prepare(!empty($opt['user_id']) ? "SELECT 1 FROM opsh_users WHERE id=? AND status='active'" : "SELECT 1 FROM opsh_users WHERE email=? AND status='active' LIMIT 1");
                $st->execute([!empty($opt['user_id']) ? (int)$opt['user_id'] : $to]); $hasAccount = (bool)$st->fetchColumn();
            } catch (Throwable $e) {}
            $vars['portal_link'] = $hasAccount ? opsh_tpl_portal_link($turl, (string)($vars['ticket_number'] ?? '')) : '';
        }
    }
    $lang = $opt['lang'] ?? opsh_email_lang($to, $opt['user_id'] ?? null);
    $r = opsh_tpl_render($key, $lang, $vars);
    if (!function_exists('mail_send')) require_once __DIR__ . '/MailIntake.php';
    return mail_send($to, $r['subject'], $r['text'], ['kind' => $key, 'ticket_id' => $opt['ticket_id'] ?? null, 'html' => $r['html'], 'lang' => $lang]);
}

}
