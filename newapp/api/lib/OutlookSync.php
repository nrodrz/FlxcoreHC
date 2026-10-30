<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — two-way sync with each person's Outlook / Teams calendar (BT66).
 *
 * Teams has no calendar of its own: it shows the person's Outlook (Exchange) calendar. So ITOps writes into the
 * person's Outlook calendar through Microsoft Graph (application permission Calendars.ReadWrite, admin consent) and
 * the items show in Outlook, Teams and phones, with Outlook's own reminder.
 *
 * What goes to a person's Outlook calendar (each person chooses; the organisation sets the defaults):
 *   events   ITOps events they organize — as a real Outlook meeting with the attendees (Exchange sends the invitations,
 *            answers come back), with a Teams link for meetings; ITOps events they attend when the organizer does
 *            not sync (a copy)
 *   tickets  response / resolution due times of the open tickets assigned to them
 *   dates    contract ends, SOP reviews, credential terms and rotations, warranty ends of the items they own
 *
 * Two-way: every few minutes ITOps reads back the Outlook events it made (by changeKey).
 *   - The organizer moved, renamed or relocated an ITOps event in Outlook → ITOps takes the change (Audit Log).
 *   - The organizer deleted it or cancelled it in Outlook → the ITOps event is set to Cancelled (kept, not deleted).
 *   - Attendees accepted / declined in Outlook or Teams → their answers show in ITOps.
 *   - Tickets and dates are owned by ITOps: a change made to them in Outlook is put back; deleting one in Outlook
 *     hides it until the ticket or date changes again.
 * A change made in ITOps reaches Outlook on the next run (about every 3 minutes with the per-minute cron).
 *
 * From Outlook into ITOps (BT68): an event a person creates in Outlook or Teams comes into ITOps when they give it one of
 * the organisation's categories (default: the app's name and "ITOps"; the match ignores upper / lower case).
 *   - They organize it → it becomes their ITOps event (origin 'outlook'), linked to that Outlook meeting and synced both
 *     ways from then on, like an event made in ITOps (ITOps does not replace its Outlook body or categories).
 *   - An invitation from someone else → an ITOps event they own (origin 'outlook_invite', link role 'invite'): it follows
 *     the organizer's changes and cancellation; ITOps never writes to the invitation.
 *   - Never twice: an Outlook event ITOps already knows (any link, the same iCalUId, or an item ITOps made) is skipped.
 *   - Removing the category later does not remove the ITOps event; deleting it in Outlook cancels it.
 *
 * Never: a password or token in a log; another person's private event to someone who may not see it (only the
 * organizer and attendees get an event); a meeting cancelled for everyone because someone switched their sync off
 * (their meetings are left in Outlook and simply no longer synced).
 */
require_once __DIR__ . '/Calendar.php';
require_once __DIR__ . '/Perms.php';
if (!function_exists('itops_log') && is_file(__DIR__ . '/EventLog.php')) require_once __DIR__ . '/EventLog.php';
if (!function_exists('itops_graph_http') && is_file(__DIR__ . '/Graph.php')) require_once __DIR__ . '/Graph.php';

