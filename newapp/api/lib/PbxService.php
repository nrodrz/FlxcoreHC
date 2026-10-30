<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT151 — what the app does with the office PBX (Grandstream UCM63xx, lib/Ucm.php) beyond reading its extensions as
 * accounts (SystemConnect.php): the full list of extensions with the person each belongs to, the PBX's status, and taking
 * an extension out of service or back. Every change is written to the Audit Log, with what was before; a refusal too.
 */
require_once __DIR__ . '/SystemConnect.php';
require_once __DIR__ . '/Ucm.php';

if (!function_exists('itops_pbx_client')) {





    // ───────────────────────────── BT153: managing extensions ─────────────────────────────



    /**
     * When each extension out of service was first seen out of service by this app (it decides when it may be deleted).
     * Kept in the connection state; an extension back in service is forgotten. Returns the map.
     */
    function itops_pbx_note_out(PDO $db, array $rows): array {
        $st = itops_conn_state($db, 'pbx'); $m = is_array($st['out_seen'] ?? null) ? $st['out_seen'] : []; $now = date('Y-m-d H:i:s'); $chg = false; $seen = [];
        foreach ($rows as $r) { $e = (string)$r['extension']; $seen[$e] = 1;
            if ($r['out_of_service'] && !isset($m[$e])) { $m[$e] = $now; $chg = true; }
            if (!$r['out_of_service'] && isset($m[$e])) { unset($m[$e]); $chg = true; } }
        foreach (array_keys($m) as $e) if (!isset($seen[(string)$e])) { unset($m[$e]); $chg = true; }
        if ($chg) { $st = itops_conn_state($db, 'pbx'); $st['out_seen'] = $m; itops_conn_put($db, 'pbx', $st); }
        return $m;
    }










}
