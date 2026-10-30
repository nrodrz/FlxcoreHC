<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — Phase 7 part 2 (BT84): the continual improvement register (continual improvement).
 *
 * Anyone can suggest an improvement. The people who triage — Super Admins and everyone who may create cases (Cases &
 * Projects · Create, or named in Settings → General) — accept it (priority, owner, target date, how we will know it
 * worked) or decline it with a reason. The owner carries it out and closes it with the result ("Did we get there?").
 * A major incident review, a change that failed or had issues, and a problem investigation can add one directly
 * (accepted at once when a triager adds it). Agreed with the user on 10/15/2026.
 *
 * Statuses: new → accepted → in_progress → done; new / accepted / in_progress → declined.
 */
if (!function_exists('opsh_imp_has')) {
    function opsh_imp_has(PDO $db): bool {
        static $h = null; if ($h !== null) return $h;
        try { $db->query('SELECT 1 FROM opsh_improvements LIMIT 0'); $h = true; } catch (Throwable $e) { $h = false; }
        return $h;
    }
    function opsh_imp_labels(): array {
        return [
            'status' => ['new' => 'New', 'accepted' => 'Accepted', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined'],
            'priority' => ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'],
            'effort' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
            'source' => ['suggestion' => 'Suggestion', 'major_incident' => 'Major incident review', 'change' => 'Change', 'problem' => 'Problem investigation', 'case' => 'Case or project', 'review' => 'Weekly review'],
            'result' => ['worked' => 'It worked', 'partly' => 'It partly worked', 'not' => 'It did not work'],
        ];
    }
    /** Super Admins and the people who may create cases triage the register. */
    function opsh_imp_triager(PDO $db, array $user): bool {
        if (($user['role_slug'] ?? '') === 'super_admin') return true;
        if (!function_exists('opsh_cs_can')) require_once __DIR__ . '/Cases.php';
        return opsh_cs_can($db, $user, 'create');
    }
    /** Everyone who triages (active accounts), for the bell on a new suggestion. */
    function opsh_imp_triagers(PDO $db): array {
        $out = [];
        $q = $db->query("SELECT u.id, u.display_name, u.email, r.slug AS role_slug, r.permissions AS role_perms FROM opsh_users u LEFT JOIN opsh_roles r ON r.id=u.role_id WHERE u.status='active'");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
            try { if (opsh_imp_triager($db, $u)) $out[] = (int)$u['id']; } catch (Throwable $e) {}
        }
        return $out;
    }
    function opsh_imp_get(PDO $db, int $id): ?array {
        $q = $db->prepare('SELECT i.*, s.display_name AS suggested_by_name, o.display_name AS owner_name, d.display_name AS decided_by_name, c.case_number
                           FROM opsh_improvements i LEFT JOIN opsh_users s ON s.id=i.suggested_by LEFT JOIN opsh_users o ON o.id=i.owner_id
                           LEFT JOIN opsh_users d ON d.id=i.decided_by LEFT JOIN opsh_cases c ON c.id=i.case_id WHERE i.id=?');
        $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
    function opsh_imp_audit(string $action, array $imp, $old = null, $new = null): void {
        if (function_exists('audit_log')) { try { audit_log($action, 'improvement', (int)$imp['id'], mb_substr($imp['number'] . ' · ' . $imp['title'], 0, 250), $old, $new); } catch (Throwable $e) {} }
    }
    function opsh_imp_bell(PDO $db, $uid, string $title, string $body): void {
        if (!$uid) return;
        try { $db->prepare('INSERT INTO opsh_notifications (user_id,title,body,type,link) VALUES (?,?,?,?,?)')->execute([(int)$uid, $title, mb_substr($body, 0, 500), 'improvement', 'improvements']); } catch (Throwable $e) {}
    }
    /** IM-YYYY-NNNN, never given twice. */
    function opsh_imp_create(PDO $db, array $f): int {
        $year = date('Y'); $key = 'improvement_seq_' . $year;
        $issued = 0; try { $st = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $st->execute([$key]); $issued = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        $last = (int)$db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(number,9) AS UNSIGNED)),0) FROM opsh_improvements WHERE number LIKE 'IM-{$year}-%'")->fetchColumn();
        $n = max($issued, $last) + 1;
        $cols = array_keys($f);
        for ($try = 0; $try < 12; $try++, $n++) {
            $num = sprintf('IM-%s-%04d', $year, $n);
            try {
                $db->prepare('INSERT INTO opsh_improvements (number, ' . implode(', ', $cols) . ', created_at, updated_at) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW(), NOW())')
                   ->execute(array_merge([$num], array_values($f)));
            } catch (PDOException $e) { if ((string)$e->getCode() === '23000' && stripos($e->getMessage(), 'number') !== false) continue; throw $e; }
            $id = (int)$db->lastInsertId();
            try { $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=IF(CAST(sval AS UNSIGNED) < CAST(VALUES(sval) AS UNSIGNED), VALUES(sval), sval)")->execute([$key, (string)$n]); } catch (Throwable $e) {}
            return $id;
        }
        throw new RuntimeException('Could not give the improvement a number. Try again.');
    }
    /** What one person may see of an item: the whole record (triagers, the owner), or the public view (the register). */
    function opsh_imp_view(array $r, bool $full): array {
        $out = ['id' => (int)$r['id'], 'number' => $r['number'], 'title' => $r['title'], 'description' => $r['description'], 'benefit' => $r['benefit'], 'status' => $r['status'],
                'priority' => $r['priority'], 'owner_id' => $r['owner_id'] !== null ? (int)$r['owner_id'] : null, 'owner_name' => $r['owner_name'], 'target_date' => $r['target_date'],
                'source_type' => $r['source_type'], 'source_label' => $r['source_label'], 'suggested_by' => $r['suggested_by'] !== null ? (int)$r['suggested_by'] : null, 'suggested_by_name' => $r['suggested_by_name'],
                'created_at' => $r['created_at'], 'done_at' => $r['done_at'], 'result' => $r['status'] === 'done' ? $r['result'] : null, 'result_note' => $r['status'] === 'done' ? $r['result_note'] : null,
                'decision_note' => in_array($r['status'], ['declined'], true) ? $r['decision_note'] : null];
        if ($full) $out += ['effort' => $r['effort'], 'measure' => $r['measure'], 'progress' => $r['progress'], 'decision_note' => $r['decision_note'], 'decided_by_name' => $r['decided_by_name'],
                            'decided_at' => $r['decided_at'], 'source_id' => $r['source_id'] !== null ? (int)$r['source_id'] : null, 'case_id' => $r['case_id'] !== null ? (int)$r['case_id'] : null,
                            'case_number' => $r['case_number'], 'updated_at' => $r['updated_at']];
        return $out;
    }
    /** For Reports and the Scorecard: open items and items done in [$s, $e). */
    function opsh_imp_stats(PDO $db, string $s, string $e): ?array {
        if (!opsh_imp_has($db)) return null;
        $r = $db->query("SELECT SUM(status='new') nw, SUM(status IN ('accepted','in_progress')) open_, SUM(status IN ('accepted','in_progress') AND target_date < CURDATE()) late FROM opsh_improvements")->fetch(PDO::FETCH_ASSOC);
        $q = $db->prepare("SELECT COUNT(*) n, SUM(result='worked') worked, SUM(source_type='suggestion') staff FROM opsh_improvements WHERE status='done' AND done_at >= ? AND done_at < ?"); $q->execute([$s, $e]); $d = $q->fetch(PDO::FETCH_ASSOC);
        return ['new' => (int)$r['nw'], 'open' => (int)$r['open_'], 'late' => (int)$r['late'], 'done' => (int)$d['n'], 'worked' => (int)$d['worked'], 'from_staff' => (int)$d['staff']];
    }
}