if (!function_exists('itops_os_ready')) {
    // BT131: the Windows time-zone name Graph's calendar calls expect, for the installation's zone (config.php may still set ITOPS_OS_TZ)
    function itops_os_tz(): string { if (defined('ITOPS_OS_TZ')) return (string)ITOPS_OS_TZ; require_once __DIR__ . '/TimeZone.php'; return itops_tz_windows(date_default_timezone_get()); }

    function itops_os_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_outlook_links LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
    function itops_os_kinds(): array {
        return ['events' => 'Events I organize or attend', 'tickets' => "My tickets' due times", 'dates' => 'Dates I own (contract ends, SOP reviews, credentials, warranties)'];
    }
    function itops_os_setting(PDO $db, string $k): ?string { try { $s = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? null : (string)$v; } catch (Throwable $e) { return null; } }
    function itops_os_setting_put(PDO $db, string $k, string $v): void { $db->prepare('INSERT INTO itops_settings (skey, sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')->execute([$k, $v]); }

    /** The organisation's settings. */
    function itops_os_cfg(PDO $db): array {
        $d = ['enabled' => false, 'default_on' => true, 'default_kinds' => ['events', 'tickets', 'dates'], 'days_ahead' => 90, 'teams' => true, 'import' => true, 'import_categories' => itops_os_default_categories()];
        $j = json_decode((string)itops_os_setting($db, 'outlook_sync'), true);
        if (!is_array($j)) return $d;
        try { return itops_os_cfg_clean($j + $d); } catch (InvalidArgumentException $e) { return $d; }
    }
    /** The categories that bring an Outlook event in, by default: the app's name and "ITOps" (one when they are the same). */
    function itops_os_default_categories(): array { return itops_os_cats_unique([itops_app_name_safe(), 'ITOps']); }
    function itops_os_cats_unique(array $c): array { $o = []; foreach ($c as $x) { $x = trim((string)$x); if ($x !== '' && !isset($o[mb_strtolower($x)])) $o[mb_strtolower($x)] = $x; } return array_values($o); }
    function itops_os_cfg_clean(array $in): array {
        $kinds = array_values(array_intersect(array_keys(itops_os_kinds()), (array)($in['default_kinds'] ?? [])));
        $days = (int)($in['days_ahead'] ?? 90); if ($days < 14 || $days > 365) throw new InvalidArgumentException('How far ahead: 14 to 365 days.');
        $cats = $in['import_categories'] ?? itops_os_default_categories(); if (is_string($cats)) $cats = explode(',', $cats);
        if (!is_array($cats)) throw new InvalidArgumentException('The categories are a list of names.');
        $cats = itops_os_cats_unique(array_map(fn($x) => preg_replace('/\s+/u', ' ', (string)$x), $cats));
        if (!$cats || count($cats) > 5) throw new InvalidArgumentException('Give 1 to 5 Outlook category names.');
        foreach ($cats as $c) if (mb_strlen($c) > 60 || preg_match('/[\x00-\x1F]/', $c)) throw new InvalidArgumentException('An Outlook category name is at most 60 characters.');
        return ['enabled' => !empty($in['enabled']), 'default_on' => !empty($in['default_on']), 'default_kinds' => $kinds, 'days_ahead' => $days, 'teams' => !empty($in['teams']),
                'import' => !empty($in['import']), 'import_categories' => $cats];
    }
    /** A person's effective choice (their own, or the organisation's default). */
    function itops_os_pref(PDO $db, int $uid, ?array $cfg = null): array {
        $cfg = $cfg ?? itops_os_cfg($db);
        $s = $db->prepare('SELECT * FROM itops_outlook_users WHERE user_id=?'); $s->execute([$uid]); $r = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $kinds = $r && $r['kinds'] !== null ? array_values(array_intersect(array_keys(itops_os_kinds()), (array)json_decode((string)$r['kinds'], true))) : $cfg['default_kinds'];
        return ['enabled' => $r && $r['enabled'] !== null ? (bool)$r['enabled'] : (bool)$cfg['default_on'], 'kinds' => $kinds,
                'own_choice' => $r && ($r['enabled'] !== null || $r['kinds'] !== null),
                'last_sync_at' => $r['last_sync_at'] ?? null, 'last_ok_at' => $r['last_ok_at'] ?? null, 'last_error' => (string)($r['last_error'] ?? ''), 'items' => (int)($r['items'] ?? 0)];
    }
    function itops_os_pref_save(PDO $db, int $uid, bool $enabled, array $kinds): void {
        $kinds = array_values(array_intersect(array_keys(itops_os_kinds()), $kinds));
        $db->prepare('INSERT INTO itops_outlook_users (user_id, enabled, kinds) VALUES (?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), kinds=VALUES(kinds)')->execute([$uid, $enabled ? 1 : 0, json_encode($kinds)]);
    }
    /** A person with their role, as the API sees them (the same permission rules). */
    function itops_os_user(PDO $db, int $uid): ?array {
        $s = $db->prepare("SELECT u.*, r.slug AS role_slug, r.permissions AS role_perms FROM itops_users u LEFT JOIN itops_roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active'");
        $s->execute([$uid]); $u = $s->fetch(PDO::FETCH_ASSOC); return $u ?: null;
    }
    function itops_os_mailbox(array $u): string { $m = strtolower(trim((string)($u['email'] ?? ''))); return filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : ''; }
    /** Does the Microsoft 365 app have Calendars.ReadWrite? ok | missing | unknown (the token carries no roles) | off */
    function itops_os_permission(): array {
        if (!function_exists('itops_graph_configured') || !itops_graph_configured()) return ['state' => 'off', 'message' => 'Microsoft 365 is not connected (Microsoft 365 → Connection).'];
        try { $roles = (array)(itops_graph_jwt_claims(itops_graph_token())['roles'] ?? []); }
        catch (Throwable $e) { return ['state' => 'error', 'message' => 'Could not get a Microsoft 365 token: ' . $e->getMessage()]; }
        if (!$roles) return ['state' => 'unknown', 'message' => 'The token lists no permissions; the first sync will tell.'];
        return in_array('Calendars.ReadWrite', $roles, true) ? ['state' => 'ok', 'message' => 'Calendars.ReadWrite is granted.']
            : ['state' => 'missing', 'message' => 'The Microsoft 365 app needs the Application permission Calendars.ReadWrite with admin consent (Azure → App registrations → API permissions).'];
    }

    // ── Graph ──
    function itops_os_graph(string $method, string $path, ?array $body = null): array {
        $url = 'https://graph.microsoft.com/v1.0/' . ltrim($path, '/');
        [$code, $data] = itops_graph_http($method, $url, $body, ['Prefer: outlook.timezone="' . itops_os_tz() . '"'], 25);
        return [(int)$code, is_array($data) ? $data : []];
    }
    function itops_os_gerr(int $code, array $d): string { $m = (string)($d['error']['message'] ?? ''); $c = (string)($d['error']['code'] ?? ''); return 'HTTP ' . $code . ($c !== '' ? ' ' . $c : '') . ($m !== '' ? ': ' . mb_substr($m, 0, 200) : ''); }
    /** A failure that concerns the whole mailbox (no mailbox, no permission) rather than one item. */
    function itops_os_mailbox_err(int $code, array $d): ?string {
        $c = (string)($d['error']['code'] ?? '');
        if ($code === 401 || $code === 403) return 'Microsoft 365 refused access to this calendar (' . itops_os_gerr($code, $d) . '). Check the app permission Calendars.ReadWrite and any Exchange limit on which mailboxes the app may use.';
        if ($code === 404 && in_array($c, ['MailboxNotEnabledForRESTAPI', 'ResourceNotFound', 'ErrorInvalidUser', 'Request_ResourceNotFound'], true)) return 'No Outlook mailbox for this email address (' . itops_os_gerr($code, $d) . ').';
        return null;
    }
    function itops_os_dt(string $s): string { return date('Y-m-d\TH:i:s', strtotime($s)); }
    function itops_os_from_graph(array $t): string { $v = (string)($t['dateTime'] ?? ''); return $v === '' ? '' : str_replace('T', ' ', substr($v, 0, 19)); }

    // ── what a person's Outlook calendar should have ──
    function itops_os_recurrence(array $e): ?array {
        $r = $e['recur']; if (!$r) return null;
        $ts = strtotime($e['start']); $dn = ['MO' => 'monday', 'TU' => 'tuesday', 'WE' => 'wednesday', 'TH' => 'thursday', 'FR' => 'friday', 'SA' => 'saturday', 'SU' => 'sunday'];
        $wd = strtolower(date('l', $ts)); $i = (int)$r['interval'];
        switch ($r['freq']) {
            case 'daily': $p = ['type' => 'daily', 'interval' => $i]; break;
            case 'weekly': $p = ['type' => 'weekly', 'interval' => $i, 'daysOfWeek' => array_map(fn($d) => $dn[$d], $r['byday']), 'firstDayOfWeek' => 'sunday']; break;
            case 'monthly':
                if (($r['monthly'] ?? 'day') === 'day') { $p = ['type' => 'absoluteMonthly', 'interval' => $i, 'dayOfMonth' => (int)date('j', $ts)]; break; }
                $n = intdiv((int)date('j', $ts) - 1, 7) + 1;
                $p = ['type' => 'relativeMonthly', 'interval' => $i, 'daysOfWeek' => [$wd], 'index' => ($r['monthly'] === 'last' || $n >= 5) ? 'last' : ['first', 'second', 'third', 'fourth'][$n - 1]];
                break;
            default: $p = ['type' => 'absoluteYearly', 'interval' => $i, 'month' => (int)date('n', $ts), 'dayOfMonth' => (int)date('j', $ts)];
        }
        $range = ['type' => 'noEnd', 'startDate' => date('Y-m-d', $ts), 'recurrenceTimeZone' => itops_os_tz()];
        if (!empty($r['until'])) $range = ['type' => 'endDate', 'startDate' => date('Y-m-d', $ts), 'endDate' => $r['until'], 'recurrenceTimeZone' => itops_os_tz()];
        elseif (!empty($r['count'])) $range = ['type' => 'numbered', 'startDate' => date('Y-m-d', $ts), 'numberOfOccurrences' => (int)$r['count'], 'recurrenceTimeZone' => itops_os_tz()];
        return ['pattern' => $p, 'range' => $range];
    }
    function itops_os_link(string $hash): string { return defined('APP_URL') && APP_URL !== '' && strpos((string)APP_URL, '%%') === false ? rtrim((string)APP_URL, '/') . '/#' . $hash : ''; }
    function itops_os_html(string $text, string $link, string $linkLabel, array $extra = []): array {
        $h = $text !== '' ? '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>' : '';
        foreach ($extra as $x) $h .= '<p>' . $x . '</p>';
        if ($link !== '') $h .= '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($linkLabel, ENT_QUOTES, 'UTF-8') . '</a></p>';
        $h .= '<p style="color:#7A8794;font-size:12px">' . htmlspecialchars(itops_app_name_safe(), ENT_QUOTES, 'UTF-8') . ' keeps this item up to date.</p>';
        return ['contentType' => 'HTML', 'content' => $h];
    }
    function itops_app_name_safe(): string { return function_exists('itops_app_name') ? itops_app_name() : 'ITOps'; }
    /** The core of an item, the same shape whether ITOps sent it or Outlook returned it (to tell a real Outlook edit). */
    function itops_os_core(array $p): array {
        return ['subject' => (string)($p['subject'] ?? ''), 'start' => substr(itops_os_from_graph((array)($p['start'] ?? [])), 0, 16), 'end' => substr(itops_os_from_graph((array)($p['end'] ?? [])), 0, 16),
                'all_day' => !empty($p['isAllDay']), 'location' => (string)($p['location']['displayName'] ?? '')];
    }
    /** Write to the Audit Log as someone else (the organizer "in Outlook", or the sync itself), then put the caller back. */
    function itops_os_audit_as(?int $uid, string $name, callable $f): void {
        $o = [$_SERVER['ITOPS_USER_ID'] ?? null, $_SERVER['ITOPS_USER_NAME'] ?? null];
        if ($uid === null) unset($_SERVER['ITOPS_USER_ID']); else $_SERVER['ITOPS_USER_ID'] = $uid; $_SERVER['ITOPS_USER_NAME'] = $name;
        try { $f(); } finally { if ($o[0] === null) unset($_SERVER['ITOPS_USER_ID']); else $_SERVER['ITOPS_USER_ID'] = $o[0]; if ($o[1] === null) unset($_SERVER['ITOPS_USER_NAME']); else $_SERVER['ITOPS_USER_NAME'] = $o[1]; }
    }
    function itops_os_core_diff(array $a, array $b): bool { foreach (['subject', 'start', 'end', 'all_day', 'location'] as $k) if ((string)json_encode($a[$k] ?? null) !== (string)json_encode($b[$k] ?? null)) return true; return false; }
    /** One event in the shape the run keeps (after a change from Outlook, so the push uses the new values). */
    function itops_os_cache_event(PDO $db, int $eid): ?array {
        $e = itops_cal_get($db, $eid); if (!$e || $e['status'] === 'cancelled') return null;
        $e['owner_name'] = (string)$db->query('SELECT display_name FROM itops_users WHERE id=' . (int)$e['owner_id'])->fetchColumn();
        $e['attendees'] = array_map(fn($a) => ['user_id' => $a['user_id'], 'email' => $a['email'], 'name' => $a['name']], $e['attendees']);
        return $e;
    }
    function itops_os_event_payload(array $e, string $role, array $types, array $att, string $ownerName): array {
        $t = itops_cal_type($types, $e['event_type']) ?? ['minutes' => 60];
        if ($e['all_day']) { $s = substr($e['start'], 0, 10); $en = date('Y-m-d', strtotime(substr($e['end'] ?: $e['start'], 0, 10) . ' +1 day'));
            $start = ['dateTime' => $s . 'T00:00:00', 'timeZone' => itops_os_tz()]; $end = ['dateTime' => $en . 'T00:00:00', 'timeZone' => itops_os_tz()]; }
        else { $endTs = $e['end'] ? strtotime($e['end']) : strtotime($e['start']) + max(15, (int)$t['minutes']) * 60; if ($endTs <= strtotime($e['start'])) $endTs = strtotime($e['start']) + 900;
            $start = ['dateTime' => itops_os_dt($e['start']), 'timeZone' => itops_os_tz()]; $end = ['dateTime' => date('Y-m-d\TH:i:s', $endTs), 'timeZone' => itops_os_tz()]; }
        $extra = [];
        if ($e['online_url'] !== '' && !preg_match('~^https://teams\.(microsoft|live)\.com/~i', $e['online_url'])) $extra[] = 'Online: <a href="' . htmlspecialchars($e['online_url'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($e['online_url'], ENT_QUOTES, 'UTF-8') . '</a>';
        if ($role === 'copy') $extra[] = 'Organizer: ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . ' (in ' . htmlspecialchars(itops_app_name_safe(), ENT_QUOTES, 'UTF-8') . ')';
        $rem = array_map('intval', (array)$e['reminders']); sort($rem);
        $p = ['subject' => mb_substr($e['title'], 0, 255), 'body' => itops_os_html($e['description'], itops_os_link('calendar/' . $e['id']), 'Open in ' . itops_app_name_safe(), $extra),
              'start' => $start, 'end' => $end, 'isAllDay' => (bool)$e['all_day'], 'location' => ['displayName' => mb_substr($e['location'], 0, 200)],
              'categories' => [itops_app_name_safe()], 'showAs' => $e['event_type'] === 'out_of_office' ? 'oof' : (in_array($e['event_type'], ['deadline', 'closure'], true) ? 'free' : 'busy'),
              'sensitivity' => $e['visibility'] === 'private' ? 'private' : 'normal',
              'isReminderOn' => (bool)$rem, 'reminderMinutesBeforeStart' => $rem ? $rem[0] : 0, 'recurrence' => itops_os_recurrence($e)];
        if (($e['origin'] ?? '') !== '') unset($p['body'], $p['categories']);   // BT68: made in Outlook — its own body (Teams details) and categories stay
        if ($role === 'organizer') {
            $list = []; foreach ($att as $a) { $m = strtolower(trim((string)$a['email'])); if ($m === '' || !filter_var($m, FILTER_VALIDATE_EMAIL)) continue; $list[$m] = ['emailAddress' => ['address' => $m, 'name' => (string)$a['name']], 'type' => 'required']; }
            ksort($list); $p['attendees'] = array_values($list);
        }
        return $p;
    }
    function itops_os_item_payload(string $subject, string $start, ?string $end, bool $allDay, string $body, string $link, string $linkLabel, int $reminder): array {
        if ($allDay) { $d = substr($start, 0, 10); $s = ['dateTime' => $d . 'T00:00:00', 'timeZone' => itops_os_tz()]; $en = ['dateTime' => date('Y-m-d', strtotime($d . ' +1 day')) . 'T00:00:00', 'timeZone' => itops_os_tz()]; }
        else { $s = ['dateTime' => itops_os_dt($start), 'timeZone' => itops_os_tz()]; $en = ['dateTime' => itops_os_dt($end ?? date('Y-m-d H:i:s', strtotime($start) + 900)), 'timeZone' => itops_os_tz()]; }
        return ['subject' => mb_substr($subject, 0, 255), 'body' => itops_os_html($body, $link, $linkLabel), 'start' => $s, 'end' => $en, 'isAllDay' => $allDay, 'location' => ['displayName' => ''],
                'categories' => [itops_app_name_safe()], 'showAs' => 'free', 'sensitivity' => 'normal', 'isReminderOn' => $reminder >= 0, 'reminderMinutesBeforeStart' => max(0, $reminder), 'recurrence' => null];
    }
    /** The run's shared reads: events in the window with their attendees, the event kinds, who syncs their events. */
    function itops_os_run_cache(PDO $db, array $cfg, array $orgSync): array {
        $from = date('Y-m-d', time() - 7 * 86400); $to = date('Y-m-d', time() + $cfg['days_ahead'] * 86400);
        $s = $db->prepare("SELECT e.*, u.display_name AS owner_name FROM itops_calendar_events e LEFT JOIN itops_users u ON u.id = COALESCE(e.owner_id, e.created_by)
                           WHERE e.status <> 'cancelled' AND e.start_date <= ? AND (e.recur IS NOT NULL OR COALESCE(e.end_date, e.start_date) >= ?)");
        $s->execute([$to . ' 23:59:59', $from . ' 00:00:00']);
        $ev = []; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $e = itops_cal_row($r); if (!itops_cal_occurrences($e, $from, $to)) continue; $e['owner_name'] = (string)($r['owner_name'] ?? ''); $e['attendees'] = []; $ev[$e['id']] = $e; }
        if ($ev) { foreach ($db->query('SELECT a.event_id, a.user_id, a.email, a.name, u.display_name, u.email AS uemail FROM itops_calendar_attendees a LEFT JOIN itops_users u ON u.id=a.user_id WHERE a.event_id IN (' . implode(',', array_keys($ev)) . ') ORDER BY a.id')->fetchAll(PDO::FETCH_ASSOC) as $a)
            $ev[(int)$a['event_id']]['attendees'][] = ['user_id' => $a['user_id'] ? (int)$a['user_id'] : null, 'email' => (string)($a['user_id'] ? $a['uemail'] : $a['email']), 'name' => (string)($a['user_id'] ? $a['display_name'] : $a['name'])]; }
        $sa = []; try { $sa = array_map('intval', $db->query("SELECT u.id FROM itops_users u JOIN itops_roles r ON r.id=u.role_id WHERE r.slug='super_admin'")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
        return ['from' => $from, 'to' => $to, 'events' => $ev, 'types' => itops_cal_types($db), 'org' => $orgSync, 'sa' => $sa];
    }
    /** item_key => [role, payload, date (Y-m-d H:i:s), teams, exdates, event_id] for one person. */
    function itops_os_desired(PDO $db, array $user, array $pref, array $cfg, array $C): array {
        $uid = (int)$user['id']; $out = [];
        if (!$pref['enabled']) return [];
        if (in_array('events', $pref['kinds'], true) && itops_can($user, 'calendar', 'view')) {
            foreach ($C['events'] as $e) {
                $att = $e['attendees']; $attIds = array_map(fn($a) => (int)$a['user_id'], array_filter($att, fn($a) => $a['user_id']));
                $owner = (int)$e['owner_id']; $origin = (string)($e['origin'] ?? '');
                $cb = (int)($e['created_by'] ?? 0);   // BT86 (code audit C-2): invitations go out from a mailbox only for an event its organizer made (or a Super Admin, or the app for a case meeting)
                $trusted = $origin !== '' || !$cb || $cb === $owner || ($e['entity_type'] ?? '') === 'case' || in_array($cb, (array)($C['sa'] ?? []), true);
                if ($owner === $uid) $role = $origin === 'outlook_invite' ? 'invite' : ($trusted ? 'organizer' : 'copy');
                elseif (in_array($uid, $attIds, true) && (empty($C['org'][$owner]) || !$trusted) && $origin === '') $role = 'copy';   // an event from Outlook: the attendees already have the real invitation
                else continue;
                if ($role === 'invite') { $out['ev:' . $e['id']] = ['role' => 'invite', 'payload' => ['subject' => $e['title'], 'start' => $e['start'], 'end' => $e['end']], 'date' => $e['start'], 'teams' => false, 'exdates' => [], 'event_id' => $e['id']]; continue; }
                $att = array_values(array_filter($att, fn($a) => strtolower((string)$a['email']) !== itops_os_mailbox($user)));
                $out['ev:' . $e['id']] = ['role' => $role, 'payload' => itops_os_event_payload($e, $role, $C['types'], $att, $e['owner_name']), 'date' => $e['start'],
                    'teams' => $role === 'organizer' && $cfg['teams'] && $e['event_type'] === 'meeting' && $e['online_url'] === '', 'exdates' => (array)$e['exdates'], 'event_id' => $e['id']];
            }
        }
        $f = $C['from'] . ' 00:00:00'; $t = $C['to'] . ' 23:59:59';
        if (in_array('tickets', $pref['kinds'], true) && itops_can($user, 'tickets', 'view')) {
            $s = $db->prepare("SELECT id, ticket_number, subject, priority, due_date, sla_response_due, first_response_at FROM itops_tickets WHERE assignee_id=? AND status NOT IN ('resolved','closed','cancelled')
                               AND ((due_date BETWEEN ? AND ?) OR (sla_response_due BETWEEN ? AND ? AND first_response_at IS NULL))");
            $s->execute([$uid, $f, $t, $f, $t]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $body = $r['subject'] . "\nPriority: " . ucfirst(str_replace('_', ' ', (string)preg_replace('/^p\d_/i', '', (string)$r['priority']))); $link = itops_os_link('tickets/' . $r['id']);
                if ($r['due_date'] && $r['due_date'] >= $f && $r['due_date'] <= $t)
                    $out['tr:' . $r['id']] = ['role' => 'item', 'date' => $r['due_date'], 'teams' => false, 'exdates' => [], 'event_id' => null,
                        'payload' => itops_os_item_payload($r['ticket_number'] . ' · resolution due — ' . $r['subject'], $r['due_date'], date('Y-m-d H:i:s', strtotime($r['due_date']) + 900), false, $body, $link, 'Open the ticket', 30)];
                if ($r['sla_response_due'] && !$r['first_response_at'] && $r['sla_response_due'] >= $f && $r['sla_response_due'] <= $t)
                    $out['tp:' . $r['id']] = ['role' => 'item', 'date' => $r['sla_response_due'], 'teams' => false, 'exdates' => [], 'event_id' => null,
                        'payload' => itops_os_item_payload($r['ticket_number'] . ' · response due — ' . $r['subject'], $r['sla_response_due'], date('Y-m-d H:i:s', strtotime($r['sla_response_due']) + 900), false, $body, $link, 'Open the ticket', 30)];
            }
        }
        if (in_array('dates', $pref['kinds'], true)) {
            $pre = ['contract_end' => 'ce', 'sop_review' => 'sr', 'cred_term' => 'ct', 'cred_rotation' => 'cr', 'warranty_end' => 'we'];
            foreach (itops_cal_agenda($db, $user, $C['from'], $C['to'], ['mine' => true]) as $a) {
                if (!isset($pre[$a['type']]) || empty($a['entity_id'])) continue;
                $out[$pre[$a['type']] . ':' . $a['entity_id']] = ['role' => 'item', 'date' => $a['date'] . ' 00:00:00', 'teams' => false, 'exdates' => [], 'event_id' => null,
                    'payload' => itops_os_item_payload($a['title'] . ' — ' . $a['subtitle'], $a['date'], null, true, $a['subtitle'], itops_os_link((string)($a['page'] ?? 'calendar')), 'Open in ' . itops_app_name_safe(), 900)];   // 9:00 AM the day before
            }
        }
        return $out;
    }
    function itops_os_hash(array $d): string { return sha1(json_encode([$d['role'], $d['payload'], $d['exdates']])); }

    // ── changes made in Outlook ──
    /** Bring the organizer's Outlook edits and the attendees' answers into the ITOps event. Returns [changed fields, answers]. */
    function itops_os_apply_outlook(PDO $db, array $user, array $link, array $g): array {
        $eid = (int)substr($link['item_key'], 3); $cur = itops_cal_get($db, $eid); if (!$cur) return [[], 0];
        $sent = json_decode((string)$link['sent'], true) ?: []; $now = itops_os_core($g); $chg = [];
        if (($sent['subject'] ?? '') !== $now['subject'] && trim($now['subject']) !== '') $chg['title'] = mb_substr(trim($now['subject']), 0, 200);
        if (($sent['location'] ?? '') !== $now['location']) $chg['location'] = mb_substr($now['location'], 0, 200);
        if (($sent['start'] ?? '') !== $now['start'] || ($sent['end'] ?? '') !== $now['end'] || ($sent['all_day'] ?? false) !== $now['all_day']) {
            if ($now['all_day']) { $chg['all_day'] = 1; $chg['start_date'] = substr($now['start'], 0, 10) . ' 00:00:00'; $last = date('Y-m-d', strtotime(substr($now['end'], 0, 10) . ' -1 day')); $chg['end_date'] = max($last, substr($now['start'], 0, 10)) . ' 00:00:00'; }
            else { $chg['all_day'] = 0; $chg['start_date'] = $now['start'] . ':00'; $chg['end_date'] = $now['end'] . ':00'; }
        }
        if (!empty($g['isCancelled']) && $cur['status'] !== 'cancelled') $chg['status'] = 'cancelled';
        $before = ['Title' => $cur['title'], 'Starts' => $cur['all_day'] ? substr($cur['start'], 0, 10) . ' (all day)' : substr($cur['start'], 0, 16), 'Ends' => $cur['end'] ? ($cur['all_day'] ? substr($cur['end'], 0, 10) : substr($cur['end'], 0, 16)) : '', 'Location' => $cur['location'], 'Status' => $cur['status']];
        $who = (string)$user['display_name'] . ' (in Outlook)'; $inv = ($link['role'] ?? '') === 'invite';
        $as = fn(callable $f) => itops_os_audit_as((int)$user['id'], $who, $f);
        if ($chg) {
            $db->prepare('UPDATE itops_calendar_events SET ' . implode(', ', array_map(fn($k) => "$k=?", array_keys($chg))) . ', updated_by=? WHERE id=?')->execute(array_merge(array_values($chg), [(int)$user['id'], $eid]));
            $n = itops_cal_get($db, $eid);
            $after = ['Title' => $n['title'], 'Starts' => $n['all_day'] ? substr($n['start'], 0, 10) . ' (all day)' : substr($n['start'], 0, 16), 'Ends' => $n['end'] ? ($n['all_day'] ? substr($n['end'], 0, 10) : substr($n['end'], 0, 16)) : '', 'Location' => $n['location'], 'Status' => $n['status']];
            $as(fn() => audit_log('calendar_event_outlook', 'calendar_event', $eid, $n['title'], array_diff_assoc($before, $after), array_diff_assoc($after, $before) + ['Changed in' => $inv ? 'Outlook: the organizer changed the invitation' : 'Outlook by the organizer']));
            if (($cur['entity_type'] ?? '') === 'case' && is_file(__DIR__ . '/Cases.php')) { require_once __DIR__ . '/Cases.php'; itops_cs_event_changed($db, $eid, 'Outlook', (int)$user['id'], (string)$user['display_name']); }   // BT77
        }
        // the attendees' answers
        $map = ['accepted' => 'accepted', 'tentativelyAccepted' => 'tentative', 'declined' => 'declined'];
        $answers = 0;
        if ($inv) return [array_keys($chg), 0];   // an attendee's copy does not carry everyone's answers
        foreach ((array)($g['attendees'] ?? []) as $a) {
            $m = strtolower((string)($a['emailAddress']['address'] ?? '')); $resp = $map[(string)($a['status']['response'] ?? '')] ?? null; if ($m === '' || !$resp) continue;
            $s = $db->prepare('SELECT a.id, a.response, COALESCE(u.display_name, a.name, a.email) AS who FROM itops_calendar_attendees a LEFT JOIN itops_users u ON u.id=a.user_id WHERE a.event_id=? AND (LOWER(a.email)=? OR LOWER(u.email)=?)');
            $s->execute([$eid, $m, $m]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['response'] === $resp) continue;
                $db->prepare('UPDATE itops_calendar_attendees SET response=? WHERE id=?')->execute([$resp, (int)$row['id']]); $answers++;
                itops_os_audit_as(null, 'Outlook sync', fn() => audit_log('calendar_respond_outlook', 'calendar_event', $eid, $cur['title'], ['Attendee' => $row['who'], 'Answer' => $row['response']], ['Attendee' => $row['who'], 'Answer' => $resp, 'Answered in' => 'Outlook / Teams']));
            }
        }
        return [array_keys($chg), $answers];
    }

    // ── from Outlook into ITOps (BT68) ──
    /** Outlook's repeat → ITOps's repeat (null when it has none or ITOps cannot show it). */
    function itops_os_recur_from_graph(?array $r, string $start): ?array {
        if (!$r || empty($r['pattern']['type'])) return null;
        $p = $r['pattern']; $rg = (array)($r['range'] ?? []); $i = max(1, min(99, (int)($p['interval'] ?? 1)));
        $dn = ['monday' => 'MO', 'tuesday' => 'TU', 'wednesday' => 'WE', 'thursday' => 'TH', 'friday' => 'FR', 'saturday' => 'SA', 'sunday' => 'SU'];
        switch ($p['type']) {
            case 'daily': $o = ['freq' => 'daily', 'interval' => $i]; break;
            case 'weekly': $o = ['freq' => 'weekly', 'interval' => $i, 'byday' => array_values(array_filter(array_map(fn($d) => $dn[strtolower((string)$d)] ?? null, (array)($p['daysOfWeek'] ?? []))))]; break;
            case 'absoluteMonthly': $o = ['freq' => 'monthly', 'interval' => $i, 'monthly' => 'day']; break;
            case 'relativeMonthly': $o = ['freq' => 'monthly', 'interval' => $i, 'monthly' => ($p['index'] ?? '') === 'last' ? 'last' : 'nth']; break;
            case 'absoluteYearly': case 'relativeYearly': $o = ['freq' => 'yearly', 'interval' => $i]; break;
            default: return null;
        }
        if (($rg['type'] ?? '') === 'endDate' && !empty($rg['endDate'])) $o['until'] = substr((string)$rg['endDate'], 0, 10);
        elseif (($rg['type'] ?? '') === 'numbered' && !empty($rg['numberOfOccurrences'])) $o['count'] = min(500, (int)$rg['numberOfOccurrences']);
        try { return itops_cal_recur_clean($o, $start); } catch (InvalidArgumentException $e) { return null; }
    }
    /** An Outlook body (HTML) as plain text for the ITOps description. */
    function itops_os_body_text(array $b): string {
        $t = (string)($b['content'] ?? '');
        if (strtolower((string)($b['contentType'] ?? 'html')) === 'html') {
            $t = preg_replace('~<(style|script|head)\b[^>]*>.*?</\1>~is', '', $t) ?? '';
            $t = preg_replace('~<br\s*/?>|</(p|div|li|tr|h\d)>~i', "\n", $t) ?? '';
            $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $t = str_replace(["\r", "\u{00A0}"], ['', ' '], $t);
        $lines = array_map('rtrim', explode("\n", $t)); $lines = array_filter($lines, fn($x) => !preg_match('/^[\s_\-=]{8,}$/u', $x));
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
    }
    /** Did ITOps make this Outlook item (its text and link are in the body)? */
    function itops_os_made_here(array $g): bool {
        $c = (string)($g['body']['content'] ?? '');
        if (strpos($c, 'keeps this item up to date.') !== false) return true;
        $l = itops_os_link(''); return $l !== '' && (strpos($c, $l . 'calendar/') !== false || strpos($c, $l . 'tickets/') !== false);
    }
    /** The person's Outlook events with one of the categories (all pages, within the read budget).
     *  $since (UTC 'Y-m-d H:i:s' or null): only items changed since then — tagging an event changes it — so the items the app
     *  made itself (they carry the app's name as a category too) are not read again and again.
     *  Returns ['list' => events, 'complete' => all pages read] or ['fatal' => sentence] (the mailbox) or ['soft' => sentence]. */
    function itops_os_tagged(string $mbx, array $cats, ?string $since, int &$reads): array {
        $q = []; foreach ($cats as $c) foreach (array_unique([$c, mb_strtolower($c), mb_strtoupper($c), mb_convert_case($c, MB_CASE_TITLE)]) as $v) $q[] = "categories/any(c:c eq '" . str_replace("'", "''", $v) . "')";
        $sel = 'subject,body,start,end,isAllDay,location,isCancelled,attendees,organizer,isOrganizer,changeKey,type,recurrence,iCalUId,categories,onlineMeeting,isOnlineMeeting,showAs,sensitivity,isReminderOn,reminderMinutesBeforeStart';
        $f = implode(' or ', array_values(array_unique($q)));
        if ($since !== null) $f = '(' . $f . ') and lastModifiedDateTime ge ' . gmdate('Y-m-d\TH:i:s\Z', strtotime($since . ' UTC') - 600);   // 10 minutes of overlap
        $path = 'users/' . rawurlencode($mbx) . '/events?$filter=' . rawurlencode($f) . '&$select=' . $sel . '&$top=50';
        $out = []; $pages = 0;
        while ($path !== '' && $pages < 10 && $reads > 0) {
            $pages++; $reads--;
            [$code, $g] = itops_os_graph('GET', $path);
            if ($e = itops_os_mailbox_err($code, $g)) return ['fatal' => $e];
            if ($code >= 400) return ['soft' => 'Could not read the tagged Outlook events (' . itops_os_gerr($code, $g) . ').'];
            foreach ((array)($g['value'] ?? []) as $e) $out[] = $e;
            $next = (string)($g['@odata.nextLink'] ?? ''); $path = $next !== '' ? (string)preg_replace('#^https://graph\.microsoft\.com/v1\.0/#', '', $next) : '';
        }
        $want = array_map('mb_strtolower', $cats);   // Outlook may answer more loosely; keep exactly the categories asked for (any case)
        return ['complete' => $path === '', 'list' => array_values(array_filter($out, fn($e) => (bool)array_intersect($want, array_map(fn($c) => mb_strtolower((string)$c), (array)($e['categories'] ?? [])))))];
    }
    /** Bring the person's tagged Outlook events into ITOps. Returns how many came in, or an error sentence (mailbox-level). */
    function itops_os_import(PDO $db, array $user, array $cfg, array &$C, array &$links, int &$reads) {
        $uid = (int)$user['id']; $mbx = itops_os_mailbox($user); $now = date('Y-m-d H:i:s');
        $st = $db->prepare('SELECT import_scan_at FROM itops_outlook_users WHERE user_id=?'); $st->execute([$uid]); $since = $st->fetchColumn() ?: null;
        $began = gmdate('Y-m-d H:i:s');
        $res = itops_os_tagged($mbx, $cfg['import_categories'], $since, $reads);
        if (isset($res['fatal'])) return $res['fatal'];
        if (isset($res['soft'])) return ['soft' => $res['soft']];
        $list = $res['list'];
        // the next read starts from here — only when every page was read (otherwise the same read again next time)
        if ($res['complete']) $db->prepare('INSERT INTO itops_outlook_users (user_id, import_scan_at) VALUES (?,?) ON DUPLICATE KEY UPDATE import_scan_at=VALUES(import_scan_at)')->execute([$uid, $began]);
        if (!$list) return 0;
        $mine = []; foreach ($links as $l) if ($l['graph_id'] !== '') $mine[$l['graph_id']] = 1;
        $known = function (string $ical) use ($db): bool { if ($ical === '') return false; $s = $db->prepare('SELECT COUNT(*) FROM itops_outlook_links WHERE ical_uid=?'); $s->execute([$ical]); return (int)$s->fetchColumn() > 0; };
        $types = $C['types']; $n = 0;
        $minS = date('Y-m-d H:i:s', time() - 86400); $maxS = $C['to'] . ' 23:59:59';
        foreach ($list as $g) {
            $gid = (string)($g['id'] ?? ''); $ical = mb_substr((string)($g['iCalUId'] ?? ''), 0, 255);
            if ($gid === '' || isset($mine[$gid]) || !empty($g['isCancelled']) || !in_array((string)($g['type'] ?? 'singleInstance'), ['singleInstance', 'seriesMaster'], true)) continue;
            if ($known($ical) || itops_os_made_here($g)) continue;
            $core = itops_os_core($g); if ($core['start'] === '') continue;
            $allDay = $core['all_day'];
            $start = $allDay ? substr($core['start'], 0, 10) . ' 00:00:00' : $core['start'] . ':00';
            $end = $allDay ? max(substr($core['start'], 0, 10), date('Y-m-d', strtotime(substr($core['end'] ?: $core['start'], 0, 10) . ' -1 day'))) . ' 00:00:00' : ($core['end'] !== '' ? $core['end'] . ':00' : null);
            if ($end !== null && strtotime($end) - strtotime($start) > 62 * 86400) continue;   // longer than ITOps events can be
            $recur = itops_os_recur_from_graph($g['recurrence'] ?? null, $start);
            if ($start > $maxS) continue;
            if (!$recur && ($end ?? $start) < $minS) continue;   // already over
            if ($recur && !empty($recur['until']) && $recur['until'] < substr($minS, 0, 10)) continue;
            $isOrg = !empty($g['isOrganizer']);
            $orgM = mb_strtolower((string)($g['organizer']['emailAddress']['address'] ?? '')); $orgN = (string)($g['organizer']['emailAddress']['name'] ?? '');
            // attendees: people of the app by email, everyone else as a guest; the person themself is the organizer here
            $att = []; $resp = [];
            $people = $g['attendees'] ?? [];
            if (!$isOrg && $orgM !== '') array_unshift($people, ['emailAddress' => ['address' => $orgM, 'name' => $orgN], 'status' => ['response' => 'organizer']]);
            $rmap = ['accepted' => 'accepted', 'tentativelyAccepted' => 'tentative', 'declined' => 'declined', 'organizer' => 'accepted'];
            foreach ((array)$people as $a) {
                $m = mb_strtolower(trim((string)($a['emailAddress']['address'] ?? ''))); if ($m === '' || !filter_var($m, FILTER_VALIDATE_EMAIL) || $m === $mbx || count($att) >= 100) continue;
                $s = $db->prepare("SELECT id FROM itops_users WHERE LOWER(email)=? AND status='active' LIMIT 1"); $s->execute([$m]); $pid = (int)$s->fetchColumn();
                $att[] = $pid ? ['user_id' => $pid] : ['email' => $m, 'name' => mb_substr((string)($a['emailAddress']['name'] ?? ''), 0, 160)];
                $resp[$pid ? 'u' . $pid : 'e' . $m] = $rmap[(string)($a['status']['response'] ?? '')] ?? 'none';
            }
            $type = ($g['showAs'] ?? '') === 'oof' ? 'out_of_office' : (($att || !empty($g['isOnlineMeeting'])) ? 'meeting' : 'other');
            if (!itops_cal_type($types, $type)) $type = itops_cal_type($types, 'other') ? 'other' : (string)$types[0]['key'];
            $rem = [];
            if (!empty($g['isReminderOn'])) { $want = (int)($g['reminderMinutesBeforeStart'] ?? 15); $best = null; foreach (array_keys(itops_cal_reminder_choices()) as $c) if ($best === null || abs($c - $want) < abs($best - $want)) $best = $c; $rem = [$best]; }
            $join = (string)($g['onlineMeeting']['joinUrl'] ?? '');
            $desc = itops_os_body_text((array)($g['body'] ?? []));
            if (!$isOrg) $desc = 'Organizer (in Outlook): ' . trim($orgN . ($orgM !== '' ? ' <' . $orgM . '>' : '')) . ($desc !== '' ? "\n\n" . $desc : '');
            $in = ['title' => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($g['subject'] ?? ''))) ?: '(no title)', 0, 255), 'description' => mb_substr($desc, 0, 5000), 'event_type' => $type,
                   'all_day' => $allDay, 'start' => $start, 'end' => $end, 'location' => mb_substr($core['location'], 0, 200), 'online_url' => preg_match('~^https://[^\s<>"]+$~i', $join) ? mb_substr($join, 0, 500) : '',
                   'visibility' => in_array((string)($g['sensitivity'] ?? ''), ['private', 'confidential'], true) ? 'private' : 'team', 'reminders' => $rem, 'attendees' => $att];
            try { [$e, $A] = itops_cal_clean($db, $in, null, $types, $user); } catch (InvalidArgumentException $x) { continue; }
            $e['recur'] = $recur; $origin = $isOrg ? 'outlook' : 'outlook_invite';
            $cols = ['title' => $e['title'], 'description' => $e['description'], 'event_type' => $e['event_type'], 'start_date' => $e['start'], 'end_date' => $e['end'], 'all_day' => $e['all_day'] ? 1 : 0, 'color' => null,
                     'location' => $e['location'], 'site_id' => null, 'online_url' => $e['online_url'], 'visibility' => $e['visibility'], 'status' => 'scheduled', 'recur' => $recur ? json_encode($recur) : null,
                     'exdates' => null, 'reminders' => $e['reminders'] ? json_encode($e['reminders']) : null, 'owner_id' => $uid, 'department_id' => null, 'entity_type' => null, 'entity_id' => null,
                     'updated_by' => $uid, 'created_by' => $uid, 'origin' => $origin];
            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO itops_calendar_events (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
                $eid = (int)$db->lastInsertId();
                $ia = $db->prepare('INSERT INTO itops_calendar_attendees (event_id, user_id, email, name, response) VALUES (?,?,?,?,?)');
                foreach ((array)$A as $a) $ia->execute([$eid, $a['user_id'], $a['email'], $a['name'], $resp[$a['user_id'] ? 'u' . $a['user_id'] : 'e' . $a['email']] ?? 'none']);
                $role = $isOrg ? 'organizer' : 'invite';
                $db->prepare('INSERT INTO itops_outlook_links (user_id, item_key, role, graph_id, change_key, hash, sent, item_date, teams, dismissed, synced_at, pulled_at, ical_uid) VALUES (?,?,?,?,?,?,?,?,0,0,?,?,?)')
                   ->execute([$uid, 'ev:' . $eid, $role, $gid, (string)($g['changeKey'] ?? ''), $isOrg ? 'adopt' : 'invite', json_encode($core), $e['start'], $now, $now, $ical]);
                $lid = (int)$db->lastInsertId();
                $db->commit();
            } catch (Throwable $x) { $db->rollBack(); throw $x; }
            $links['ev:' . $eid] = ['id' => $lid, 'user_id' => $uid, 'item_key' => 'ev:' . $eid, 'role' => $role, 'graph_id' => $gid, 'change_key' => (string)($g['changeKey'] ?? ''), 'hash' => $isOrg ? 'adopt' : 'invite',
                                    'sent' => json_encode($core), 'item_date' => $e['start'], 'teams' => 0, 'dismissed' => 0, 'error' => null, 'synced_at' => $now, 'pulled_at' => $now, 'ical_uid' => $ical];
            $mine[$gid] = 1;
            $ne = itops_os_cache_event($db, $eid); if ($ne) $C['events'][$eid] = $ne;
            $who = (string)$user['display_name'] . ' (in Outlook)';
            $vals = ['Title' => $e['title'], 'Starts' => $e['all_day'] ? substr($e['start'], 0, 10) . ' (all day)' : substr($e['start'], 0, 16), 'Ends' => $e['end'] ? ($e['all_day'] ? substr($e['end'], 0, 10) : substr($e['end'], 0, 16)) : '',
                     'Location' => $e['location'], 'Repeat' => itops_cal_recur_text($recur, $e['start']), 'Attendees' => (string)count((array)$A), 'Came from' => $isOrg ? 'Outlook (their own event, category ' . implode(' / ', array_map('strval', (array)($g['categories'] ?? []))) . ')' : 'Outlook (an invitation from ' . ($orgN !== '' ? $orgN : $orgM) . ')'];
            itops_os_audit_as($uid, $who, fn() => audit_log('calendar_event_from_outlook', 'calendar_event', $eid, $e['title'], null, array_filter($vals, fn($v) => $v !== '')));
            $n++;
        }
        return $n;
    }

    // ── one person ──
    function itops_os_sync_user(PDO $db, array $user, array $cfg, array &$C, int &$budget, int &$reads): array {
        $uid = (int)$user['id']; $mbx = itops_os_mailbox($user); $pref = itops_os_pref($db, $uid, $cfg);
        $R = ['created' => 0, 'updated' => 0, 'removed' => 0, 'from_outlook' => 0, 'answers' => 0, 'imported' => 0, 'errors' => 0, 'error' => null];
        $ls = $db->prepare('SELECT * FROM itops_outlook_links WHERE user_id=?'); $ls->execute([$uid]); $links = []; foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) $links[$l['item_key']] = $l;
        if (!$pref['enabled'] && !$links) return $R;
        $fail = function (string $m) use ($db, $uid, &$R) { $R['error'] = $m; $db->prepare('INSERT INTO itops_outlook_users (user_id, last_sync_at, last_error) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_sync_at=VALUES(last_sync_at), last_error=VALUES(last_error)')->execute([$uid, date('Y-m-d H:i:s'), mb_substr($m, 0, 500)]); return $R; };
        if ($mbx === '') return $fail('This person has no valid email address, so there is no Outlook calendar to use.');
        $base = 'users/' . rawurlencode($mbx) . '/events';
        $upd = $db->prepare('UPDATE itops_outlook_links SET graph_id=?, change_key=?, hash=?, sent=?, item_date=?, role=?, teams=?, dismissed=?, error=NULL, synced_at=? WHERE id=?');
        $ins = $db->prepare('INSERT INTO itops_outlook_links (user_id, item_key, role, graph_id, change_key, hash, sent, item_date, teams, dismissed, synced_at) VALUES (?,?,?,?,?,?,?,?,?,0,?)');
        $del = $db->prepare('DELETE FROM itops_outlook_links WHERE id=?');
        $now = date('Y-m-d H:i:s'); $softErr = null;

        // 1) changes made in Outlook (read back what ITOps made)
        foreach ($links as $k => $l) {
            if ($l['graph_id'] === '' || $l['dismissed']) continue;
            // the organizer's meetings are read every run; tickets, dates and copies (ITOps keeps them) once an hour
            if (!in_array($l['role'], ['organizer', 'invite'], true) && $l['pulled_at'] && strtotime($l['pulled_at']) > time() - 3600) continue;
            if ($reads-- <= 0) break;
            $db->prepare('UPDATE itops_outlook_links SET pulled_at=? WHERE id=?')->execute([$now, (int)$l['id']]);
            [$code, $g] = itops_os_graph('GET', $base . '/' . rawurlencode($l['graph_id']) . '?$select=subject,start,end,isAllDay,location,isCancelled,attendees,changeKey,type');
            if ($e = itops_os_mailbox_err($code, $g)) return $fail($e);
            if ($code === 404) {
                if (in_array($l['role'], ['organizer', 'invite'], true)) {   // deleted in Outlook → cancelled in ITOps (kept, not deleted)
                    $eid = (int)substr($k, 3); $cur = itops_cal_get($db, $eid);
                    if ($cur && $cur['status'] !== 'cancelled') {
                        $db->prepare("UPDATE itops_calendar_events SET status='cancelled', updated_by=? WHERE id=?")->execute([$uid, $eid]);
                        $why = $l['role'] === 'invite' ? 'Outlook: the invitation was removed from their calendar' : 'Outlook: the organizer deleted it';
                        itops_os_audit_as($uid, (string)$user['display_name'] . ' (in Outlook)', fn() => audit_log('calendar_event_outlook', 'calendar_event', $eid, $cur['title'], ['Status' => $cur['status']], ['Status' => 'cancelled', 'Changed in' => $why]));
                        $R['from_outlook']++;
                        if (($cur['entity_type'] ?? '') === 'case' && is_file(__DIR__ . '/Cases.php')) { require_once __DIR__ . '/Cases.php'; itops_cs_event_changed($db, $eid, 'Outlook', $uid, (string)$user['display_name']); }   // BT77
                    }
                    unset($C['events'][$eid]);
                    $del->execute([(int)$l['id']]); unset($links[$k]);
                } else { $db->prepare('UPDATE itops_outlook_links SET dismissed=1, synced_at=? WHERE id=?')->execute([$now, (int)$l['id']]); $links[$k]['dismissed'] = 1; }
                continue;
            }
            if ($code >= 400) { $db->prepare('UPDATE itops_outlook_links SET error=? WHERE id=?')->execute([mb_substr(itops_os_gerr($code, $g), 0, 500), (int)$l['id']]); $R['errors']++; continue; }
            if ((string)($g['changeKey'] ?? '') === $l['change_key']) continue;
            $sent = json_decode((string)$l['sent'], true) ?: []; $core = itops_os_core($g);
            if (in_array($l['role'], ['organizer', 'invite'], true)) {
                [$chg, $ans] = itops_os_apply_outlook($db, $user, $l, $g);
                $R['answers'] += $ans;
                if ($chg) { $R['from_outlook']++; $links[$k]['hash'] = 'adopt'; $eid = (int)substr($k, 3); $ne = itops_os_cache_event($db, $eid); if ($ne) $C['events'][$eid] = $ne; else unset($C['events'][$eid]); }
                $db->prepare('UPDATE itops_outlook_links SET change_key=?, sent=?, hash=? WHERE id=?')->execute([(string)($g['changeKey'] ?? ''), json_encode($core), $links[$k]['hash'], (int)$l['id']]);
                $links[$k]['change_key'] = (string)($g['changeKey'] ?? ''); $links[$k]['sent'] = json_encode($core);
            } else {   // tickets / dates / copies are kept by ITOps: an edit in Outlook is put back
                $edited = $sent && itops_os_core_diff($core, $sent);
                $db->prepare('UPDATE itops_outlook_links SET change_key=?' . ($edited ? ", hash=''" : '') . ' WHERE id=?')->execute([(string)($g['changeKey'] ?? ''), (int)$l['id']]);
                $links[$k]['change_key'] = (string)($g['changeKey'] ?? ''); if ($edited) $links[$k]['hash'] = '';
            }
        }

        // 1b) events the person tagged in Outlook come into ITOps (BT68)
        if ($pref['enabled'] && in_array('events', $pref['kinds'], true) && !empty($cfg['import']) && itops_can($user, 'calendar', 'create')) {
            $r = itops_os_import($db, $user, $cfg, $C, $links, $reads);
            if (is_string($r)) return $fail($r);
            if (is_array($r)) { $R['errors']++; $softErr = $r['soft']; } else $R['imported'] = $r;
        }

        // 2) what should be there now
        $want = itops_os_desired($db, $user, $pref, $cfg, $C);
        $wstart = $C['from'] . ' 00:00:00';
        foreach ($want as $k => $d) {
            if ($budget <= 0) break;
            $h = itops_os_hash($d); $l = $links[$k] ?? null; $p = $d['payload'];
            if ($d['role'] === 'invite') {   // someone else's invitation: ITOps only reads it
                if ($l && ($l['hash'] !== $h || $l['item_date'] !== $d['date'])) $db->prepare('UPDATE itops_outlook_links SET hash=?, item_date=?, dismissed=0 WHERE id=?')->execute([$h, $d['date'], (int)$l['id']]);
                continue;
            }
            if ($l && (int)$l['dismissed'] === 2) {   // a meeting left in Outlook when the sync was switched off: take it up again (no second meeting)
                if ($l['hash'] === $h && $l['role'] === $d['role']) { $upd->execute([$l['graph_id'], $l['change_key'], $h, $l['sent'], $d['date'], $d['role'], (int)$l['teams'], 0, $now, (int)$l['id']]); continue; }
                $l['dismissed'] = 0;
            }
            if ($l && $l['hash'] === 'adopt') { $upd->execute([$l['graph_id'], $l['change_key'], $h, $l['sent'], $d['date'], $d['role'], (int)$l['teams'], 0, $now, (int)$l['id']]); continue; }
            if ($l && $l['hash'] === $h && $l['role'] === $d['role']) continue;
            if ($l && $l['dismissed'] && $l['hash'] === $h) continue;
            $teams = false;
            if ($l && $l['graph_id'] !== '' && !$l['dismissed'] && $l['role'] === $d['role']) {
                $budget--; [$code, $g] = itops_os_graph('PATCH', $base . '/' . rawurlencode($l['graph_id']), $p);
                if ($e = itops_os_mailbox_err($code, $g)) return $fail($e);
                if ($code === 404) { $l['graph_id'] = ''; }
                elseif ($code >= 400) { $db->prepare('UPDATE itops_outlook_links SET error=? WHERE id=?')->execute([mb_substr(itops_os_gerr($code, $g), 0, 500), (int)$l['id']]); $R['errors']++; continue; }
                else { $upd->execute([$l['graph_id'], (string)($g['changeKey'] ?? ''), $h, json_encode(itops_os_core($g ?: $p)), $d['date'], $d['role'], (int)$l['teams'], 0, $now, (int)$l['id']]); $R['updated']++; itops_os_exdates($base, $l['graph_id'], $d, $budget); continue; }
            }
            if ($l && $l['graph_id'] !== '' && $l['role'] !== $d['role'] && !$l['dismissed']) { $budget--; itops_os_graph('DELETE', $base . '/' . rawurlencode($l['graph_id'])); }
            // a new Outlook event
            if ($d['teams']) { $p['isOnlineMeeting'] = true; $p['onlineMeetingProvider'] = 'teamsForBusiness'; $teams = true; }
            $budget--; [$code, $g] = itops_os_graph('POST', $base, $p);
            if ($e = itops_os_mailbox_err($code, $g)) return $fail($e);
            if ($code >= 400 || empty($g['id'])) {
                if ($l) $db->prepare('UPDATE itops_outlook_links SET error=? WHERE id=?')->execute([mb_substr(itops_os_gerr($code, $g), 0, 500), (int)$l['id']]);
                else $db->prepare('INSERT INTO itops_outlook_links (user_id, item_key, role, error, item_date) VALUES (?,?,?,?,?)')->execute([$uid, $k, $d['role'], mb_substr(itops_os_gerr($code, $g), 0, 500), $d['date']]);
                $R['errors']++; continue;
            }
            $core = json_encode(itops_os_core($g + $p));
            if ($l) $upd->execute([(string)$g['id'], (string)($g['changeKey'] ?? ''), $h, $core, $d['date'], $d['role'], $teams ? 1 : 0, 0, $now, (int)$l['id']]);
            else $ins->execute([$uid, $k, $d['role'], (string)$g['id'], (string)($g['changeKey'] ?? ''), $h, $core, $d['date'], $teams ? 1 : 0, $now]);
            $R['created']++;
            if (!empty($g['iCalUId'])) $db->prepare('UPDATE itops_outlook_links SET ical_uid=? WHERE user_id=? AND item_key=?')->execute([mb_substr((string)$g['iCalUId'], 0, 255), $uid, $k]);
            itops_os_exdates($base, (string)$g['id'], $d, $budget);
            $join = (string)($g['onlineMeeting']['joinUrl'] ?? '');
            if ($teams && $join !== '' && $d['event_id']) {   // the Teams link goes back to the ITOps event
                $st = $db->prepare("UPDATE itops_calendar_events SET online_url=? WHERE id=? AND online_url=''"); $st->execute([mb_substr($join, 0, 500), (int)$d['event_id']]);
                if ($st->rowCount()) itops_os_audit_as(null, 'Outlook sync', fn() => audit_log('calendar_teams_link', 'calendar_event', (int)$d['event_id'], (string)$p['subject'], ['Online link' => ''], ['Online link' => 'Teams meeting (made by the Outlook sync)']));
            }
        }
        // 3) what should no longer be there
        foreach ($links as $k => $l) {
            if (isset($want[$k]) || (int)$l['dismissed'] === 2) continue;
            if ($l['role'] === 'invite') { $del->execute([(int)$l['id']]); continue; }   // never touch someone else's invitation
            if ($budget <= 0) break;
            $past = $l['item_date'] && $l['item_date'] < $wstart;
            $keepMeeting = false;
            if ($l['role'] === 'organizer') {   // switched off, or no longer allowed: leave the real meeting in Outlook, just stop syncing it
                $eid = (int)substr($k, 3); $e = $C['events'][$eid] ?? null;
                // BT124 (review DESK-2): an event that only left the sync window is not in this run's list: read it here,
                // so a meeting that still exists is never cancelled for its attendees
                if (!$e && $eid > 0) { $q = $db->prepare('SELECT id, owner_id, status FROM itops_calendar_events WHERE id=?'); $q->execute([$eid]); $e = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
                $keepMeeting = $e && (int)$e['owner_id'] === $uid && ($e['status'] ?? '') !== 'cancelled';
            }
            if ($l['graph_id'] !== '' && !$l['dismissed'] && !$past && !$keepMeeting) {
                $budget--;
                if ($l['role'] === 'organizer') { [$code] = itops_os_graph('POST', $base . '/' . rawurlencode($l['graph_id']) . '/cancel', ['Comment' => 'Cancelled in ' . itops_app_name_safe() . '.']); if ($code >= 400 && $code !== 404) { $budget--; [$code] = itops_os_graph('DELETE', $base . '/' . rawurlencode($l['graph_id'])); } }
                else { [$code, $g] = itops_os_graph('DELETE', $base . '/' . rawurlencode($l['graph_id'])); if ($e = itops_os_mailbox_err($code, $g)) return $fail($e); }
                $R['removed']++;
            }
            if ($keepMeeting && $l['graph_id'] !== '') { $db->prepare('UPDATE itops_outlook_links SET dismissed=2 WHERE id=?')->execute([(int)$l['id']]); continue; }   // parked: taken up again if switched back on
            $del->execute([(int)$l['id']]);
        }
        $n = $db->prepare('SELECT COUNT(*) FROM itops_outlook_links WHERE user_id=? AND dismissed=0 AND graph_id<>\'\''); $n->execute([$uid]);
        $db->prepare('INSERT INTO itops_outlook_users (user_id, last_sync_at, last_ok_at, last_error, items) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE last_sync_at=VALUES(last_sync_at), last_ok_at=VALUES(last_ok_at), last_error=VALUES(last_error), items=VALUES(items)')
           ->execute([$uid, $now, $now, $softErr, (int)$n->fetchColumn()]);
        return $R;
    }
    /** Skipped dates of a repeating event: remove those occurrences in Outlook. */
    function itops_os_exdates(string $base, string $gid, array $d, int &$budget): void {
        if (empty($d['payload']['recurrence'])) return;
        foreach ($d['exdates'] as $x) {
            if ($budget <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$x)) continue;
            $budget--; [$code, $g] = itops_os_graph('GET', $base . '/' . rawurlencode($gid) . '/instances?startDateTime=' . $x . 'T00:00:00&endDateTime=' . $x . 'T23:59:59&$select=id');
            if ($code >= 400) continue;
            foreach ((array)($g['value'] ?? []) as $i) { if (empty($i['id'])) continue; $budget--; itops_os_graph('DELETE', $base . '/' . rawurlencode((string)$i['id'])); }
        }
    }

    // ── everyone ──
    /** One run: every person who syncs (or still has items to clean up). $force: ignore the 3-minute spacing. $only: one person. */
    function itops_os_run(PDO $db, bool $force = false, ?int $only = null): array {
        if (!itops_os_ready($db)) return ['ran' => false, 'reason' => 'Run db-update.php first.'];
        $cfg = itops_os_cfg($db);
        if (!$cfg['enabled']) return ['ran' => false, 'reason' => 'The Outlook calendar sync is off.'];
        if (!function_exists('itops_graph_configured') || !itops_graph_configured()) return ['ran' => false, 'reason' => 'Microsoft 365 is not connected.'];
        $last = json_decode((string)itops_os_setting($db, 'outlook_sync_last'), true) ?: [];
        if (!$force && $only === null && !empty($last['at']) && time() - strtotime($last['at']) < 170) return ['ran' => false, 'reason' => 'Ran less than 3 minutes ago.'];
        if (!(int)$db->query("SELECT GET_LOCK('itops_outlook_sync', 0)")->fetchColumn()) return ['ran' => false, 'reason' => 'Another sync is running.'];
        $sum = ['ran' => true, 'people' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0, 'from_outlook' => 0, 'answers' => 0, 'imported' => 0, 'errors' => 0, 'failed_people' => []];
        try {
            $ids = array_map('intval', $db->query("SELECT u.id FROM itops_users u WHERE u.status='active' UNION SELECT DISTINCT user_id FROM itops_outlook_links")->fetchAll(PDO::FETCH_COLUMN));
            if ($only !== null) $ids = array_values(array_intersect($ids, [$only]));
            $users = []; $org = [];
            foreach ($ids as $id) {
                $u = itops_os_user($db, $id);
                if (!$u) { $u = ['id' => $id, 'email' => (string)$db->query('SELECT email FROM itops_users WHERE id=' . $id)->fetchColumn(), 'display_name' => '', 'role_slug' => '', 'role_perms' => '{}']; $u['_off'] = true; }
                $p = itops_os_pref($db, $id, $cfg); if (!empty($u['_off'])) $p['enabled'] = false;
                $users[$id] = $u;
                $org[$id] = $p['enabled'] && in_array('events', $p['kinds'], true) && itops_os_mailbox($u) !== '' && itops_can($u, 'calendar', 'view');
            }
            if ($only !== null) foreach (array_map('intval', $db->query("SELECT id FROM itops_users WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN)) as $id)
                if (!isset($org[$id])) { $u = itops_os_user($db, $id); $p = itops_os_pref($db, $id, $cfg); $org[$id] = $u && $p['enabled'] && in_array('events', $p['kinds'], true) && itops_os_mailbox($u) !== '' && itops_can($u, 'calendar', 'view'); }
            $C = itops_os_run_cache($db, $cfg, $org);
            $budget = 300; $reads = 600;   // Graph writes and reads per run (the rest waits for the next run)
            foreach ($users as $id => $u) {
                if ($budget <= 0) break;
                try { $r = itops_os_sync_user($db, $u, $cfg, $C, $budget, $reads); }
                catch (Throwable $e) { $r = ['error' => 'Sync error: ' . $e->getMessage(), 'created' => 0, 'updated' => 0, 'removed' => 0, 'from_outlook' => 0, 'answers' => 0, 'imported' => 0, 'errors' => 1]; }
                if ($r['created'] + $r['updated'] + $r['removed'] + $r['from_outlook'] + $r['answers'] + (int)($r['imported'] ?? 0) > 0 || $r['error']) $sum['people']++;
                foreach (['created', 'updated', 'removed', 'from_outlook', 'answers', 'imported', 'errors'] as $k) $sum[$k] += (int)($r[$k] ?? 0);
                if ($r['error']) { $sum['failed_people'][] = ($u['display_name'] ?: $u['email']) . ': ' . $r['error']; }
            }
        } finally { $db->query("SELECT RELEASE_LOCK('itops_outlook_sync')"); }
        $sum['at'] = date('Y-m-d H:i:s');
        if ($only === null) itops_os_setting_put($db, 'outlook_sync_last', json_encode(['at' => $sum['at']] + $sum));
        if ($sum['failed_people'] && function_exists('itops_log')) itops_log('warning', 'calendar', 'Outlook calendar sync: ' . count($sum['failed_people']) . ' person(s) not synced', ['people' => array_slice($sum['failed_people'], 0, 10)]);
        return $sum;
    }
    /** Remove ITOps's items (tickets, dates, copies) from everyone's Outlook; meetings are left and simply no longer synced. */
    function itops_os_remove_all(PDO $db): array {
        $out = ['removed' => 0, 'unlinked' => 0, 'errors' => 0];
        foreach ($db->query('SELECT l.*, u.email FROM itops_outlook_links l LEFT JOIN itops_users u ON u.id=l.user_id')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $m = strtolower(trim((string)$l['email']));
            if ((int)$l['dismissed'] === 2) { $out['unlinked']++; continue; }
            if (!in_array($l['role'], ['organizer', 'invite'], true) && $l['graph_id'] !== '' && !$l['dismissed'] && $m !== '') {
                [$code] = itops_os_graph('DELETE', 'users/' . rawurlencode($m) . '/events/' . rawurlencode($l['graph_id']));
                if ($code >= 400 && $code !== 404) { $out['errors']++; continue; }
                $out['removed']++;
            } else $out['unlinked']++;
            if ($l['role'] === 'organizer' && $l['graph_id'] !== '') { $db->prepare('UPDATE itops_outlook_links SET dismissed=2 WHERE id=?')->execute([(int)$l['id']]); continue; }   // left in Outlook; taken up again later, never a second meeting
            $db->prepare('DELETE FROM itops_outlook_links WHERE id=?')->execute([(int)$l['id']]);
        }
        $db->exec('UPDATE itops_outlook_users SET items=0');
        return $out;
    }
    /** An attendee answered in ITOps: give the same answer to the invitation in their own Outlook calendar (best effort). */
    function itops_os_respond(PDO $db, int $eid, array $user, string $resp): string {
        if (!itops_os_ready($db) || !itops_os_cfg($db)['enabled'] || !function_exists('itops_graph_configured') || !itops_graph_configured()) return '';
        if (!itops_os_pref($db, (int)$user['id'])['enabled']) return '';
        $s = $db->prepare("SELECT ical_uid FROM itops_outlook_links WHERE item_key=? AND role IN ('organizer','invite') AND ical_uid<>'' LIMIT 1"); $s->execute(['ev:' . $eid]); $uidX = (string)$s->fetchColumn();
        $mbx = itops_os_mailbox($user); if ($uidX === '' || $mbx === '') return '';
        $act = ['accepted' => 'accept', 'tentative' => 'tentativelyAccept', 'declined' => 'decline'][$resp] ?? null; if (!$act) return '';
        try {
            [$code, $g] = itops_os_graph('GET', 'users/' . rawurlencode($mbx) . "/events?\$filter=" . rawurlencode("iCalUId eq '" . str_replace("'", "''", $uidX) . "'") . '&$select=id&$top=1');
            $gid = (string)($g['value'][0]['id'] ?? ''); if ($code >= 400 || $gid === '') return 'The invitation is not in your Outlook calendar (yet).';
            [$code, $g] = itops_os_graph('POST', 'users/' . rawurlencode($mbx) . '/events/' . rawurlencode($gid) . '/' . $act, ['sendResponse' => true]);
            return $code < 400 ? 'ok' : 'Outlook did not take the answer (' . itops_os_gerr($code, $g) . ').';
        } catch (Throwable $e) { return 'Outlook did not take the answer (' . $e->getMessage() . ').'; }
    }
    /** For one ITOps event: is it in Outlook, and since when (the event page shows it). */
    function itops_os_event_state(PDO $db, int $eid): ?array {
        if (!itops_os_ready($db) || !itops_os_cfg($db)['enabled']) return null;
        $s = $db->prepare("SELECT l.role, l.synced_at, l.error, l.teams, l.graph_id, u.display_name FROM itops_outlook_links l LEFT JOIN itops_users u ON u.id=l.user_id WHERE l.item_key=? AND l.dismissed<>2 ORDER BY l.role IN ('organizer','invite') DESC, l.id");
        $s->execute(['ev:' . $eid]); $r = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$r) return ['in_outlook' => false];
        $org = array_values(array_filter($r, fn($x) => in_array($x['role'], ['organizer', 'invite'], true)))[0] ?? null;
        return ['in_outlook' => (bool)array_filter($r, fn($x) => $x['graph_id'] !== ''), 'as' => $org ? ($org['role'] === 'invite' ? 'invite' : 'meeting') : 'copies', 'calendars' => count(array_filter($r, fn($x) => $x['graph_id'] !== '')),
                'synced_at' => max(array_map(fn($x) => (string)$x['synced_at'], $r)), 'teams' => (bool)array_filter($r, fn($x) => (int)$x['teams'] === 1),
                'error' => (string)(array_values(array_filter(array_map(fn($x) => (string)$x['error'], $r)))[0] ?? '')];
    }
}
