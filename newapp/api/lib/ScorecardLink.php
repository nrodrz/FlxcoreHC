<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — putting an automatic measure on the Scorecard (BT62).
 *
 * Used by Uptime and Backups: "Add to the Scorecard" makes a new weekly row, or connects a row that is already there
 * (for example the default "System uptime" or "Backup success rate" rows, which are typed by hand), and fills the last
 * completed weeks straight away. A value someone typed in a week is never overwritten here.
 */
if (!function_exists('opsh_sc_weeks')) {
    /** The last $n completed weeks, newest first: [period_end (Sunday), from (Monday 00:00), to (next Monday 00:00)]. */
    function opsh_sc_weeks(int $n = 13): array {
        $d = new DateTime('today'); $d->modify('-' . ((int)$d->format('N') % 7) . ' days');
        if ($d->format('Y-m-d') === date('Y-m-d')) $d->modify('-7 days');   // today is Sunday: that week is not over yet
        $out = [];
        for ($w = 0; $w < $n; $w++) {
            $out[] = [$d->format('Y-m-d'), (clone $d)->modify('-6 days')->format('Y-m-d 00:00:00'), (clone $d)->modify('+1 day')->format('Y-m-d 00:00:00')];
            $d->modify('-7 days');
        }
        return $out;
    }
    /** Fill the past weeks of measurable $mid from $value(from, to). Weeks with a typed (manual) value are left alone. */
    function opsh_sc_fill(PDO $db, int $mid, callable $value, int $weeks = 13): int {
        $man = $db->prepare('SELECT COUNT(*) FROM opsh_scorecard_entries WHERE measurable_id=? AND period_end=? AND is_auto=0');
        $up = $db->prepare('INSERT INTO opsh_scorecard_entries (measurable_id, period_end, value, is_auto) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE value=IF(is_auto=1, VALUES(value), value)');
        $n = 0;
        foreach (opsh_sc_weeks($weeks) as [$pe, $from, $to]) {
            $man->execute([$mid, $pe]); if ((int)$man->fetchColumn() > 0) continue;
            $v = $value($from, $to); if ($v === null) continue;
            $up->execute([$mid, $pe, $v]); $n++;
        }
        return $n;
    }
    /** BT92: may this person see this Scorecard row? (the same rule as the Scorecard page: everyone, or the roles and people chosen, its owner, Scorecard · Edit) */
    function opsh_sc_row_visible(array $m, array $user): bool {
        $v = $m['visible_to'] ?? null; if ($v === null || $v === '') return true;
        if (function_exists('opsh_can') && opsh_can($user, 'scorecard', 'edit')) return true;
        if (!empty($m['owner_id']) && (int)$m['owner_id'] === (int)($user['id'] ?? 0)) return true;
        $j = json_decode((string)$v, true); if (!is_array($j)) return true;
        return in_array((int)($user['role_id'] ?? 0), array_map('intval', (array)($j['roles'] ?? [])), true) || in_array((int)($user['id'] ?? 0), array_map('intval', (array)($j['users'] ?? [])), true);
    }
    /** Measurables that can take an automatic measure: the hand-typed ones (and the ones already on one of $prefixes). */
    function opsh_sc_linkable(PDO $db, array $prefixes, ?array $user = null): array {
        try { $rows = $db->query("SELECT id, name, source, visible_to, owner_id FROM opsh_scorecard_measurables WHERE active=1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
        if ($user !== null) $rows = array_filter($rows, fn($r) => opsh_sc_row_visible($r, $user));   // BT92: only the rows the person sees
        return array_values(array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'source' => $r['source']],
            array_filter($rows, function ($r) use ($prefixes) { if ($r['source'] === 'manual' || $r['source'] === '') return true; foreach ($prefixes as $p) if (strpos($r['source'], $p) === 0) return true; return false; })));
    }
    /**
     * Add a new row, or connect an existing one ($o['measurable_id']), to $source. $label is the source's name, $value the
     * week function. Returns ['id', 'filled', 'linked']. Throws InvalidArgumentException (400) / RuntimeException (409).
     */
    function opsh_sc_put(PDO $db, array $user, array $o, string $source, string $label, callable $value, string $unit, string $op, float $goal): array {
        $mid = (int)($o['measurable_id'] ?? 0);
        if ($mid > 0) {
            $s = $db->prepare('SELECT * FROM opsh_scorecard_measurables WHERE id=? AND active=1'); $s->execute([$mid]); $m = $s->fetch(PDO::FETCH_ASSOC);
            if (!$m || !opsh_sc_row_visible($m, $user)) throw new InvalidArgumentException('That Scorecard row is not there any more.');   // BT92: a row hidden from the person is not theirs to re-point
            // BT92 (code audit M-6): connecting an existing row changes what it measures — that is Scorecard · Edit, not Create
            if (!opsh_can($user, 'scorecard', 'edit')) {
                audit_log('scorecard_refused', 'scorecard', $mid, (string)$m['name'], null, ['Wanted' => 'Make "' . $m['name'] . '" measure ' . $label, 'Needs' => 'Scorecard · Edit']);
                error_response('Connecting an existing Scorecard row changes what it measures: it needs Scorecard · Edit. Add a new row instead.', 403);
            }
            $ex = $db->prepare('SELECT id FROM opsh_scorecard_measurables WHERE source=? AND active=1 AND id<>?'); $ex->execute([$source, $mid]);
            if ($ex->fetchColumn()) throw new RuntimeException('Another Scorecard row already measures that.');
            $keepGoal = !array_key_exists('goal', $o) || $o['goal'] === null || $o['goal'] === '';
            $newGoal = $keepGoal ? (float)$m['goal_value'] : $goal; $newOp = $keepGoal ? $m['goal_op'] : $op; $newUnit = $m['unit'] !== '' ? $m['unit'] : $unit;
            $db->prepare('UPDATE opsh_scorecard_measurables SET source=?, goal_op=?, goal_value=?, unit=? WHERE id=?')->execute([$source, $newOp, $newGoal, $newUnit, $mid]);
            $filled = opsh_sc_fill($db, $mid, $value);
            $sym = fn($op) => in_array($op, ['gte', 'gt'], true) ? '≥ ' : (in_array($op, ['lte', 'lt'], true) ? '≤ ' : '= ');
            audit_log('scorecard_measurable_save', 'scorecard', $mid, $m['name'],
                ['Measures' => $m['source'] === 'manual' ? 'Typed by hand' : $m['source'], 'Goal' => $sym($m['goal_op']) . (float)$m['goal_value'] . $m['unit']],
                ['Measures' => $label, 'Goal' => $sym($newOp) . $newGoal . $newUnit, 'Weeks filled' => (string)$filled]);
            return ['id' => $mid, 'filled' => $filled, 'linked' => true];
        }
        $ex = $db->prepare('SELECT id FROM opsh_scorecard_measurables WHERE source=? AND active=1'); $ex->execute([$source]);
        if ($ex->fetchColumn()) throw new RuntimeException('The Scorecard already measures that.');
        $name = trim((string)($o['name'] ?? '')) ?: preg_replace('/ \((%|count|minutes|ms)\)$/', '', $label);
        $sort = (int)$db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM opsh_scorecard_measurables')->fetchColumn();
        $db->prepare('INSERT INTO opsh_scorecard_measurables (name, goal_op, goal_value, unit, source, owner_id, sort_order) VALUES (?,?,?,?,?,?,?)')
           ->execute([mb_substr($name, 0, 160), $op, $goal, $unit, $source, (int)$user['id'], $sort]);
        $mid = (int)$db->lastInsertId();
        $filled = opsh_sc_fill($db, $mid, $value);
        audit_log('scorecard_measurable_add', 'scorecard', $mid, $name, null, ['Name' => $name, 'Measures' => $label, 'Goal' => ($op === 'gte' ? '≥ ' : '≤ ') . $goal . $unit, 'Weeks filled' => (string)$filled]);
        return ['id' => $mid, 'filled' => $filled, 'linked' => false];
    }
}
