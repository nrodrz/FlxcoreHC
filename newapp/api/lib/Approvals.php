<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT115: which changes need a second person's approval (Settings → Approvals, Super Admin).
 * Setting itops_settings.m365_approvals = {"retire":true,"wipe":true,"fresh_start":true,"ca_change":false}.
 * A small organization with one administrator switches an action off: it then runs on the word of the one person who
 * asks (reason and typed confirmation still required), and the Audit Log records that no second person approved it.
 */
if (!function_exists('itops_appr_catalog')) {

    /** key => [label, area, on by default, what it covers, in use now] */
    function itops_appr_catalog(): array {
        return [
            'retire'      => ['Retire a device', 'Devices', true, 'Removes the organization\'s apps and data from a device and takes it out of Intune.', true],
            'wipe'        => ['Wipe a device (factory reset)', 'Devices', true, 'Erases a device and returns it to factory settings.', true],
            'fresh_start' => ['Fresh start a device', 'Devices', true, 'Reinstalls Windows on a device and removes apps that did not come with it.', true],
            'ca_change'   => ['Change Conditional Access', 'Security', false, 'Create, turn on, turn off, delete or put back a Conditional Access policy, the allowed countries, or switch from security defaults (Microsoft 365 → Security → Conditional Access).', true],
            'admin_role'  => ['Change an admin role', 'Security', false, 'Remove an admin role from someone, make it eligible instead of permanent, or put such a change back (Microsoft 365 → Security → Admin roles).', true],
        ];
    }

    /** The current setting, every key present (defaults filled in). */
    function itops_appr_cfg(PDO $db): array {
        $j = [];
        try { $st = $db->prepare("SELECT sval FROM itops_settings WHERE skey='m365_approvals'"); $st->execute(); $j = json_decode((string)$st->fetchColumn(), true); } catch (Throwable $e) {}
        if (!is_array($j)) $j = [];
        $out = [];
        foreach (itops_appr_catalog() as $k => $c) $out[$k] = array_key_exists($k, $j) ? (bool)$j[$k] : $c[2];
        return $out;
    }

    /** Does this action need a second person right now? */
    function itops_appr_needed(PDO $db, string $key): bool {
        $c = itops_appr_cfg($db); return $c[$key] ?? true;   // an action this list does not know always needs a second person
    }

    /** Save; returns [old, new] of the keys that changed. Unknown keys are ignored. */
    function itops_appr_save(PDO $db, array $want): array {
        $old = itops_appr_cfg($db); $new = $old;
        foreach (itops_appr_catalog() as $k => $c) if (array_key_exists($k, $want)) $new[$k] = (bool)$want[$k];
        $db->prepare("INSERT INTO itops_settings (skey,sval) VALUES ('m365_approvals',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($new)]);
        $o = []; $n = [];
        foreach ($new as $k => $v) if ($old[$k] !== $v) { $o[$k] = $old[$k]; $n[$k] = $v; }
        return [$o, $n];
    }
}
