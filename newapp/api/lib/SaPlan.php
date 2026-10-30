<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT117: security baselines, build 4b. The tenant plan and the client's decisions.
 *
 * A plan is built from the latest assessment run: the rules of the chosen baseline that failed or warned (and that the
 * tenant is licensed for), grouped in four stages (Prepare, Report-only, Enforce, Tighten) and ordered so that what a
 * rule depends on comes first, then by severity, then by effort. It is built by rules, never by the AI: the same scan
 * gives the same plan.
 *
 * Each item gets a decision: do it (owner and date), make it a task (a ticket in the service desk), accept the risk
 * (reason, expiry), not for us (reason), later. Decisions carry over to the next version; an accepted risk whose expiry
 * has passed comes back undecided. A draft is signed off by a named client contact; a signed plan is kept as it was, and
 * a new version starts from it. Every build, decision, task, sign-off and refusal is in the Audit Log.
 *
 * Tables: itops_sa_plans, itops_sa_plan_items (migration 2026_12_18_01_bt117_sa_plans).
 */
require_once __DIR__ . '/SecAssess.php';

if (!function_exists('itops_sp_ready')) {

    function itops_sp_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_sa_plan_items LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

    function itops_sp_stages(): array {
        return [1 => ['Prepare', 'Get ready without changing how anyone signs in: emergency accounts, registration, clean-up, inventory.'],
                2 => ['Report-only', 'Create the policies and settings in a watching or pilot mode first, and look at what they would do.'],
                3 => ['Enforce', 'Turn on what the report-only stage showed is safe.'],
                4 => ['Tighten', 'The strongest settings, once the rest has held for a few weeks.']];
    }
    function itops_sp_decisions(): array {
        return ['undecided' => 'Not decided', 'do' => 'Do it', 'task' => 'Make it a task', 'accept' => 'Accept the risk', 'na' => 'Not for us', 'later' => 'Later'];
    }
    /** The stage of a rule. Strict-only rules are Tighten; the rest by what they are. */
    function itops_sp_stage_of(string $id, string $level): int {
        static $map = [
            'break_glass' => 1, 'mfa_registered' => 1, 'admin_mfa_registered' => 1, 'passkey_enabled' => 1, 'admin_consent_workflow' => 1, 'stale_users' => 1,
            'stale_guests' => 1, 'ga_count' => 1, 'admin_separate' => 1, 'admins_cloud_only' => 1, 'app_credentials' => 1, 'spf' => 1, 'dkim' => 1,
            'compliance_policy_platforms' => 1, 'windows_supported' => 1, 'devices_checkin' => 1, 'mailbox_audit' => 1,
            'mfa_required' => 2, 'legacy_auth' => 2, 'admin_mfa_policy' => 2, 'device_code_blocked' => 2, 'admin_phish_resistant' => 2, 'compliant_device_admins' => 2,
            'dmarc' => 2, 'asr_assigned' => 2, 'baseline_assigned' => 2, 'laps_backed_up' => 2, 'bitlocker_escrow' => 2, 'update_rings' => 2, 'firewall_assigned' => 2,
            'antivirus_assigned' => 2, 'edr_assigned' => 2, 'app_protection' => 2, 'devices_encrypted' => 2,
            'user_consent' => 3, 'users_create_apps' => 3, 'guest_invites' => 3, 'users_create_tenants' => 3, 'guest_access_restricted' => 3, 'email_verified_join' => 3,
            'self_service_signup' => 3, 'weak_methods' => 3, 'secure_by_default' => 3, 'ca_report_only' => 3, 'exo_modern_auth' => 3, 'spo_anyone_links' => 3,
            'app_password_block' => 3, 'devices_compliant' => 3, 'app_long_secrets' => 3,
        ];
        if ($level === 'strict') return 4;
        return $map[$id] ?? 4;
    }
    /** Order within a stage: a rule after what it depends on (inside the plan), then severity, then effort. */
    function itops_sp_order(array $items): array {
        $sev = ['high' => 0, 'medium' => 1, 'low' => 2]; $eff = ['low' => 0, 'medium' => 1, 'high' => 2];
        // what more of the plan waits on comes first (the emergency accounts before everything that needs them)
        $waits = []; foreach ($items as $it) foreach ($it['deps'] as $d) $waits[$d] = ($waits[$d] ?? 0) + 1;
        $key = fn($x) => [$x['stage'], -($waits[$x['rule_id']] ?? 0), $sev[$x['severity']] ?? 3, $eff[$x['effort']] ?? 3, $x['rule_id']];
        usort($items, fn($a, $b) => $key($a) <=> $key($b));
        $in = array_flip(array_column($items, 'rule_id')); $out = []; $placed = [];
        $place = function ($it) use (&$place, &$out, &$placed, $items, $in) {
            if (isset($placed[$it['rule_id']])) return; $placed[$it['rule_id']] = true;
            foreach ($it['deps'] as $d) if (isset($in[$d])) foreach ($items as $x) if ($x['rule_id'] === $d && $x['stage'] <= $it['stage']) $place($x);
            $out[] = $it;
        };
        foreach ($items as $it) $place($it);
        // a dependency in a later stage cannot be pulled forward: the stage stays, the order inside stages is what counts
        usort($out, fn($a, $b) => $a['stage'] <=> $b['stage']);
        return $out;
    }

    function itops_sp_plan_row(PDO $db, int $id): ?array {
        $s = $db->prepare('SELECT p.*, u.display_name AS created_by_name, s.display_name AS signed_by_name, r.started_at AS run_at FROM itops_sa_plans p LEFT JOIN itops_users u ON u.id=p.created_by LEFT JOIN itops_users s ON s.id=p.signed_by LEFT JOIN itops_sa_runs r ON r.id=p.run_id WHERE p.id=?');
        $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
    /** The draft, or else the latest signed plan, or null. */
    function itops_sp_current_id(PDO $db): ?int {
        $d = $db->query("SELECT id FROM itops_sa_plans WHERE status='draft' ORDER BY id DESC LIMIT 1")->fetchColumn(); if ($d) return (int)$d;
        $s = $db->query("SELECT id FROM itops_sa_plans WHERE status='signed' ORDER BY id DESC LIMIT 1")->fetchColumn(); return $s ? (int)$s : null;
    }

    /**
     * Build (or rebuild) the draft from the latest run. Decisions are carried from the draft being replaced, else from the
     * latest signed plan. Returns ['id', 'version', 'items', 'carried', 'expired', 'left_out' => [unlicensed, unread]].
     */
    function itops_sp_build(PDO $db, int $userId): array {
        $run = (int)$db->query('SELECT MAX(id) FROM itops_sa_runs')->fetchColumn();
        if (!$run) throw new RuntimeException('Run the assessment first: the plan is built from its latest run.');
        $baseline = itops_sa_baseline_cur($db);
        $checks = itops_sa_results($db, $run, $baseline);
        $draft = $db->query("SELECT id, version FROM itops_sa_plans WHERE status='draft' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $from = $draft ? (int)$draft['id'] : (int)$db->query("SELECT MAX(id) FROM itops_sa_plans WHERE status='signed'")->fetchColumn();
        $prev = [];
        if ($from) { $q = $db->prepare('SELECT * FROM itops_sa_plan_items WHERE plan_id=?'); $q->execute([$from]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $prev[$x['rule_id']] = $x; }
        $items = []; $left = ['unlicensed' => 0, 'unread' => 0];
        foreach ($checks as $c) {
            if (!$c['in_baseline']) continue;
            if ($c['unlicensed']) { if (in_array($c['status'], ['fail', 'warn'], true)) $left['unlicensed']++; continue; }
            if ($c['status'] === 'unknown') { $left['unread']++; continue; }
            if (!in_array($c['status'], ['fail', 'warn'], true)) continue;
            $items[] = ['rule_id' => $c['id'], 'stage' => itops_sp_stage_of($c['id'], $c['level']), 'severity' => $c['severity'], 'effort' => $c['effort'], 'deps' => array_column($c['deps'], 0),
                        'found_status' => $c['status'], 'found_detail' => mb_substr((string)$c['detail'], 0, 1000), 'affected_count' => (int)$c['affected_count']];
        }
        $items = itops_sp_order($items);
        $db->beginTransaction();
        try {
            if ($draft) {
                $pid = (int)$draft['id']; $ver = (int)$draft['version'];
                $db->prepare('DELETE FROM itops_sa_plan_items WHERE plan_id=?')->execute([$pid]);
                $db->prepare('UPDATE itops_sa_plans SET run_id=?, baseline=?, updated_at=NOW() WHERE id=?')->execute([$run, $baseline, $pid]);
            } else {
                $ver = (int)$db->query('SELECT COALESCE(MAX(version),0)+1 FROM itops_sa_plans')->fetchColumn();
                $db->prepare("INSERT INTO itops_sa_plans (version, status, baseline, run_id, created_by, created_at, updated_at) VALUES (?,'draft',?,?,?,NOW(),NOW())")->execute([$ver, $baseline, $run, $userId]);
                $pid = (int)$db->lastInsertId();
            }
            $ins = $db->prepare('INSERT INTO itops_sa_plan_items (plan_id, rule_id, stage, seq, found_status, found_detail, affected_count, decision, note, owner_id, due_date, expires_on, ticket_id, decided_by, decided_at)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $carried = 0; $expired = 0; $seq = 0;
            foreach ($items as $it) {
                $p = $prev[$it['rule_id']] ?? null; $dec = 'undecided'; $note = null; $owner = null; $due = null; $exp = null; $tid = null; $by = null; $at = null;
                if ($p && $p['decision'] !== 'undecided') {
                    $dec = $p['decision']; $note = $p['note']; $owner = $p['owner_id']; $due = $p['due_date']; $exp = $p['expires_on']; $tid = $p['ticket_id']; $by = $p['decided_by']; $at = $p['decided_at']; $carried++;
                    if ($dec === 'accept' && $exp && strtotime($exp) < strtotime(date('Y-m-d'))) { $dec = 'undecided'; $note = 'The accepted risk expired on ' . date('m/d/Y', strtotime($exp)) . ': decide again. (Reason given then: ' . mb_substr((string)$p['note'], 0, 300) . ')'; $exp = null; $expired++; $carried--; }
                }
                $ins->execute([$pid, $it['rule_id'], $it['stage'], ++$seq, $it['found_status'], $it['found_detail'], $it['affected_count'], $dec, $note, $owner, $due, $exp, $tid, $by, $at]);
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        return ['id' => $pid, 'version' => $ver, 'items' => count($items), 'carried' => $carried, 'expired' => $expired, 'left_out' => $left, 'rebuilt' => (bool)$draft];
    }

    /** A plan with its items, each with its rule and where the rule stands in the latest run. */
    function itops_sp_view(PDO $db, int $planId): ?array {
        $p = itops_sp_plan_row($db, $planId); if (!$p) return null;
        $cat = itops_sa_catalog(); $meta = itops_sa_meta(); $areas = itops_sa_areas(); $lic = itops_sa_licenses(); $D = itops_sp_decisions(); $S = itops_sp_stages();
        $latest = (int)$db->query('SELECT MAX(id) FROM itops_sa_runs')->fetchColumn(); $now = [];
        if ($latest) { $q = $db->prepare('SELECT check_id, status FROM itops_sa_results WHERE run_id=?'); $q->execute([$latest]); $now = $q->fetchAll(PDO::FETCH_KEY_PAIR); }
        $q = $db->prepare('SELECT i.*, o.display_name AS owner_name, d.display_name AS decided_by_name, t.ticket_number, t.status AS ticket_status FROM itops_sa_plan_items i
                           LEFT JOIN itops_users o ON o.id=i.owner_id LEFT JOIN itops_users d ON d.id=i.decided_by LEFT JOIN itops_tickets t ON t.id=i.ticket_id WHERE i.plan_id=? ORDER BY i.stage, i.seq');
        $q->execute([$planId]); $items = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $id = $x['rule_id']; $c = $cat[$id] ?? null; $m = $meta[$id] ?? ['strict', 'none', 'medium', 'medium', []];
            $st = $now[$id] ?? null;
            $items[] = ['id' => (int)$x['id'], 'rule_id' => $id, 'title' => $c ? $c[1] : $id, 'area' => $c ? ($areas[$c[0]] ?? $c[0]) : '', 'severity' => $c ? $c[2] : 'medium',
                'why_it_matters' => $c ? $c[5] : '', 'fix' => $c ? $c[6] : [], 'stage' => (int)$x['stage'], 'stage_label' => $S[(int)$x['stage']][0] ?? '',
                'effort' => $m[2], 'impact' => $m[3], 'lic_label' => $lic[$m[1]][0] ?? $m[1], 'deps' => array_values(array_map(fn($d) => [$d, $cat[$d][1] ?? $d], array_filter($m[4], fn($d) => isset($cat[$d])))),
                'found_status' => $x['found_status'], 'found_detail' => $x['found_detail'], 'affected_count' => (int)$x['affected_count'],
                'now' => $st, 'fixed' => $st === 'pass' || $st === 'na',
                'decision' => $x['decision'], 'decision_label' => $D[$x['decision']] ?? $x['decision'], 'note' => $x['note'], 'owner_id' => $x['owner_id'] ? (int)$x['owner_id'] : null, 'owner_name' => $x['owner_name'],
                'due_date' => $x['due_date'], 'expires_on' => $x['expires_on'], 'expired' => $x['decision'] === 'accept' && $x['expires_on'] && $x['expires_on'] < date('Y-m-d'), 'ticket_id' => $x['ticket_id'] ? (int)$x['ticket_id'] : null, 'ticket_number' => $x['ticket_number'], 'ticket_status' => $x['ticket_status'],
                'decided_by' => $x['decided_by_name'], 'decided_at' => $x['decided_at']];
        }
        $counts = array_fill_keys(array_keys($D), 0); foreach ($items as $i) $counts[$i['decision']]++;
        $soon = count(array_filter($items, fn($i) => $i['decision'] === 'accept' && $i['expires_on'] && strtotime($i['expires_on']) < time() + 30 * 86400));
        return ['plan' => ['id' => (int)$p['id'], 'version' => (int)$p['version'], 'status' => $p['status'], 'baseline' => $p['baseline'], 'run_id' => (int)$p['run_id'], 'run_at' => $p['run_at'],
                           'created_by' => $p['created_by_name'], 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
                           'signed_name' => $p['signed_name'], 'signed_title' => $p['signed_title'], 'signed_note' => $p['signed_note'], 'signed_at' => $p['signed_at'], 'signed_by' => $p['signed_by_name']],
                'items' => $items, 'counts' => $counts, 'fixed' => count(array_filter($items, fn($i) => $i['fixed'])), 'expiring' => $soon, 'latest_run' => $latest];
    }

    /** Validate and record a decision on a draft item. $f: decision, note, owner_id, due_date, expires_on, category_id. Throws InvalidArgumentException. */
    function itops_sp_decide(PDO $db, int $itemId, array $f, array $user): array {
        $q = $db->prepare('SELECT i.*, p.status AS plan_status, p.version FROM itops_sa_plan_items i JOIN itops_sa_plans p ON p.id=i.plan_id WHERE i.id=?'); $q->execute([$itemId]); $it = $q->fetch(PDO::FETCH_ASSOC);
        if (!$it) throw new InvalidArgumentException('No such plan item.');
        if ($it['plan_status'] !== 'draft') throw new InvalidArgumentException('This plan is signed. Start a new version to change decisions.');
        $dec = (string)($f['decision'] ?? ''); if (!isset(itops_sp_decisions()[$dec])) throw new InvalidArgumentException('Unknown decision.');
        $note = trim((string)($f['note'] ?? '')); $owner = (int)($f['owner_id'] ?? 0) ?: null; $due = trim((string)($f['due_date'] ?? '')) ?: null; $exp = trim((string)($f['expires_on'] ?? '')) ?: null;
        foreach (['due date' => $due, 'expiry date' => $exp] as $l => $v) if ($v !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v))) throw new InvalidArgumentException('The ' . $l . ' is not a date.');
        if ($owner) { $o = $db->prepare("SELECT COUNT(*) FROM itops_users WHERE id=? AND status='active'"); $o->execute([$owner]); if (!$o->fetchColumn()) throw new InvalidArgumentException('The owner is not an active user.'); }
        if ($dec === 'accept') {
            if (mb_strlen($note) < 5) throw new InvalidArgumentException('Say why the risk is accepted.');
            if (!$exp) throw new InvalidArgumentException('An accepted risk needs an expiry date (at most a year away).');
            if (strtotime($exp) <= strtotime(date('Y-m-d')) || strtotime($exp) > strtotime('+366 days')) throw new InvalidArgumentException('The expiry date must be after today and at most a year away.');
            if (!$owner) throw new InvalidArgumentException('An accepted risk needs an owner who reviews it when it expires.');
        }
        if ($dec === 'na' && mb_strlen($note) < 5) throw new InvalidArgumentException('Say why this does not apply here.');
        if ($dec !== 'accept') $exp = null;
        // Review SEC-30: the item is locked while its ticket is made and linked, so two clicks make one ticket
        $ownTx = !$db->inTransaction(); if ($ownTx) $db->beginTransaction();
        try {
        $lk = $db->prepare('SELECT ticket_id FROM itops_sa_plan_items WHERE id=? FOR UPDATE'); $lk->execute([$itemId]); $it['ticket_id'] = $lk->fetchColumn() ?: null;
        $tid = $it['ticket_id'] ? (int)$it['ticket_id'] : null; $tn = null;
        if ($dec === 'task' && !$tid) {
            $catId = (int)($f['category_id'] ?? 0);
            $c = $db->prepare("SELECT COUNT(*) FROM itops_ticket_categories WHERE id=? AND (status IS NULL OR status='active')"); $c->execute([$catId]);
            if (!$catId || !$c->fetchColumn()) throw new InvalidArgumentException('Choose the ticket category for the task.');
            [$tn, $tid] = itops_sp_ticket($db, $it, $catId, $owner, $due, $note, $user);
        }
        $old = ['decision' => $it['decision'], 'note' => $it['note'], 'owner_id' => $it['owner_id'], 'due_date' => $it['due_date'], 'expires_on' => $it['expires_on']];
        $db->prepare('UPDATE itops_sa_plan_items SET decision=?, note=?, owner_id=?, due_date=?, expires_on=?, ticket_id=?, decided_by=?, decided_at=NOW() WHERE id=?')
           ->execute([$dec, $note !== '' ? mb_substr($note, 0, 1000) : null, $owner, $due, $exp, $tid, (int)$user['id'], $itemId]);
        $db->prepare('UPDATE itops_sa_plans SET updated_at=NOW() WHERE id=?')->execute([(int)$it['plan_id']]);
        if ($ownTx) $db->commit();
        } catch (Throwable $e) { if ($ownTx && $db->inTransaction()) $db->rollBack(); throw $e; }
        return ['old' => $old, 'new' => ['decision' => $dec, 'note' => $note, 'owner_id' => $owner, 'due_date' => $due, 'expires_on' => $exp], 'rule_id' => $it['rule_id'], 'ticket_number' => $tn, 'ticket_id' => $tid, 'version' => (int)$it['version']];
    }

    /** "Make it a task": a ticket in the service desk with the rule, what was found and the steps. */
    function itops_sp_ticket(PDO $db, array $it, int $catId, ?int $owner, ?string $due, string $note, array $user): array {
        require_once __DIR__ . '/TicketNumbers.php';
        $c = itops_sa_catalog()[$it['rule_id']] ?? null; $title = $c ? $c[1] : $it['rule_id'];
        $desc = 'Security plan (Microsoft 365 → Posture), version ' . (int)$it['version'] . ": " . $title . "\n\nWhat was found: " . $it['found_detail']
              . ($c ? "\n\nWhy it matters: " . $c[5] . "\n\nHow to fix it:\n" . implode("\n", array_map(fn($s, $i) => ($i + 1) . '. ' . $s, $c[6], array_keys($c[6]))) : '')
              . ($due ? "\n\nWanted by " . date('m/d/Y', strtotime($due)) . '.' : '') . ($note !== '' ? "\n\nNote: " . $note : '');
        $subj = mb_substr('Security plan: ' . $title, 0, 250); $req = (int)$user['id'];
        $pri = ($c && $c[2] === 'high') ? 'p2_high' : 'p3_medium';
        [$tn, $tid] = itops_ticket_create_numbered($db, function (string $tn) use ($db, $subj, $desc, $catId, $pri, $req) {
            $db->prepare("INSERT INTO itops_tickets (ticket_number,subject,description,category_id,priority,status,ticket_type,requester_id) VALUES (?,?,?,?,?,'open','request',?)")
               ->execute([$tn, $subj, $desc, $catId, $pri, $req]);
            return [$tn, (int)$db->lastInsertId()];
        });
        if (!function_exists('itops_ticket_sla_fill')) require_once __DIR__ . '/BusinessHours.php';
        try { itops_ticket_sla_fill($db, $tid); } catch (Throwable $e) {}
        try { require_once __DIR__ . '/TicketRouting.php'; itops_tr_on_created($db, $tid, null, 'security_plan'); } catch (Throwable $e) {}
        if ($owner) $db->prepare('UPDATE itops_tickets SET assignee_id=? WHERE id=?')->execute([$owner, $tid]);
        return [$tn, $tid];
    }

    /** Sign off a draft: the client contact's name and title. The signed plan stays as it was. */
    function itops_sp_sign(PDO $db, int $planId, string $name, string $title, string $note, array $user): array {
        $p = itops_sp_plan_row($db, $planId);
        if (!$p) throw new InvalidArgumentException('No such plan.');
        if ($p['status'] !== 'draft') throw new InvalidArgumentException('Only a draft can be signed off.');
        if (mb_strlen(trim($name)) < 2) throw new InvalidArgumentException('Name the client contact who signs off.');
        // BT124 (code review SEC-19): what is signed is kept — a copy of every item and decision, the scan and baseline it
        // came from, and its SHA-256 fingerprint — read and checked inside one transaction, so nothing changes in between
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT id FROM itops_sa_plans WHERE id=? FOR UPDATE'); $q->execute([$planId]);
            $q = $db->prepare("SELECT rule_id, stage, found_status, decision, note, owner_id, due_date, expires_on, ticket_id FROM itops_sa_plan_items WHERE plan_id=? ORDER BY stage, seq FOR UPDATE"); $q->execute([$planId]);
            $items = $q->fetchAll(PDO::FETCH_ASSOC);
            $und = count(array_filter($items, fn($i) => $i['decision'] === 'undecided'));
            if ($und) throw new InvalidArgumentException($und . ' item' . ($und === 1 ? ' is' : 's are') . ' not decided yet. Decide every item (Later is a decision) before signing off.');
            $snap = json_encode(['plan' => $planId, 'version' => (int)$p['version'], 'run_id' => $p['run_id'] !== null ? (int)$p['run_id'] : null, 'baseline' => $p['baseline'],
                                 'signed_name' => mb_substr(trim($name), 0, 150), 'signed_title' => mb_substr(trim($title), 0, 150), 'items' => $items], JSON_UNESCAPED_UNICODE);
            $hash = hash('sha256', $snap);
            $db->exec("UPDATE itops_sa_plans SET status='superseded' WHERE status='signed'");
            $db->prepare("UPDATE itops_sa_plans SET status='signed', signed_name=?, signed_title=?, signed_note=?, signed_at=NOW(), signed_by=?, updated_at=NOW(), signed_snapshot=?, signed_hash=? WHERE id=?")
               ->execute([mb_substr(trim($name), 0, 150), mb_substr(trim($title), 0, 150), mb_substr(trim($note), 0, 1000) ?: null, (int)$user['id'], $snap, $hash, $planId]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        $newer = (int)$db->query('SELECT MAX(id) FROM itops_sa_runs')->fetchColumn();
        return ['version' => (int)$p['version'], 'hash' => $hash, 'newer_scan' => $p['run_id'] !== null && $newer > (int)$p['run_id']];
    }
}
