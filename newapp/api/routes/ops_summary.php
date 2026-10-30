<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — consolidated dashboard tiles. One authenticated call; each tile is
 * computed defensively so a missing table/column returns null instead of 500ing.
 * GET /api/ops_summary
 */
$db = getDB();
if ($method !== 'GET') error_response('Method not allowed', 405);

$out = [];
// BT91: each tile only for someone who may open that module (others get null, as for a missing table).
$can = function (string $mod) use ($currentUser) { return opsh_can($currentUser, $mod, 'view'); };
$one = function (string $sql, string $mod) use ($db, $can) { if (!$can($mod)) return null; try { return (int)$db->query($sql)->fetchColumn(); } catch (\Throwable $e) { return null; } };

// Review DESK-23: cancelled tickets are not open, and without Tickets · All only the person's own tickets count.
$__tkScope = opsh_can($currentUser, 'tickets', 'all') ? '' : ' AND (assignee_id=' . (int)$currentUser['id'] . ' OR requester_id=' . (int)$currentUser['id'] . ' OR backup_id=' . (int)$currentUser['id'] . ')';
$out['open_tickets']        = $one("SELECT COUNT(*) FROM opsh_tickets WHERE status NOT IN ('resolved','closed','cancelled') AND merged_into_id IS NULL" . $__tkScope, 'tickets');
$out['open_alerts']         = $one("SELECT COUNT(*) FROM opsh_alerts WHERE status='open'", 'guardian');
$out['onboarding_active']   = $one("SELECT COUNT(*) FROM opsh_ob_runs WHERE status='in_progress'", 'onboarding');
$out['onboarding_overdue']  = $one("SELECT COUNT(DISTINCT r.id) FROM opsh_ob_runs r JOIN opsh_ob_run_tasks rt ON rt.run_id=r.id
                                    WHERE r.status='in_progress' AND rt.status='pending' AND rt.due_date IS NOT NULL AND rt.due_date < CURDATE()", 'onboarding');
$out['kb_gaps']             = $one("SELECT COUNT(*) FROM opsh_kb_gaps", 'kb_gaps');
$out['deflections_total']   = $one("SELECT COALESCE(SUM(deflect_count),0) FROM opsh_kb_articles", 'kb');
$out['kb_published']        = $one("SELECT COUNT(*) FROM opsh_kb_articles WHERE status='published'", 'kb');

// Standards coverage % (requirements with any evidence: uploaded file OR a cross-link)
if (!$can('standards')) { $out['std_pct'] = null; json_response($out); }
try {
    $tot = (int)$db->query("SELECT COUNT(*) FROM opsh_std_requirements")->fetchColumn();
    $cov = (int)$db->query("SELECT COUNT(*) FROM (
              SELECT requirement_id AS rid FROM opsh_std_evidence
              UNION SELECT a_id FROM opsh_links WHERE a_type='std_req'
              UNION SELECT b_id FROM opsh_links WHERE b_type='std_req'
            ) x")->fetchColumn();
    $out['std_total'] = $tot; $out['std_covered'] = min($cov, $tot);
    $out['std_pct'] = $tot ? (int)round(min($cov,$tot) * 100 / $tot) : 0;
} catch (\Throwable $e) { $out['std_pct'] = null; }

json_response($out);
