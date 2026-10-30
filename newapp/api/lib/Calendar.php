<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — the calendar (BT60): event types, times, recurrence, reminders, attendees, offices, and calendar reports.
 *
 * Events (itops_calendar_events) have a type (Settings of the calendar: meeting, maintenance window, change window,
 * training, vendor visit, audit, deadline, out of office, office closure, task, other — editable, each with a colour
 * and defaults), a start and an end (or all day), a status (scheduled, tentative, cancelled, done), a location (an
 * office from IT Budget → Offices, or free text, or an online meeting link), an organizer, attendees (people of the app
 * and outside email addresses), reminders (minutes before; sent by the main scheduler in the app and by email), a
 * visibility (team = everyone with Calendar · View; private = only the organizer, the attendees and Super Admins) and
 * an optional repeat rule:
 *   {freq: daily|weekly|monthly|yearly, interval: 1–99, byday: [MO…SU] (weekly), monthly: day|nth|last,
 *    until: YYYY-MM-DD | null, count: 1–500 | null}
 * An occurrence can be skipped (exdates) or changed on its own: it becomes its own event (series_id = the series).
 *
 * The agenda also shows the due dates of other modules, each filtered by the viewer's permissions (BT24): ticket
 * response and resolution, contract ends, SOP reviews, credential terms and rotations, equipment warranty ends (BT60).
 *
 * Reports (itops_cal_report): events by type, upcoming deadlines and renewals, maintenance and change windows,
 * attendance by person, activity by office, overdue items, and the full agenda — on screen, CSV, PDF, Word and
 * calendar file (.ics).
 */

