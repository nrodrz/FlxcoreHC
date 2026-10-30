<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — dashboard tiles. One authenticated call; each tile is computed defensively so a missing table/column
 * returns null instead of 500ing, and only for someone who may open that module (others get null).
 * GET /api/ops_summary
 * 0001-A003: only this product's tiles (the reference app's ticket, alert, knowledge and standards tiles removed).
 */
$db = getDB();
if ($method !== 'GET') error_response('Method not allowed', 405);

$can = function (string $mod) use ($currentUser) { return itops_can($currentUser, $mod, 'view'); };
$one = function (string $sql, string $mod) use ($db, $can) { if (!$can($mod)) return null; try { return (int)$db->query($sql)->fetchColumn(); } catch (\Throwable $e) { return null; } };

$out = [];
$out['onboarding_active']  = $one("SELECT COUNT(*) FROM itops_ob_runs WHERE status='in_progress'", 'onboarding');
$out['onboarding_overdue'] = $one("SELECT COUNT(DISTINCT r.id) FROM itops_ob_runs r JOIN itops_ob_run_tasks rt ON rt.run_id=r.id
                                   WHERE r.status='in_progress' AND rt.status='pending' AND rt.due_date IS NOT NULL AND rt.due_date < CURDATE()", 'onboarding');
json_response($out);
