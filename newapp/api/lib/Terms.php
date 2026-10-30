<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Terms & renewals for Contracts and Credentials.
 *
 * MODEL
 *   Every row in opsh_contracts / opsh_credentials is ONE TERM (a period with a start and an end).
 *   Terms that belong together form a SERIES:
 *     series_id        id of the original term (the first one) — shared by every renewal
 *     term_no          1 = original, 2 = first renewal, 3 = second renewal …
 *     renewed_from_id  the term this one replaced
 *     renewed_to_id    the term that replaced this one (set when renewed)
 *     renewal_note     why/how it was renewed (required on renewal)
 *     ended_at / end_reason   when and why a term was closed without renewal
 *   Reference shown to users:  CT-0005 (original contract)  →  CT-0005-R1 (1st renewal)  →  CT-0005-R2 …
 *                               CR-0012 (original credential) → CR-0012-R1 …
 *
 * RULES
 *   - A term that has been renewed or ended is READ-ONLY (its own change history stays intact).
 *   - Renewal validates the new period: end after start, not before the previous term's start,
 *     at most 10 years. If the new term starts before the previous one ends (early renewal) the
 *     previous term's end date is moved to the day before; a coverage gap is allowed but recorded.
 *   - Contracts move active → expiring (within renewal_notice_days) → expired automatically.
 *   - Credentials keep their own rotation status; the term (valid_from/valid_until) is separate.
 */