if (!function_exists('itops_cal_types')) {

    function itops_cal_type_defaults(): array {
        return [
            ['key' => 'meeting', 'label' => 'Meeting', 'color' => '#1D4D7A', 'minutes' => 60, 'all_day' => false, 'window' => false, 'reminders' => [15]],
            ['key' => 'maintenance', 'label' => 'Maintenance window', 'color' => '#C08A30', 'minutes' => 120, 'all_day' => false, 'window' => true, 'reminders' => [1440, 60]],
            ['key' => 'change', 'label' => 'Change window', 'color' => '#B5562E', 'minutes' => 120, 'all_day' => false, 'window' => true, 'reminders' => [1440, 60]],
            ['key' => 'training', 'label' => 'Training', 'color' => '#3D9A6E', 'minutes' => 60, 'all_day' => false, 'window' => false, 'reminders' => [1440]],
            ['key' => 'vendor_visit', 'label' => 'Vendor visit', 'color' => '#5E6FA8', 'minutes' => 90, 'all_day' => false, 'window' => false, 'reminders' => [1440]],
            ['key' => 'audit', 'label' => 'Audit / inspection', 'color' => '#8E5BB5', 'minutes' => 240, 'all_day' => false, 'window' => false, 'reminders' => [10080, 1440]],
            ['key' => 'deadline', 'label' => 'Deadline', 'color' => '#C44545', 'minutes' => 0, 'all_day' => true, 'window' => false, 'reminders' => [10080, 1440]],
            ['key' => 'out_of_office', 'label' => 'Out of office', 'color' => '#7A8794', 'minutes' => 0, 'all_day' => true, 'window' => false, 'reminders' => []],
            ['key' => 'closure', 'label' => 'Office closure / holiday', 'color' => '#4F6B7A', 'minutes' => 0, 'all_day' => true, 'window' => false, 'reminders' => [2880]],
            ['key' => 'task', 'label' => 'Task', 'color' => '#2F7F86', 'minutes' => 30, 'all_day' => false, 'window' => false, 'reminders' => [60]],
            ['key' => 'other', 'label' => 'Other', 'color' => '#8FB3C7', 'minutes' => 60, 'all_day' => false, 'window' => false, 'reminders' => []],
        ];
    }
    function itops_cal_reminder_choices(): array { return [0 => 'At the start', 5 => '5 minutes before', 10 => '10 minutes before', 15 => '15 minutes before', 30 => '30 minutes before', 60 => '1 hour before', 120 => '2 hours before', 240 => '4 hours before', 1440 => '1 day before', 2880 => '2 days before', 10080 => '1 week before']; }
    /** The event types: the defaults, as changed in the calendar settings (a type in use is never removed, only hidden). */
    function itops_cal_types(PDO $db): array {
        $d = itops_cal_type_defaults();
        try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='calendar_types'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        if (!is_array($j) || !$j) return array_map(fn($t) => $t + ['hidden' => false], $d);
        return array_map(fn($t) => $t + ['hidden' => false], $j);
    }
    function itops_cal_types_clean(array $in): array {
        $bad = fn($m) => throw new InvalidArgumentException($m);
        if (!$in || count($in) > 40) $bad('Keep between 1 and 40 kinds of event.');
        $o = []; $seen = [];
        foreach ($in as $t) {
            if (!is_array($t)) $bad('The kinds of event are not in the expected form.');
            $label = trim(preg_replace('/\s+/u', ' ', (string)($t['label'] ?? '')) ?? '');
            if (mb_strlen($label) < 2 || mb_strlen($label) > 60) $bad('Each kind of event needs a name of 2 to 60 characters.');
            $key = (string)($t['key'] ?? ''); if ($key === '') $key = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)), '_');
            if (!preg_match('/^[a-z0-9_]{2,40}$/', $key)) $bad('"' . $label . '": its key can only use a–z, 0–9 and _.');
            if (isset($seen[$key])) $bad('Two kinds of event have the same key "' . $key . '".'); $seen[$key] = 1;
            $col = strtoupper((string)($t['color'] ?? '#8FB3C7')); if (!preg_match('/^#[0-9A-F]{6}$/', $col)) $bad('"' . $label . '": the colour is a hex colour such as #1D4D7A.');
            $min = (int)($t['minutes'] ?? 60); if ($min < 0 || $min > 1440) $bad('"' . $label . '": the usual length is 0 to 1440 minutes.');
            $rem = []; foreach ((array)($t['reminders'] ?? []) as $m) { if (!array_key_exists((int)$m, itops_cal_reminder_choices())) $bad('"' . $label . '": choose reminders from the list.'); $rem[] = (int)$m; }
            $o[] = ['key' => $key, 'label' => $label, 'color' => $col, 'minutes' => $min, 'all_day' => !empty($t['all_day']), 'window' => !empty($t['window']), 'reminders' => array_values(array_unique($rem)), 'hidden' => !empty($t['hidden'])];
        }
        return $o;
    }
    function itops_cal_type(array $types, string $key): ?array { foreach ($types as $t) if ($t['key'] === $key) return $t; return null; }

    // ── one event ──────────────────────────────────────────────────────────────────────────────────────────────────

    function itops_cal_row(array $r): array {
        $j = fn($v) => ($v === null || $v === '') ? null : json_decode((string)$v, true);
        return ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'description' => (string)($r['description'] ?? ''), 'event_type' => (string)($r['event_type'] ?: 'other'),
            'start' => (string)$r['start_date'], 'end' => $r['end_date'] ? (string)$r['end_date'] : null, 'all_day' => (bool)$r['all_day'], 'color' => (string)($r['color'] ?? ''),
            'location' => (string)($r['location'] ?? ''), 'site_id' => $r['site_id'] ?? null ? (int)$r['site_id'] : null, 'online_url' => (string)($r['online_url'] ?? ''),
            'visibility' => (string)($r['visibility'] ?? 'team'), 'status' => (string)($r['status'] ?? 'scheduled'), 'recur' => $j($r['recur'] ?? null), 'exdates' => $j($r['exdates'] ?? null) ?: [],
            'reminders' => $j($r['reminders'] ?? null) ?: [], 'owner_id' => ($r['owner_id'] ?? null) ? (int)$r['owner_id'] : (($r['created_by'] ?? null) ? (int)$r['created_by'] : null),
            'department_id' => $r['department_id'] ? (int)$r['department_id'] : null, 'entity_type' => $r['entity_type'] ?? null, 'entity_id' => $r['entity_id'] ? (int)$r['entity_id'] : null,
            'series_id' => ($r['series_id'] ?? null) ? (int)$r['series_id'] : null, 'created_by' => $r['created_by'] ? (int)$r['created_by'] : null,
            'origin' => (string)($r['origin'] ?? '')];   // BT68: '' made in ITOps, 'outlook' their own Outlook event, 'outlook_invite' an Outlook invitation
    }
    function itops_cal_get(PDO $db, int $id): ?array {
        $s = $db->prepare('SELECT * FROM itops_calendar_events WHERE id=?'); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); if (!$r) return null;
        $e = itops_cal_row($r);
        $a = $db->prepare('SELECT a.user_id, a.email, a.name, a.response, u.display_name, u.email AS uemail FROM itops_calendar_attendees a LEFT JOIN itops_users u ON u.id = a.user_id WHERE a.event_id=? ORDER BY a.id'); $a->execute([$id]);
        $e['attendees'] = array_map(fn($x) => ['user_id' => $x['user_id'] ? (int)$x['user_id'] : null, 'email' => (string)($x['user_id'] ? $x['uemail'] : $x['email']), 'name' => (string)($x['user_id'] ? $x['display_name'] : $x['name']), 'response' => (string)$x['response']], $a->fetchAll(PDO::FETCH_ASSOC));
        return $e;
    }
    /** May this person see the event (private events: organizer, attendees, creator, Super Admin)? */
    function itops_cal_can_see(array $e, array $user, ?array $attUserIds = null): bool {
        if (($e['visibility'] ?? 'team') !== 'private') return true;
        $uid = (int)($user['id'] ?? 0); if (($user['role_slug'] ?? '') === 'super_admin') return true;
        if ($uid && ($uid === (int)($e['owner_id'] ?? 0) || $uid === (int)($e['created_by'] ?? 0))) return true;
        $ids = $attUserIds ?? array_map(fn($a) => (int)$a['user_id'], array_filter($e['attendees'] ?? [], fn($a) => $a['user_id']));
        return in_array($uid, $ids, true);
    }

    /** Check an event sent by a person. Returns [event fields, attendees]. Throws InvalidArgumentException with a sentence. */
    function itops_cal_clean(PDO $db, array $in, ?array $cur, array $types, array $user): array {
        $bad = fn($m) => throw new InvalidArgumentException($m);
        $o = $cur ?? ['title' => '', 'description' => '', 'event_type' => 'meeting', 'start' => null, 'end' => null, 'all_day' => false, 'color' => '', 'location' => '', 'site_id' => null, 'online_url' => '',
                      'visibility' => 'team', 'status' => 'scheduled', 'recur' => null, 'exdates' => [], 'reminders' => [], 'owner_id' => (int)$user['id'], 'department_id' => null, 'entity_type' => null, 'entity_id' => null];
        $str = function ($k, int $max, bool $multi = false) use ($in, $bad) { if (!is_string($in[$k])) $bad('The event is not in the expected form (text values).'); $v = $multi ? trim(str_replace("\r", '', $in[$k])) : trim(preg_replace('/\s+/u', ' ', $in[$k]) ?? ''); if (mb_strlen($v) > $max) $bad('That text is too long (' . $max . ' characters at most).'); return $v; };
        if (array_key_exists('title', $in)) $o['title'] = $str('title', 255);
        if ($o['title'] === '') $bad('Give the event a title.');
        if (array_key_exists('description', $in)) $o['description'] = $str('description', 5000, true);
        if (array_key_exists('event_type', $in)) { if (!itops_cal_type($types, (string)$in['event_type'])) $bad('Choose what kind of event it is.'); $o['event_type'] = (string)$in['event_type']; }
        if (array_key_exists('all_day', $in)) { if (!is_bool($in['all_day'])) $bad('"All day" is on or off.'); $o['all_day'] = $in['all_day']; }
        $dt = function ($v, string $what) use ($bad, $o): string {
            if (!is_string($v)) $bad('Choose the ' . $what . '.');
            $v = trim(str_replace('T', ' ', $v));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v .= ' 00:00:00';
            elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ':00';
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) $bad('The ' . $what . ' is not a valid date and time.');
            if ((int)$m[1] < 2000 || (int)$m[1] > 2100) $bad('The ' . $what . ' is out of range.');
            return $v;
        };
        if (array_key_exists('start', $in)) $o['start'] = $dt($in['start'], 'start');
        if (array_key_exists('end', $in)) $o['end'] = ($in['end'] === null || $in['end'] === '') ? null : $dt($in['end'], 'end');
        if (!$o['start']) $bad('Choose when the event starts.');
        if ($o['all_day']) { $o['start'] = substr($o['start'], 0, 10) . ' 00:00:00'; if ($o['end']) $o['end'] = substr($o['end'], 0, 10) . ' 00:00:00'; }
        if ($o['end'] !== null && $o['end'] < $o['start']) $bad('The event ends before it starts.');
        if ($o['end'] !== null && strtotime($o['end']) - strtotime($o['start']) > 62 * 86400) $bad('An event lasts at most 62 days; use a repeat for longer.');
        if (array_key_exists('color', $in)) { $c = strtoupper(trim((string)$in['color'])); if ($c !== '' && !preg_match('/^#[0-9A-F]{6}$/', $c)) $bad('The colour is a hex colour such as #1D4D7A.'); $o['color'] = $c; }
        if (array_key_exists('location', $in)) $o['location'] = $str('location', 200);
        if (array_key_exists('online_url', $in)) { $u = $str('online_url', 500); if ($u !== '' && !preg_match('~^https://[^\s<>"]+$~i', $u)) $bad('The online meeting link starts with https://.'); $o['online_url'] = $u; }
        if (array_key_exists('visibility', $in)) { if (!in_array($in['visibility'], ['team', 'private'], true)) $bad('Visible to the team, or private.'); $o['visibility'] = $in['visibility']; }
        if (array_key_exists('status', $in)) { if (!in_array($in['status'], ['scheduled', 'tentative', 'cancelled', 'done'], true)) $bad('Choose the status: scheduled, tentative, cancelled or done.'); $o['status'] = $in['status']; }
        foreach (['site_id' => ['itops_budget_sites', 'That office is not in the list any more.'], 'department_id' => ['itops_departments', 'That department is not in the list any more.'], 'owner_id' => ['itops_users', 'Choose the organizer from the list.']] as $k => [$tbl, $msg]) if (array_key_exists($k, $in)) {
            $v = $in[$k]; if ($v === null || $v === '' || $v === 0) { if ($k === 'owner_id') $bad('Every event has an organizer.'); $o[$k] = null; continue; }
            if (!is_numeric($v)) $bad($msg);
            try { $s = $db->prepare("SELECT COUNT(*) FROM $tbl WHERE id=?" . ($tbl === 'itops_users' ? " AND status='active'" : '')); $s->execute([(int)$v]); if (!$s->fetchColumn()) $bad($msg); } catch (InvalidArgumentException $e) { throw $e; } catch (Throwable $e) { $bad($msg); }
            $o[$k] = (int)$v;
        }
        if (array_key_exists('reminders', $in)) {
            if (!is_array($in['reminders']) || count($in['reminders']) > 5) $bad('Up to five reminders.');
            $r = []; foreach ($in['reminders'] as $m) { if (!is_numeric($m) || !array_key_exists((int)$m, itops_cal_reminder_choices())) $bad('Choose the reminders from the list.'); $r[] = (int)$m; }
            rsort($r); $o['reminders'] = array_values(array_unique($r));
        }
        if (array_key_exists('recur', $in)) $o['recur'] = itops_cal_recur_clean($in['recur'], $o['start']);
        if (array_key_exists('entity_type', $in) || array_key_exists('entity_id', $in)) {
            $et = (string)($in['entity_type'] ?? ''); $ei = $in['entity_id'] ?? null;
            if ($et === '' || $ei === null || $ei === '') { $o['entity_type'] = null; $o['entity_id'] = null; }
            else {
                $map = ['ticket' => 'itops_tickets', 'contract' => 'itops_contracts', 'vendor' => 'itops_vendors', 'asset' => 'itops_assets', 'sop' => 'itops_sops', 'case' => 'itops_cases'];   // BT77: a case's meeting
                if (!isset($map[$et]) || !is_numeric($ei)) $bad('The related item is not one the calendar knows.');
                $s = $db->prepare("SELECT COUNT(*) FROM {$map[$et]} WHERE id=?"); $s->execute([(int)$ei]); if (!$s->fetchColumn()) $bad('The related item is not there any more.');
                $o['entity_type'] = $et; $o['entity_id'] = (int)$ei;
            }
        }
        $att = null;
        if (array_key_exists('attendees', $in)) {
            if (!is_array($in['attendees']) || count($in['attendees']) > 100) $bad('Up to 100 attendees.');
            $att = []; $seen = [];
            foreach ($in['attendees'] as $a) {
                if (!is_array($a)) $bad('The attendees are not in the expected form.');
                if (!empty($a['user_id'])) {
                    if (!is_numeric($a['user_id'])) $bad('Choose the attendees from the list.');
                    $s = $db->prepare("SELECT id FROM itops_users WHERE id=? AND status='active'"); $s->execute([(int)$a['user_id']]); if (!$s->fetchColumn()) $bad('An attendee is not an active person of the app any more.');
                    $k = 'u' . (int)$a['user_id']; if (isset($seen[$k])) continue; $seen[$k] = 1;
                    $att[] = ['user_id' => (int)$a['user_id'], 'email' => '', 'name' => ''];
                } else {
                    $em = mb_strtolower(trim((string)($a['email'] ?? ''))); if (!filter_var($em, FILTER_VALIDATE_EMAIL) || mb_strlen($em) > 190) $bad('"' . mb_substr($em, 0, 60) . '" is not an email address.');
                    $k = 'e' . $em; if (isset($seen[$k])) continue; $seen[$k] = 1;
                    $att[] = ['user_id' => null, 'email' => $em, 'name' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($a['name'] ?? '')) ?? ''), 0, 160)];
                }
            }
        }
        return [$o, $att];
    }

    function itops_cal_recur_clean($r, string $start): ?array {
        $bad = fn($m) => throw new InvalidArgumentException($m);
        if ($r === null || $r === '' || $r === false) return null;
        if (!is_array($r)) $bad('The repeat is not in the expected form.');
        $f = (string)($r['freq'] ?? ''); if ($f === '' || $f === 'none') return null;
        if (!in_array($f, ['daily', 'weekly', 'monthly', 'yearly'], true)) $bad('Repeat daily, weekly, monthly or yearly.');
        $i = (int)($r['interval'] ?? 1); if ($i < 1 || $i > 99) $bad('Repeat every 1 to 99 ' . ['daily' => 'days', 'weekly' => 'weeks', 'monthly' => 'months', 'yearly' => 'years'][$f] . '.');
        $o = ['freq' => $f, 'interval' => $i];
        if ($f === 'weekly') {
            $days = array_values(array_intersect(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], array_map('strtoupper', (array)($r['byday'] ?? []))));
            if (!$days) $days = [strtoupper(substr(date('D', strtotime($start)), 0, 2))];
            $o['byday'] = $days;
        }
        if ($f === 'monthly') { $m = (string)($r['monthly'] ?? 'day'); if (!in_array($m, ['day', 'nth', 'last'], true)) $bad('Repeat monthly on the same day, or on the same weekday of the month.'); $o['monthly'] = $m; }
        $o['until'] = null; $o['count'] = null;
        if (!empty($r['until'])) { $u = (string)$r['until']; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $u) || !checkdate((int)substr($u, 5, 2), (int)substr($u, 8, 2), (int)substr($u, 0, 4))) $bad('The repeat end is not a valid date.'); if ($u < substr($start, 0, 10)) $bad('The repeat ends before the first event.'); $o['until'] = $u; }
        elseif (!empty($r['count'])) { $c = (int)$r['count']; if ($c < 1 || $c > 500) $bad('Repeat 1 to 500 times.'); $o['count'] = $c; }
        elseif (strtotime(substr($start, 0, 10) . ' +5 years') !== false) $o['until'] = date('Y-m-d', strtotime(substr($start, 0, 10) . ' +5 years'));   // never forever: five years at most
        return $o;
    }
    /** "Every 2 weeks on Tue, Thu until Dec 31, 2026" */
    function itops_cal_recur_text(?array $r, string $start): string {
        if (!$r) return '';
        $u = ['daily' => ['day', 'days'], 'weekly' => ['week', 'weeks'], 'monthly' => ['month', 'months'], 'yearly' => ['year', 'years']][$r['freq']];
        $t = 'Every ' . ($r['interval'] > 1 ? $r['interval'] . ' ' . $u[1] : $u[0]);
        $dn = ['MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu', 'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun'];
        if ($r['freq'] === 'weekly') $t .= ' on ' . implode(', ', array_map(fn($d) => $dn[$d], $r['byday']));
        if ($r['freq'] === 'monthly') { $ts = strtotime($start); $n = intdiv((int)date('j', $ts) - 1, 7) + 1; $ord = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth'];
            $t .= $r['monthly'] === 'day' ? ' on day ' . date('j', $ts) : ($r['monthly'] === 'last' ? ' on the last ' . date('l', $ts) : ' on the ' . $ord[$n] . ' ' . date('l', $ts)); }
        if ($r['until']) $t .= ' until ' . date('m/d/Y', strtotime($r['until'])); elseif ($r['count']) $t .= ', ' . $r['count'] . ' times';
        return $t;
    }

    /** The start times of an event between $from and $to (YYYY-MM-DD, both included). At most 1,000. */
    function itops_cal_occurrences(array $e, string $from, string $to): array {
        $S = strtotime($e['start']); $dur = $e['end'] ? strtotime($e['end']) - $S : 0; $time = date('H:i:s', $S);
        $f0 = strtotime($from . ' 00:00:00'); $t1 = strtotime($to . ' 23:59:59');
        $r = $e['recur']; $ex = array_flip((array)($e['exdates'] ?? []));
        if (!$r) return ($S <= $t1 && $S + max(0, $dur) >= $f0) ? [date('Y-m-d H:i:s', $S)] : [];
        $out = []; $n = 0; $until = $r['until'] ? strtotime($r['until'] . ' 23:59:59') : PHP_INT_MAX; $count = $r['count'] ?: PHP_INT_MAX; $guard = 0;
        $emit = function (int $ts) use (&$out, &$n, $S, $f0, $t1, $dur, $ex, $until, $count): bool {   // false = stop
            if ($ts < $S) return true;
            if ($ts > $until || $n >= $count || $ts > $t1) return false;
            $n++;
            if (!isset($ex[date('Y-m-d', $ts)]) && $ts + max(0, $dur) >= $f0) $out[] = date('Y-m-d H:i:s', $ts);
            return true;
        };
        $d0 = date('Y-m-d', $S); $i = (int)$r['interval'];
        switch ($r['freq']) {
            case 'daily':
                for ($k = 0; $guard++ < 20000; $k += $i) if (!$emit(strtotime("$d0 $time +$k days"))) break;
                break;
            case 'weekly':
                $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7]; $days = array_map(fn($d) => $map[$d], $r['byday']); sort($days);
                $monday = strtotime($d0 . ' -' . ((int)date('N', $S) - 1) . ' days');
                for ($w = 0; $guard++ < 5000; $w += $i) { $stop = false;
                    foreach ($days as $dn) { $ts = strtotime(date('Y-m-d', $monday) . " $time +" . ($w * 7 + $dn - 1) . ' days'); if (!$emit($ts)) { $stop = true; break; } }
                    if ($stop) break; }
                break;
            case 'monthly':
                $dom = (int)date('j', $S); $wd = (int)date('N', $S); $nth = intdiv($dom - 1, 7) + 1; $y = (int)date('Y', $S); $m = (int)date('n', $S);
                for ($k = 0; $guard++ < 2000; $k += $i) {
                    $mm = $m + $k; $yy = $y + intdiv($mm - 1, 12); $mm = ($mm - 1) % 12 + 1; $dim = (int)date('t', mktime(0, 0, 0, $mm, 1, $yy));
                    if ($r['monthly'] === 'day') { if ($dom > $dim) { if (mktime(0, 0, 0, $mm, 1, $yy) > min($until, $t1)) break; continue; } $day = $dom; }
                    else {
                        $first = (int)date('N', mktime(0, 0, 0, $mm, 1, $yy)); $firstHit = 1 + (($wd - $first + 7) % 7);
                        if ($r['monthly'] === 'last') { $day = $firstHit; while ($day + 7 <= $dim) $day += 7; }
                        else { $day = $firstHit + ($nth - 1) * 7; if ($day > $dim) { if (mktime(0, 0, 0, $mm, 1, $yy) > min($until, $t1)) break; continue; } }
                    }
                    if (!$emit(strtotime(sprintf('%04d-%02d-%02d %s', $yy, $mm, $day, $time)))) break;
                }
                break;
            case 'yearly':
                $md = date('m-d', $S); $y = (int)date('Y', $S);
                for ($k = 0; $guard++ < 200; $k += $i) { $yy = $y + $k; if ($md === '02-29' && !checkdate(2, 29, $yy)) { if (mktime(0, 0, 0, 1, 1, $yy) > min($until, $t1)) break; continue; } if (!$emit(strtotime("$yy-$md $time"))) break; }
                break;
        }
        return array_slice($out, 0, 1000);
    }

    // ── the agenda ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Everything this person may see between $from and $to: the due dates of other modules (each with its own
     * permission, BT24) and the calendar events with their occurrences. $opt: mine (only the viewer's own items),
     * types (event types to keep), site_id, user_id (organized by or attended by), department_id, sources (bool: other modules).
     */
    function itops_cal_agenda(PDO $db, array $currentUser, string $from, string $to, array $opt = []): array {
        $f = $from . ' 00:00:00'; $tt = $to . ' 23:59:59'; $ev = [];
        $mine = !empty($opt['mine']); $uid = (int)$currentUser['id'];
        $canT = itops_can($currentUser, 'tickets', 'view'); $allT = $canT && itops_can($currentUser, 'tickets', 'all');
        $tryQ = function ($sql, $args) use ($db) { try { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };
        if (!array_key_exists('sources', $opt) || $opt['sources']) {
        $edT = itops_can($currentUser, 'tickets', 'edit');
        if ($canT) {
            $own = ($allT && !$mine) ? '' : "AND (assignee_id=$uid OR requester_id=$uid OR backup_id=$uid) ";
            $st = $db->prepare("SELECT id,ticket_number,subject,priority,status,due_date,sla_response_due,resolved_at,closed_at,first_response_at FROM itops_tickets WHERE status NOT IN ('cancelled') " . $own . "AND ((due_date BETWEEN ? AND ?) OR (sla_response_due BETWEEN ? AND ?))");
            $st->execute([$f,$tt,$f,$tt]);
            foreach ($st->fetchAll() as $r) {
                $open = !in_array($r['status'], ['resolved','closed']);
                if ($r['due_date'] && $r['due_date']>=$f && $r['due_date']<=$tt)
                    $ev[] = ['date'=>substr($r['due_date'],0,10),'datetime'=>$r['due_date'],'type'=>'ticket_resolution','title'=>$r['ticket_number'].' · resolution due','subtitle'=>$r['subject'],'entity'=>'ticket','entity_id'=>(int)$r['id'],'page'=>'tickets','color'=>'#C44545','overdue'=>($open && strtotime($r['due_date'])<time()),'can_edit'=>$edT];
                if ($r['sla_response_due'] && $r['sla_response_due']>=$f && $r['sla_response_due']<=$tt && empty($r['first_response_at']))
                    $ev[] = ['date'=>substr($r['sla_response_due'],0,10),'datetime'=>$r['sla_response_due'],'type'=>'ticket_response','title'=>$r['ticket_number'].' · response due','subtitle'=>$r['subject'],'entity'=>'ticket','entity_id'=>(int)$r['id'],'page'=>'tickets','color'=>'#C08A30','overdue'=>($open && strtotime($r['sla_response_due'])<time()),'can_edit'=>$edT];
            }
        }
        $tryQ = function($sql,$args) use ($db) { try { $s=$db->prepare($sql); $s->execute($args); return $s->fetchAll(); } catch (Throwable $e) { return []; } };
        $ownQ = function($col) use ($mine,$uid) { return $mine ? " AND $col=$uid" : ''; };
        if (itops_can($currentUser, 'contracts', 'view')) { $ed = itops_can($currentUser, 'contracts', 'edit');
            foreach ($tryQ("SELECT id,title,end_date FROM itops_contracts WHERE end_date BETWEEN ? AND ? AND status NOT IN ('renewed','terminated','archived')" . $ownQ('owner_id'), [$from,$to]) as $r)
                $ev[] = ['date'=>$r['end_date'],'datetime'=>$r['end_date'].' 00:00:00','type'=>'contract_end','title'=>'Contract ends','subtitle'=>$r['title'],'entity'=>'contract','entity_id'=>(int)$r['id'],'page'=>'contracts','color'=>'#5E6FA8','overdue'=>(strtotime($r['end_date'])<time()),'can_edit'=>$ed]; }
        if (itops_can($currentUser, 'sops', 'view')) { $ed = itops_can($currentUser, 'sops', 'edit');
            foreach ($tryQ("SELECT s.id,s.title,s.next_review_date,s.owner_id,u.display_name AS owner_name FROM itops_sops s LEFT JOIN itops_users u ON u.id=s.owner_id WHERE s.next_review_date BETWEEN ? AND ? AND s.status<>'archived'" . $ownQ('s.owner_id'), [$from,$to]) as $r)
                $ev[] = ['date'=>$r['next_review_date'],'datetime'=>$r['next_review_date'].' 00:00:00','type'=>'sop_review','title'=>'SOP review due','subtitle'=>$r['title'] . ($r['owner_name'] ? ' · ' . $r['owner_name'] : ''),'entity'=>'sop','entity_id'=>(int)$r['id'],'page'=>'sops','color'=>'#3D9A6E','overdue'=>(strtotime($r['next_review_date'])<time()),'can_edit'=>$ed,'owner_id'=>$r['owner_id']?(int)$r['owner_id']:null]; }
        if (itops_can($currentUser, 'creds', 'view')) { $ed = itops_can($currentUser, 'creds', 'edit');
            foreach ($tryQ("SELECT id,name,valid_until FROM itops_credentials WHERE valid_until BETWEEN ? AND ? AND status NOT IN ('retired','archived')" . $ownQ('owner_id'), [$from,$to]) as $r)
                $ev[] = ['date'=>$r['valid_until'],'datetime'=>$r['valid_until'].' 00:00:00','type'=>'cred_term','title'=>'Credential term ends','subtitle'=>$r['name'],'entity'=>'credential','entity_id'=>(int)$r['id'],'page'=>'credentials','color'=>'#8E5BB5','overdue'=>(strtotime($r['valid_until'])<strtotime(date('Y-m-d'))),'can_edit'=>$ed];
            foreach ($tryQ("SELECT id,name,next_rotation_date FROM itops_credentials WHERE next_rotation_date BETWEEN ? AND ? AND status NOT IN ('retired','archived','disabled')" . $ownQ('owner_id'), [$from,$to]) as $r)
                $ev[] = ['date'=>$r['next_rotation_date'],'datetime'=>$r['next_rotation_date'].' 00:00:00','type'=>'cred_rotation','title'=>'Credential rotation','subtitle'=>$r['name'],'entity'=>'credential','entity_id'=>(int)$r['id'],'page'=>'credentials','color'=>'#1D4D7A','overdue'=>(strtotime($r['next_rotation_date'])<time()),'can_edit'=>$ed]; }
        // BT60: equipment warranty ends
        if (itops_can($currentUser, 'assets', 'view')) { $ed = itops_can($currentUser, 'assets', 'edit');
            foreach ($tryQ("SELECT id, asset_tag, make, model, warranty_expiry FROM itops_assets WHERE warranty_expiry BETWEEN ? AND ? AND status NOT IN ('retired','lost')" . ($mine ? " AND assigned_to=$uid" : ''), [$from, $to]) as $r)
                $ev[] = ['date' => $r['warranty_expiry'], 'datetime' => $r['warranty_expiry'] . ' 00:00:00', 'type' => 'warranty_end', 'title' => 'Warranty ends', 'subtitle' => trim($r['asset_tag'] . ' · ' . $r['make'] . ' ' . $r['model']), 'entity' => 'asset', 'entity_id' => (int)$r['id'], 'page' => 'assets', 'color' => '#7A6A3A', 'overdue' => false, 'can_edit' => $ed, 'all_day' => true]; }
        // BT77: case task dates and target dates (the person's own, the cases they own or watch) — they stay in ITOps
        if (is_file(__DIR__ . '/Cases.php')) { try { require_once __DIR__ . '/Cases.php'; foreach (itops_cs_agenda($db, $currentUser, $from, $to, $mine) as $x) $ev[] = $x; } catch (Throwable $e) {} }
        }
        // calendar events
        if (itops_can($currentUser, 'calendar', 'view')) {
            $types = itops_cal_types($db); $tmap = []; foreach ($types as $t) $tmap[$t['key']] = $t;
            $edE = itops_can($currentUser, 'calendar', 'edit'); $isSuper = ($currentUser['role_slug'] ?? '') === 'super_admin';
            $sites = []; try { foreach ($db->query('SELECT id, name FROM itops_budget_sites')->fetchAll(PDO::FETCH_ASSOC) as $s) $sites[(int)$s['id']] = $s['name']; } catch (Throwable $e) {}
            $rows = $tryQ("SELECT e.*, u.display_name AS owner_name FROM itops_calendar_events e LEFT JOIN itops_users u ON u.id = COALESCE(e.owner_id, e.created_by)
                           WHERE e.start_date <= ? AND (e.recur IS NOT NULL OR COALESCE(e.end_date, e.start_date) >= ?)", [$tt, $f]);
            $ids = array_map(fn($r) => (int)$r['id'], $rows); $att = []; $caseRole = [];
            if ($ids) { foreach ($tryQ('SELECT a.event_id, a.user_id, a.email, a.name, u.display_name FROM itops_calendar_attendees a LEFT JOIN itops_users u ON u.id = a.user_id WHERE a.event_id IN (' . implode(',', $ids) . ')', []) as $a) $att[(int)$a['event_id']][] = $a; }
            foreach ($rows as $r) {
                $e = itops_cal_row($r); $au = array_map(fn($a) => (int)$a['user_id'], array_filter($att[$e['id']] ?? [], fn($a) => $a['user_id']));
                if (!itops_cal_can_see($e, $currentUser, $au)) continue;
                $mineE = $uid === (int)$e['owner_id'] || $uid === (int)$e['created_by'] || in_array($uid, $au, true);
                if ($mine && !$mineE) continue;
                if (!empty($opt['types']) && !in_array($e['event_type'], (array)$opt['types'], true)) continue;
                if (!empty($opt['site_id']) && (int)$e['site_id'] !== (int)$opt['site_id']) continue;
                if (!empty($opt['department_id']) && (int)$e['department_id'] !== (int)$opt['department_id']) continue;
                if (!empty($opt['user_id'])) { $pu = (int)$opt['user_id']; if ($pu !== (int)$e['owner_id'] && !in_array($pu, $au, true)) continue; }
                $t = $tmap[$e['event_type']] ?? ['label' => ucfirst(str_replace('_', ' ', $e['event_type'])), 'color' => '#8FB3C7', 'window' => false];
                $dur = $e['end'] ? strtotime($e['end']) - strtotime($e['start']) : 0;
                $hide = false;   // BT92 (code audit Low): a case meeting's text is for the case's people (and its invitees)
                if (($e['entity_type'] ?? '') === 'case' && !$isSuper && !$mineE) {
                    if (!array_key_exists((int)$e['entity_id'], $caseRole)) { $caseRole[(int)$e['entity_id']] = null; try { require_once __DIR__ . '/Cases.php'; $cs = itops_cs_get($db, (int)$e['entity_id']); $caseRole[(int)$e['entity_id']] = $cs ? itops_cs_role($db, $cs, $currentUser) : null; } catch (Throwable $x) {} }
                    $hide = !in_array($caseRole[(int)$e['entity_id']], ['owner', 'lead', 'member'], true);
                }
                $canE = $edE && ($isSuper || $mineE || $e['visibility'] === 'team');
                foreach (itops_cal_occurrences($e, $from, $to) as $occ) {
                    $end = $e['end'] ? date('Y-m-d H:i:s', strtotime($occ) + $dur) : null;
                    $ev[] = ['date' => substr($occ, 0, 10), 'datetime' => $occ, 'end' => $end, 'all_day' => $e['all_day'], 'type' => 'event', 'event_type' => $e['event_type'], 'type_label' => $t['label'],
                             'title' => $hide ? 'Case meeting' : $e['title'], 'subtitle' => $hide ? '' : mb_substr($e['description'], 0, 200), 'location' => $hide ? '' : ($e['site_id'] && isset($sites[$e['site_id']]) ? $sites[$e['site_id']] . ($e['location'] !== '' ? ' · ' . $e['location'] : '') : $e['location']),
                             'site_id' => $e['site_id'], 'online' => !$hide && $e['online_url'] !== '', 'hidden_text' => $hide, 'status' => $e['status'], 'visibility' => $e['visibility'], 'recurring' => (bool)$e['recur'], 'occ' => substr($occ, 0, 10),
                             'owner_id' => $e['owner_id'], 'owner_name' => (string)($r['owner_name'] ?? ''), 'attendees' => count($att[$e['id']] ?? []), 'attendee_ids' => $au, 'window' => !empty($t['window']),
                             'entity' => 'calendar', 'entity_id' => $e['id'], 'page' => 'calendar', 'color' => $e['color'] ?: $t['color'], 'overdue' => false, 'can_edit' => $canE, 'reminders' => $e['reminders']];
                }
            }
        }
        usort($ev, fn($a, $b) => strcmp($a['datetime'], $b['datetime']));
        return $ev;
    }

    // ── reminders (main scheduler) ─────────────────────────────────────────────────────────────────────────────────

    /** Send the reminders that are due: in the app and by email, to the organizer and the attendees who are people of the app. */
    function itops_cal_reminders_run(PDO $db): int {
        try { $db->query('SELECT 1 FROM itops_calendar_reminders_sent LIMIT 1'); } catch (Throwable $e) { return 0; }
        $now = time(); $sent = 0; $types = itops_cal_types($db); $tl = []; foreach ($types as $t) $tl[$t['key']] = $t['label'];
        $rows = $db->query("SELECT * FROM itops_calendar_events WHERE reminders IS NOT NULL AND reminders NOT IN ('','[]') AND status NOT IN ('cancelled','done')
                            AND start_date <= '" . date('Y-m-d H:i:s', $now + 8 * 86400) . "' AND (recur IS NOT NULL OR start_date >= '" . date('Y-m-d H:i:s', $now - 86400) . "')")->fetchAll(PDO::FETCH_ASSOC);
        $sites = []; try { foreach ($db->query('SELECT id, name FROM itops_budget_sites')->fetchAll(PDO::FETCH_ASSOC) as $s) $sites[(int)$s['id']] = $s['name']; } catch (Throwable $e) {}
        $mark = $db->prepare('INSERT IGNORE INTO itops_calendar_reminders_sent (event_id, occ, minutes, user_id, sent_at) VALUES (?,?,?,?,?)');
        $note = $db->prepare('INSERT INTO itops_notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)');
        foreach ($rows as $r) {
            $e = itops_cal_row($r); if (!$e['reminders']) continue;
            $who = []; if ($e['owner_id']) $who[] = $e['owner_id'];
            foreach ($db->query('SELECT user_id FROM itops_calendar_attendees WHERE event_id=' . (int)$e['id'] . ' AND user_id IS NOT NULL AND response <> \'declined\'')->fetchAll(PDO::FETCH_COLUMN) as $u) $who[] = (int)$u;
            $who = array_values(array_unique($who)); if (!$who) continue;
            foreach (itops_cal_occurrences($e, date('Y-m-d', $now - 86400), date('Y-m-d', $now + 8 * 86400)) as $occ) {
                $base = $e['all_day'] ? strtotime(substr($occ, 0, 10) . ' 08:00:00') : strtotime($occ);
                foreach ($e['reminders'] as $m) {
                    $at = $base - $m * 60; if ($at > $now || $at < $now - 6 * 3600) continue;   // due, and not older than six hours (no flood after a pause)
                    foreach ($who as $u) {
                        $mark->execute([$e['id'], substr($occ, 0, 10), $m, $u, date('Y-m-d H:i:s')]); if ($mark->rowCount() !== 1) continue;
                        $uRow = $db->query('SELECT id, email, display_name FROM itops_users WHERE id=' . (int)$u . " AND status='active'")->fetch(PDO::FETCH_ASSOC); if (!$uRow) continue;
                        $end = $e['end'] ? date('g:i A', strtotime($occ) + strtotime($e['end']) - strtotime($e['start'])) : '';
                        $when = $e['all_day'] ? date('D, m/d/Y', strtotime($occ)) . ' (all day)' : date('D, m/d/Y g:i A', strtotime($occ)) . ($end ? ' – ' . $end : '');
                        $where = trim(($e['site_id'] && isset($sites[$e['site_id']]) ? $sites[$e['site_id']] : '') . ($e['location'] !== '' ? ' · ' . $e['location'] : '') . ($e['online_url'] !== '' ? ' · online' : ''), ' ·');
                        $note->execute([$u, 'Reminder: ' . $e['title'], $when . ($where !== '' ? ' · ' . $where : ''), 'info', 'calendar']);
                        if (!empty($uRow['email']) && function_exists('itops_email')) { try { itops_email('calendar_reminder', (string)$uRow['email'], ['event_title' => $e['title'], 'event_type' => $tl[$e['event_type']] ?? $e['event_type'], 'when' => $when, 'location' => $where !== '' ? $where : '—',
                            'recipient_name' => (string)$uRow['display_name'], 'link' => defined('APP_URL') ? rtrim(APP_URL, '/') . '/#calendar' : ''], ['user_id' => (int)$u]); } catch (Throwable $x) {} }
                        $sent++;
                    }
                }
            }
        }
        try { $db->prepare('DELETE FROM itops_calendar_reminders_sent WHERE sent_at < ?')->execute([date('Y-m-d H:i:s', $now - 60 * 86400)]); } catch (Throwable $e) {}
        return $sent;
    }

    // ── reports ─────────────────────────────────────────────────────────────────────────────────────────────────────

    function itops_cal_report_types(): array {
        return ['events' => ['Events', 'Every event in the period: when, kind, organizer, attendees, place and status. Click a total to show only those.'],
                'summary' => ['Events by type', 'How many events of each kind, and the hours they took.'],
                'deadlines' => ['Upcoming deadlines and renewals', 'Ticket due dates, contract ends, SOP reviews, credential rotations and terms, warranty ends, and deadline events.'],
                'windows' => ['Maintenance and change windows', 'Every maintenance or change window with its time, length, office and people.'],
                'people' => ['Attendance by person', 'Events each person organized or attended, and the hours.'],
                'offices' => ['Activity by office', 'Events per office and kind.'],
                'overdue' => ['Overdue items', 'Items past their date that are still open.'],
                'agenda' => ['Full agenda', 'Everything in the period, day by day.']];
    }

    /**
     * A calendar report: ['title', 'subtitle', 'columns' => [[label, w, align]], 'rows' => [[cells]|['cells','style']], 'kpis' => [[label, value, note]], 'ics' => events for a calendar file].
     * $f: from, to, types[], site_id, user_id, department_id, mine.
     */
    function itops_cal_report(PDO $db, array $user, string $type, array $f): array {
        $types = itops_cal_report_types(); if (!isset($types[$type])) throw new InvalidArgumentException('Choose a kind of report.');
        $from = (string)$f['from']; $to = (string)$f['to'];
        $ctypes = itops_cal_types($db); $tl = []; foreach ($ctypes as $t) $tl[$t['key']] = $t['label'];
        $hrs = fn(array $e) => $e['all_day'] ? 0.0 : ($e['end'] ? max(0, (strtotime($e['end']) - strtotime($e['datetime'])) / 3600) : 0.0);
        $h1 = fn(float $h) => $h > 0 ? rtrim(rtrim(number_format($h, 1), '0'), '.') : '—';
        $dt = fn(array $e) => $e['all_day'] || substr($e['datetime'], 11, 5) === '00:00' && ($e['type'] ?? '') !== 'event' ? date('D M j, Y', strtotime($e['date'])) : date('D M j, Y g:i A', strtotime($e['datetime']));
        $opt = ['mine' => !empty($f['mine']), 'types' => $f['types'] ?? [], 'site_id' => $f['site_id'] ?? null, 'user_id' => $f['user_id'] ?? null, 'department_id' => $f['department_id'] ?? null];
        $events = fn(array $o = []) => array_values(array_filter(itops_cal_agenda($db, $user, $from, $to, $o + $opt + ['sources' => false]), fn($e) => $e['type'] === 'event'));
        $period = date('m/d/Y', strtotime($from)) . ' – ' . date('m/d/Y', strtotime($to));
        $out = ['title' => $types[$type][0], 'subtitle' => $period, 'columns' => [], 'rows' => [], 'kpis' => [], 'ics' => [], 'landscape' => false];
        $kindLabel = ['ticket_resolution' => 'Ticket resolution due', 'ticket_response' => 'Ticket response due', 'contract_end' => 'Contract ends', 'sop_review' => 'SOP review', 'cred_rotation' => 'Credential rotation', 'cred_term' => 'Credential term ends', 'warranty_end' => 'Warranty ends', 'event' => 'Event'];
        // BT70: a KPI can carry a filter (column, value): clicking its tile on the page shows only those rows
        $kf = fn(string $label, string $value, string $note, int $col, ?string $eq) => [$label, $value, $note, $eq === null ? null : ['col' => $col, 'eq' => $eq]];
        $stl = ['scheduled' => 'Scheduled', 'tentative' => 'Tentative', 'cancelled' => 'Cancelled', 'done' => 'Done'];
        switch ($type) {
            case 'events': {
                $ev = $events(); $th = 0; $cnt = [];
                foreach ($ev as $e) { $h = $hrs($e); if ($e['status'] !== 'cancelled') $th += $h; $st = $stl[$e['status']] ?? ucfirst((string)$e['status']); $cnt[$st] = ($cnt[$st] ?? 0) + 1;
                    $out['rows'][] = ['cells' => [$dt($e) . ($e['end'] && !$e['all_day'] ? ' – ' . date('g:i A', strtotime($e['end'])) : ''), $tl[$e['event_type']] ?? $e['event_type'], $e['title'], (string)$e['owner_name'],
                        (string)(int)$e['attendees'], $e['location'] ?: ($e['online'] ? 'Online' : '—'), $h1($h), $st], 'style' => $e['status'] === 'cancelled' ? 'muted' : null]; }
                $out['columns'] = [['When', 2.2], ['Kind', 1.3], ['Title', 2.8], ['Organizer', 1.4], ['People', 0.7, 'R'], ['Where', 1.5], ['Hours', 0.7, 'R'], ['Status', 1]];
                $out['landscape'] = true;
                $out['kpis'] = [$kf('Events', (string)count($ev), $period, 7, null), $kf('Scheduled', (string)($cnt['Scheduled'] ?? 0), 'Still to happen or held', 7, 'Scheduled'),
                                $kf('Tentative', (string)($cnt['Tentative'] ?? 0), '', 7, 'Tentative'), $kf('Cancelled', (string)($cnt['Cancelled'] ?? 0), '', 7, 'Cancelled'), $kf('Done', (string)($cnt['Done'] ?? 0), '', 7, 'Done'),
                                [ 'Hours', $h1($th), 'Timed events, not cancelled']];
                $out['ics'] = $ev;
                break; }
            case 'summary': {
                $ev = array_filter($events(), fn($e) => $e['status'] !== 'cancelled'); $by = [];
                foreach ($ev as $e) { $k = $e['event_type']; $by[$k]['n'] = ($by[$k]['n'] ?? 0) + 1; $by[$k]['h'] = ($by[$k]['h'] ?? 0) + $hrs($e); $by[$k]['a'] = ($by[$k]['a'] ?? 0) + $e['attendees']; }
                uasort($by, fn($a, $b) => $b['n'] <=> $a['n']); $tot = count($ev); $th = array_sum(array_column($by, 'h'));
                foreach ($by as $k => $v) $out['rows'][] = [$tl[$k] ?? $k, (string)$v['n'], $h1($v['h']), $v['n'] ? number_format($v['a'] / $v['n'], 1) : '—', $tot ? round($v['n'] / $tot * 100) . '%' : '—'];
                if ($by) $out['rows'][] = ['cells' => ['All events', (string)$tot, $h1($th), '', '100%'], 'style' => 'total'];
                $out['columns'] = [['Kind of event', 3], ['Events', 1, 'R'], ['Hours', 1, 'R'], ['Attendees (avg)', 1.3, 'R'], ['Share', 1, 'R']];
                $out['kpis'] = [['Events', (string)$tot, $period], ['Hours', $h1($th), 'Timed events'], ['Kinds', (string)count($by), 'Of event']];
                $out['bars'] = array_map(fn($k, $v) => [$tl[$k] ?? $k, $v['n'], (string)$v['n']], array_keys($by), $by);
                break; }
            case 'deadlines': case 'overdue': {
                $all = itops_cal_agenda($db, $user, $type === 'overdue' ? date('Y-m-d', strtotime('-2 years')) : $from, $type === 'overdue' ? date('Y-m-d', strtotime('-1 day')) : $to, $opt + ['sources' => true]);
                $all = array_values(array_filter($all, fn($e) => $e['type'] !== 'event' || $e['event_type'] === 'deadline'));
                if ($type === 'overdue') $all = array_values(array_filter($all, fn($e) => !empty($e['overdue']) || ($e['type'] === 'event' && $e['status'] === 'scheduled')));
                $today = strtotime(date('Y-m-d'));
                foreach ($all as $e) { $days = (int)floor((strtotime($e['date']) - $today) / 86400);
                    $out['rows'][] = ['cells' => [date('D M j, Y', strtotime($e['date'])), $kindLabel[$e['type']] ?? $e['type'], $e['type'] === 'event' ? $e['title'] : (string)$e['subtitle'], (string)($e['owner_name'] ?? ''), $days === 0 ? 'today' : ($days > 0 ? 'in ' . $days . ' day' . ($days === 1 ? '' : 's') : abs($days) . ' day' . ($days === -1 ? '' : 's') . ' late')], 'style' => $days < 0 ? null : null]; }
                $out['columns'] = [['Date', 1.4], ['What', 1.6], ['Item', 3.4], ['Owner', 1.4], ['When', 1.2]];
                $by = []; foreach ($all as $e) $by[$e['type']] = ($by[$e['type']] ?? 0) + 1;
                $out['kpis'] = [[$type === 'overdue' ? 'Overdue' : 'Due in the period', (string)count($all), $type === 'overdue' ? 'Open items past their date' : $period]];
                foreach (array_slice($by, 0, 3, true) as $k => $n) $out['kpis'][] = $kf($kindLabel[$k] ?? $k, (string)$n, '', 1, $kindLabel[$k] ?? $k);
                if ($type === 'overdue') $out['subtitle'] = 'Open on ' . date('m/d/Y');
                break; }
            case 'windows': {
                $wk = array_map(fn($t) => $t['key'], array_filter($ctypes, fn($t) => !empty($t['window'])));
                $ev = $events(['types' => $opt['types'] ? array_values(array_intersect((array)$opt['types'], $wk)) : $wk]);
                $th = 0;
                foreach ($ev as $e) { $h = $hrs($e); $th += $h;
                    $out['rows'][] = ['cells' => [$dt($e) . ($e['end'] && !$e['all_day'] ? ' – ' . date('g:i A', strtotime($e['end'])) : ''), $tl[$e['event_type']] ?? $e['event_type'], $e['title'], $e['location'] ?: '—', $h1($h), (string)$e['owner_name'], (string)$e['attendees'], ucfirst($e['status'])], 'style' => $e['status'] === 'cancelled' ? 'muted' : null]; }
                $out['columns'] = [['When', 2.2], ['Kind', 1.4], ['Title', 2.8], ['Where', 1.6], ['Hours', 0.7, 'R'], ['Organizer', 1.4], ['People', 0.7, 'R'], ['Status', 1]];
                $out['landscape'] = true;
                $out['kpis'] = [['Windows', (string)count($ev), $period], ['Hours', $h1($th), 'Planned downtime or change time'], $kf('Cancelled', (string)count(array_filter($ev, fn($e) => $e['status'] === 'cancelled')), '', 7, 'Cancelled')];
                $out['ics'] = $ev;
                break; }
            case 'people': {
                $ev = array_filter($events(), fn($e) => $e['status'] !== 'cancelled'); $p = [];
                $names = []; foreach ($db->query("SELECT id, display_name FROM itops_users")->fetchAll(PDO::FETCH_ASSOC) as $u) $names[(int)$u['id']] = $u['display_name'];
                foreach ($ev as $e) { $h = $hrs($e);
                    if ($e['owner_id']) { $o = (int)$e['owner_id']; $p[$o]['org'] = ($p[$o]['org'] ?? 0) + 1; $p[$o]['h'] = ($p[$o]['h'] ?? 0) + $h; $p[$o]['t'][$e['event_type']] = ($p[$o]['t'][$e['event_type']] ?? 0) + 1; }
                    foreach ($e['attendee_ids'] as $a) { if ($a === (int)$e['owner_id']) continue; $p[$a]['att'] = ($p[$a]['att'] ?? 0) + 1; $p[$a]['h'] = ($p[$a]['h'] ?? 0) + $h; $p[$a]['t'][$e['event_type']] = ($p[$a]['t'][$e['event_type']] ?? 0) + 1; } }
                uasort($p, fn($a, $b) => ($b['h'] ?? 0) <=> ($a['h'] ?? 0));
                foreach ($p as $u => $v) { arsort($v['t']); $out['rows'][] = [$names[$u] ?? ('#' . $u), (string)($v['org'] ?? 0), (string)($v['att'] ?? 0), $h1((float)($v['h'] ?? 0)), implode(', ', array_map(fn($k, $n) => ($tl[$k] ?? $k) . ' ' . $n, array_keys(array_slice($v['t'], 0, 3, true)), array_slice($v['t'], 0, 3, true)))]; }
                $out['columns'] = [['Person', 2.2], ['Organized', 1, 'R'], ['Attended', 1, 'R'], ['Hours', 0.9, 'R'], ['Mostly', 3]];
                $out['kpis'] = [['People', (string)count($p), 'Organizers and attendees'], ['Events', (string)count($ev), $period]];
                break; }
            case 'offices': {
                $ev = array_filter($events(), fn($e) => $e['status'] !== 'cancelled'); $o = [];
                $sites = []; try { foreach ($db->query('SELECT id, name FROM itops_budget_sites')->fetchAll(PDO::FETCH_ASSOC) as $s) $sites[(int)$s['id']] = $s['name']; } catch (Throwable $e) {}
                foreach ($ev as $e) { $k = $e['site_id'] ? ($sites[$e['site_id']] ?? 'Office #' . $e['site_id']) : ($e['online'] ? 'Online' : 'No office set'); $o[$k]['n'] = ($o[$k]['n'] ?? 0) + 1; $o[$k]['h'] = ($o[$k]['h'] ?? 0) + $hrs($e); $o[$k]['t'][$e['event_type']] = ($o[$k]['t'][$e['event_type']] ?? 0) + 1; }
                uasort($o, fn($a, $b) => $b['n'] <=> $a['n']);
                foreach ($o as $k => $v) { arsort($v['t']); $out['rows'][] = [$k, (string)$v['n'], $h1($v['h']), implode(', ', array_map(fn($kk, $n) => ($tl[$kk] ?? $kk) . ' ' . $n, array_keys($v['t']), $v['t']))]; }
                $out['columns'] = [['Office', 2.4], ['Events', 0.9, 'R'], ['Hours', 0.9, 'R'], ['Kinds', 3.6]];
                $out['kpis'] = [['Offices', (string)count($o), 'With events'], ['Events', (string)count($ev), $period]];
                $out['bars'] = array_map(fn($k, $v) => [$k, $v['n'], (string)$v['n']], array_keys($o), $o);
                break; }
            case 'agenda': {
                $all = itops_cal_agenda($db, $user, $from, $to, $opt + ['sources' => !isset($f['sources']) || !empty($f['sources'])]);
                $day = '';
                foreach ($all as $e) {
                    if ($e['date'] !== $day) { $day = $e['date']; $out['rows'][] = ['cells' => [date('l, F j, Y', strtotime($day))], 'style' => 'group']; }
                    $isE = $e['type'] === 'event';
                    $out['rows'][] = ['cells' => [$isE ? ($e['all_day'] ? 'All day' : date('g:i A', strtotime($e['datetime'])) . ($e['end'] ? ' – ' . date('g:i A', strtotime($e['end'])) : '')) : '—', $isE ? ($tl[$e['event_type']] ?? $e['event_type']) : ($kindLabel[$e['type']] ?? $e['type']),
                        $isE ? $e['title'] : trim($e['title'] . ': ' . $e['subtitle']), $isE ? ($e['location'] ?: ($e['online'] ? 'Online' : '')) : '', $isE ? (string)$e['owner_name'] : ''], 'style' => $isE && $e['status'] === 'cancelled' ? 'muted' : null];
                }
                $out['columns'] = [['Time', 1.5], ['Kind', 1.5], ['What', 3.6], ['Where', 1.6], ['Organizer', 1.4]];
                $out['kpis'] = [['Items', (string)count($all), $period], ['Events', (string)count(array_filter($all, fn($e) => $e['type'] === 'event')), 'Calendar events'], ['Due dates', (string)count(array_filter($all, fn($e) => $e['type'] !== 'event')), 'From other modules']];
                $out['ics'] = array_values(array_filter($all, fn($e) => $e['type'] === 'event'));
                $out['landscape'] = true;
                break; }
        }
        $out['count'] = count(array_filter($out['rows'], fn($r) => !isset($r['style']) || $r['style'] !== 'group'));
        return $out;
    }

    /** A calendar file (.ics) of events, so they can be added to Outlook or any calendar. */
    function itops_cal_ics(array $events, string $name): string {
        $esc = fn(string $s) => str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
        $fold = function (string $l): string { $o = ''; while (strlen($l) > 74) { $cut = 74; while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) $cut--; $o .= substr($l, 0, $cut) . "\r\n "; $l = substr($l, $cut); } return $o . $l; };
        $host = preg_replace('/[^a-z0-9.\-]/i', '', preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'itops'))) ?: 'itops';
        $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ITOps//Calendar//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:' . $esc($name)];
        foreach ($events as $e) {
            $L[] = 'BEGIN:VEVENT';
            $L[] = 'UID:itops-' . (int)$e['entity_id'] . '-' . str_replace('-', '', (string)$e['occ']) . '@' . $host;
            $L[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            if ($e['all_day']) { $L[] = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($e['datetime'])); $L[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime(($e['end'] ?: $e['datetime']) . ' +1 day')); }
            else { $L[] = 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime($e['datetime'])); $L[] = 'DTEND:' . gmdate('Ymd\THis\Z', strtotime($e['end'] ?: date('Y-m-d H:i:s', strtotime($e['datetime']) + 3600))); }   // UTC: every calendar app reads it
            $L[] = 'SUMMARY:' . $esc((string)$e['title']);
            if (!empty($e['subtitle'])) $L[] = 'DESCRIPTION:' . $esc((string)$e['subtitle']);
            if (!empty($e['location'])) $L[] = 'LOCATION:' . $esc((string)$e['location']);
            if (!empty($e['type_label'])) $L[] = 'CATEGORIES:' . $esc((string)$e['type_label']);
            $L[] = 'STATUS:' . (($e['status'] ?? '') === 'cancelled' ? 'CANCELLED' : (($e['status'] ?? '') === 'tentative' ? 'TENTATIVE' : 'CONFIRMED'));
            $L[] = 'END:VEVENT';
        }
        $L[] = 'END:VCALENDAR';
        return implode("\r\n", array_map($fold, $L)) . "\r\n";
    }
}