if (!function_exists('opsh_term_cfg')) {
    function opsh_term_cfg(string $kind): array {
        if ($kind === 'contract') return [
            'kind' => 'contract', 'table' => 'opsh_contracts', 'entity' => 'contract', 'prefix' => 'CT',
            'start' => 'start_date', 'end' => 'end_date', 'label' => 'title',
            'ended' => ['renewed', 'terminated', 'archived'], 'renewed_status' => 'renewed', 'new_status' => 'active',
            'end_status' => ['terminated' => 'terminated', 'expired' => 'expired', 'not_renewed' => 'expired'],
            'copy' => ['title','vendor_id','contract_number','contract_type','department_id','auto_renew','renewal_notice_days',
                       'total_value','payment_frequency','payment_amount','owner_id','notes','tags'],
            'overrides' => ['contract_number','total_value','payment_amount','payment_frequency','notes','owner_id','auto_renew'],
        ];
        return [
            'kind' => 'credential', 'table' => 'opsh_credentials', 'entity' => 'credential', 'prefix' => 'CR',
            'start' => 'valid_from', 'end' => 'valid_until', 'label' => 'name',
            'ended' => ['retired', 'archived'], 'renewed_status' => 'retired', 'new_status' => 'current',
            'end_status' => ['terminated' => 'retired', 'expired' => 'retired', 'not_renewed' => 'retired'],
            'copy' => ['name','description','category_id','department_id','system_url','environment','owner_id','rotation_days',
                       'username_enc','password_enc','api_key_enc','mfa_details_enc','notes_enc'],
            'overrides' => ['description','system_url','owner_id','rotation_days'],
        ];
    }

    function opsh_term_ref(string $prefix, $seriesId, $termNo): string {
        return sprintf('%s-%04d', $prefix, (int)$seriesId) . ((int)$termNo > 1 ? '-R' . ((int)$termNo - 1) : '');
    }

    /** Closed = renewed / ended by status, OR ended by hand ("not renewed", "expired" — these keep status
     *  'expired', so ended_at is what marks them closed). Closed terms are read-only history. */
    function opsh_term_closed(array $r, array $c): bool {
        return in_array($r['status'] ?? '', $c['ended'], true) || !empty($r['ended_at']);
    }

    /** Add ref, term_state, days_left and is_locked to a row. Never exposes secrets. */
    function opsh_term_decorate(array $r, array $c): array {
        $sid = (int)($r['series_id'] ?? 0) ?: (int)($r['id'] ?? 0);
        $r['ref'] = opsh_term_ref($c['prefix'], $sid, $r['term_no'] ?? 1);
        $r['is_locked'] = opsh_term_closed($r, $c) ? 1 : 0;
        $end = $r[$c['end']] ?? null;
        $days = null;
        if ($end) { $days = (int)floor((strtotime($end . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400); }
        $r['days_left'] = $days;
        if ($r['is_locked']) $r['term_state'] = ($r['status'] === $c['renewed_status'] && !empty($r['renewed_to_id'])) ? 'renewed' : 'ended';
        elseif ($days === null) $r['term_state'] = 'open';            // no end date (e.g. credential without a term)
        elseif ($days < 0) $r['term_state'] = 'expired';
        elseif ($days <= ((int)($r['renewal_notice_days'] ?? 0) ?: 90)) $r['term_state'] = 'expiring';   // same default (90 days) as the reminder job
        else $r['term_state'] = 'active';
        return $r;
    }

    /** Contracts: persist active → expiring → expired transitions (idempotent, audited). */
    function opsh_term_sync_contracts(PDO $db): void {
        try {
            $rows = $db->query("SELECT id, title, status, end_date, renewal_notice_days FROM opsh_contracts
                                WHERE status IN ('active','expiring') AND end_date IS NOT NULL")->fetchAll();
        } catch (Throwable $e) { return; }
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $new = null;
            if ($r['end_date'] < $today) $new = 'expired';
            elseif ($r['status'] === 'active' && $r['end_date'] <= date('Y-m-d', strtotime('+' . ((int)$r['renewal_notice_days'] ?: 90) . ' days'))) $new = 'expiring';
            if ($new && $new !== $r['status']) {
                $db->prepare('UPDATE opsh_contracts SET status=? WHERE id=?')->execute([$new, (int)$r['id']]);
                audit_log('term_' . $new, 'contract', (int)$r['id'], $r['title'], ['status' => $r['status']], ['status' => $new]);
            }
        }
    }

    /** All terms of the series that $id belongs to, oldest first, decorated. */
    function opsh_term_series(PDO $db, array $c, int $id): array {
        $st = $db->prepare("SELECT COALESCE(series_id, id) FROM {$c['table']} WHERE id=?"); $st->execute([$id]);
        $sid = (int)$st->fetchColumn();
        if (!$sid) error_response('Record not found', 404);
        $extra = $c['kind'] === 'contract' ? ', total_value, payment_amount, payment_frequency, contract_number, renewal_notice_days' : '';
        $st = $db->prepare("SELECT id, {$c['label']} AS label, status, {$c['start']} AS term_start, {$c['end']} AS term_end,
                                   series_id, term_no, renewed_from_id, renewed_to_id, renewal_note, ended_at, end_reason, created_at $extra
                            FROM {$c['table']} WHERE series_id = :s1 OR (series_id IS NULL AND id = :s2) ORDER BY term_no, id");
        $st->bindValue(':s1', $sid, PDO::PARAM_INT); $st->bindValue(':s2', $sid, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $r[$c['start']] = $r['term_start']; $r[$c['end']] = $r['term_end'];
            $out[] = opsh_term_decorate($r, $c);
        }
        return $out;
    }

    function opsh_term_valid_date($d): bool {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    /** Validate a period; returns [errors[], warnings[]]. */
    function opsh_term_check(?string $start, ?string $end, ?array $prev, array $c): array {
        $err = []; $warn = [];
        if (!opsh_term_valid_date($start)) $err[] = 'A valid start date is required.';
        if (!opsh_term_valid_date($end))   $err[] = 'A valid end date is required.';
        if ($err) return [$err, $warn];
        if ($end <= $start) $err[] = 'The end date must be after the start date.';
        $days = (int)round((strtotime($end) - strtotime($start)) / 86400);
        if ($days > 3660) $err[] = 'A single term cannot be longer than 10 years.';
        elseif ($days > 1830) $warn[] = 'This term is longer than 5 years.';
        if ($prev) {
            $pStart = $prev[$c['start']] ?? null; $pEnd = $prev[$c['end']] ?? null;
            if ($pStart && $start <= $pStart) $err[] = 'The renewed term must start after the previous term started (' . $pStart . ').';
            if ($pEnd && !$err) {
                if ($start <= $pEnd) $warn[] = 'Early renewal: the previous term will now end on ' . date('Y-m-d', strtotime($start . ' -1 day')) . ' (was ' . $pEnd . ').';
                else {
                    $gap = (int)round((strtotime($start) - strtotime($pEnd)) / 86400) - 1;
                    if ($gap > 0) $warn[] = 'Coverage gap: ' . $gap . ' day' . ($gap === 1 ? '' : 's') . ' between the previous term (ended ' . $pEnd . ') and this one.';
                }
            }
        }
        return [$err, $warn];
    }

    /**
     * Renew term $id. $in: start, end, note, plus optional overrides (and, for credentials,
     * plain-text username/password/api_key/mfa_details/notes — blank = carry the old value).
     */
    function opsh_term_renew(PDO $db, array $c, int $id, array $in, array $user): array {
        $st = $db->prepare("SELECT * FROM {$c['table']} WHERE id=?"); $st->execute([$id]);
        $prev = $st->fetch();
        if (!$prev) error_response('Record not found', 404);
        if (opsh_term_closed($prev, $c)) {
            $to = !empty($prev['renewed_to_id']) ? ' → ' . opsh_term_ref($c['prefix'], $prev['series_id'] ?: $prev['id'], (int)($prev['term_no'] ?? 1) + 1) : '';
            error_response('This term is already closed' . $to . '. Renew the current term instead.', 409);
        }
        $note = trim((string)($in['note'] ?? ''));
        if (mb_strlen($note) < 3) error_response('Add a renewal note (what changed, who approved it, PO/quote number…).', 400);
        [$errs, $warn] = opsh_term_check($in['start'] ?? null, $in['end'] ?? null, $prev, $c);
        if ($errs) error_response(implode(' ', $errs), 400);
        $start = $in['start']; $end = $in['end'];

        $new = [];
        foreach ($c['copy'] as $f) if (array_key_exists($f, $prev)) $new[$f] = $prev[$f];
        foreach ($c['overrides'] as $f) if (array_key_exists($f, $in) && $in[$f] !== '' && $in[$f] !== null) $new[$f] = $in[$f];
        if ($c['kind'] === 'credential') {
            foreach (['username','password','api_key','mfa_details','notes'] as $f) {
                if (isset($in[$f]) && $in[$f] !== '') $new[$f . '_enc'] = vault_encrypt((string)$in[$f]);
            }
            $new['last_rotated_at'] = date('Y-m-d');
            $new['next_rotation_date'] = date('Y-m-d', strtotime('+' . max(1, (int)($new['rotation_days'] ?? 90)) . ' days'));
        }
        $sid = (int)($prev['series_id'] ?: $prev['id']);
        $new[$c['start']] = $start; $new[$c['end']] = $end;
        $new['status'] = $c['new_status'];
        $new['series_id'] = $sid;
        $new['term_no'] = (int)($prev['term_no'] ?: 1) + 1;
        $new['renewed_from_id'] = (int)$prev['id'];
        $new['renewal_note'] = mb_substr($note . ($warn ? "\n\n[" . implode(' ', $warn) . ']' : ''), 0, 4000);

        $db->beginTransaction();
        try {
            // Review CORE-14: lock the term and check again, so two renewals at once cannot both pass.
            $lk = $db->prepare("SELECT renewed_to_id, ended_at FROM {$c['table']} WHERE id=? FOR UPDATE"); $lk->execute([(int)$prev['id']]); $cur = $lk->fetch();
            if (!$cur || !empty($cur['renewed_to_id']) || !empty($cur['ended_at'])) { $db->rollBack(); error_response('This term was just renewed or closed by someone else. Reload and renew the current term.', 409); }
            $cols = array_keys($new);
            $db->prepare("INSERT INTO {$c['table']} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
               ->execute(array_values($new));
            $newId = (int)$db->lastInsertId();
            $upd = ['status' => $c['renewed_status'], 'renewed_to_id' => $newId, 'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => 'renewed'];
            if (!empty($prev[$c['end']]) && $start <= $prev[$c['end']]) $upd[$c['end']] = date('Y-m-d', strtotime($start . ' -1 day'));
            $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($upd)));
            $db->prepare("UPDATE {$c['table']} SET $sets WHERE id=?")->execute(array_merge(array_values($upd), [(int)$prev['id']]));
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }

        $newRef = opsh_term_ref($c['prefix'], $sid, $new['term_no']);
        $oldRef = opsh_term_ref($c['prefix'], $sid, $prev['term_no'] ?: 1);
        $safe = fn($a) => array_diff_key($a, array_flip(['username_enc','password_enc','api_key_enc','mfa_details_enc','notes_enc']));
        audit_log('renewed', $c['entity'], (int)$prev['id'], $prev[$c['label']] . " ($oldRef → $newRef)",
                  $safe(['status' => $prev['status'], $c['end'] => $prev[$c['end']] ?? null]), $safe($upd));
        audit_log('created', $c['entity'], $newId, $new[$c['label']] . " ($newRef, renewal of $oldRef)", null,
                  $safe(array_merge($new, ['renewal_note' => $note])));
        if (function_exists('opsh_emit')) opsh_emit($c['entity'] . '.renewed', ['from_id' => (int)$prev['id'], 'to_id' => $newId, 'ref' => $newRef]);
        return ['ok' => true, 'id' => $newId, 'ref' => $newRef, 'previous_ref' => $oldRef, 'warnings' => $warn];
    }

    /** Close a term without renewing it. */
    function opsh_term_end(PDO $db, array $c, int $id, array $in): array {
        $st = $db->prepare("SELECT * FROM {$c['table']} WHERE id=?"); $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) error_response('Record not found', 404);
        if (opsh_term_closed($row, $c)) error_response('This term is already closed.', 409);
        $reason = (string)($in['reason'] ?? 'not_renewed');
        if (!isset($c['end_status'][$reason])) error_response('Unknown reason', 400);
        $note = trim((string)($in['note'] ?? ''));
        if (mb_strlen($note) < 3) error_response('Add a short note explaining why this term is ending.', 400);
        $upd = ['status' => $c['end_status'][$reason], 'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => $reason,
                'renewal_note' => mb_substr(trim(($row['renewal_note'] ? $row['renewal_note'] . "\n\n" : '') . 'Closed (' . str_replace('_', ' ', $reason) . '): ' . $note), 0, 4000)];
        if (!empty($in['end_date']) && opsh_term_valid_date($in['end_date'])) $upd[$c['end']] = $in['end_date'];
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($upd)));
        $db->prepare("UPDATE {$c['table']} SET $sets WHERE id=?")->execute(array_merge(array_values($upd), [$id]));
        audit_log('term_closed', $c['entity'], $id, $row[$c['label']] . ' — ' . $reason, ['status' => $row['status']], $upd);
        return ['ok' => true];
    }
}
