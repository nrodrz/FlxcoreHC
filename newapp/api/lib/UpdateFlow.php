<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT129: the steps of installing an update, shared by Settings → Updates (a person), the License Hub offer
 * ("Download and check") and the maintenance window (the scheduler). Every step writes the Audit Log as before.
 *
 *   opsh_upd_stage_bundle   check a bundle file (signature, package, build, space, write access) and keep it as "checked"
 *   opsh_upd_run_install    step 1 (old code): backup, copy of the old files, maintenance on, new files in place
 *   opsh_upd_run_finish     step 2 (new code): database update, maintenance off
 * Automatic installs (opt-in, BT129): only a bundle offered by the License Hub, checked with no problem, inside the
 * window this installation chose (days and hours, the app's time zone). Never a bundle uploaded by hand.
 */
require_once __DIR__ . '/Updates.php';

if (!function_exists('opsh_upd_staged')) {

    function opsh_upd_staged(PDO $db): array {
        $v = $db->query("SELECT sval FROM opsh_settings WHERE skey='update_staged'")->fetchColumn();
        $j = json_decode((string)$v, true); return is_array($j) ? $j : [];
    }
    function opsh_upd_set_staged(PDO $db, ?array $s): void {
        if ($s === null) { $db->exec("DELETE FROM opsh_settings WHERE skey='update_staged'"); return; }
        $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('update_staged',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($s)]);
    }
    function opsh_upd_forget(array $s): void {
        foreach (['bundle', 'package'] as $k) if (!empty($s[$k]) && strpos((string)$s[$k], opsh_upd_root() . '/data/updates/staged/') === 0) @unlink((string)$s[$k]);
    }

    /**
     * Checks a bundle already saved in data/updates/staged and keeps it as the checked one (the previous one is
     * forgotten). $from: 'upload' | 'hub'. Returns the staged record. Throws InvalidArgumentException (the file is
     * removed then).
     */
    function opsh_upd_stage_bundle(PDO $db, string $dest, int $by, string $byName, string $from = 'upload'): array {
        $old = opsh_upd_staged($db); if ($old && ($old['bundle'] ?? '') !== $dest) { opsh_upd_forget($old); opsh_upd_set_staged($db, null); }
        try { $o = opsh_upd_open($dest); }
        catch (InvalidArgumentException $e) { @unlink($dest); throw $e; }
        $chk = opsh_upd_check($db, $o['manifest'], $o['package']);
        $s = ['bundle' => $dest, 'package' => $o['package'], 'manifest' => $o['manifest'], 'check' => $chk, 'at' => date('Y-m-d H:i:s'), 'by' => $by, 'by_name' => $byName, 'from' => $from];
        opsh_upd_set_staged($db, $s);
        audit_log('update_checked', 'update', null, 'Build ' . $o['manifest']['build'], null,
                  ['Build' => $o['manifest']['build'], 'Installed' => OPSH_BUILD, 'From' => $from === 'hub' ? 'the License Hub' : 'a file uploaded', 'Problems' => $chk['problems'] ? implode(' · ', $chk['problems']) : 'none', 'Files' => $chk['files']]);
        return $s;
    }

    /**
     * Step 1 on the checked bundle. Returns ['to', 'backup', 'placed']. Throws InvalidArgumentException (refused,
     * nothing changed) or RuntimeException (failed; the previous files were put back).
     */
    function opsh_upd_run_install(PDO $db, int $by, string $appName): array {
        if (opsh_upd_maintenance()) throw new InvalidArgumentException('An update is already under way (maintenance mode is on).');
        $s = opsh_upd_staged($db); if (!$s || !is_file((string)($s['bundle'] ?? ''))) throw new InvalidArgumentException('Check a bundle first.');
        $lk = (int)$db->query("SELECT GET_LOCK('opsh_update', 0)")->fetchColumn(); if ($lk !== 1) throw new InvalidArgumentException('Another update is running.');
        try {
            // checked again from the bundle itself: the kept package is not trusted after the check
            @unlink((string)$s['package']); $o = opsh_upd_open((string)$s['bundle']);
            try { $r = opsh_upd_install($db, $o['package'], $o['manifest'], $by, $appName); }
            catch (InvalidArgumentException $e) { @unlink($o['package']); throw $e; }
            catch (Throwable $e) {
                @unlink($o['package']);
                opsh_upd_last($db, ['status' => 'failed', 'from' => OPSH_BUILD, 'to' => $o['manifest']['build'], 'at' => date('Y-m-d H:i:s'), 'by' => $by, 'message' => $e->getMessage()]);
                audit_log('update_failed', 'update', null, 'Build ' . $o['manifest']['build'], null, ['From' => OPSH_BUILD, 'Why' => $e->getMessage()]);
                throw new RuntimeException($e->getMessage());
            }
            opsh_upd_forget($s); opsh_upd_set_staged($db, null);
            opsh_upd_last($db, ['status' => 'placed', 'from' => OPSH_BUILD, 'to' => $o['manifest']['build'], 'backup' => $r['backup'], 'at' => date('Y-m-d H:i:s'), 'by' => $by]);
            audit_log('update_files_placed', 'update', null, 'Build ' . $o['manifest']['build'], ['Build' => OPSH_BUILD],
                      ['Build' => $o['manifest']['build'], 'Files' => $r['placed'], 'New files' => $r['new'], 'Backup' => $r['backup']]);
            return ['to' => $o['manifest']['build'], 'backup' => $r['backup'], 'placed' => $r['placed']];
        } finally { try { $db->query("SELECT RELEASE_LOCK('opsh_update')"); } catch (Throwable $e) {} }
    }

    /** Step 2 (runs on the new code). Returns ['ok', 'build', 'db_steps', 'message']. Throws InvalidArgumentException when there is nothing to finish. */
    function opsh_upd_run_finish(PDO $db, int $by): array {
        $m = opsh_upd_maintenance();
        if (!$m) throw new InvalidArgumentException('There is no update waiting to be finished.');
        if ((string)$m['to'] !== OPSH_BUILD) throw new InvalidArgumentException('The files of build ' . $m['to'] . ' are not the ones running (this is ' . OPSH_BUILD . '). Put the previous build back, or upload the files again.');
        require_once __DIR__ . '/Migrations.php';
        $rep = []; $err = '';
        try { $rep = opsh_run_migrations($db); foreach ($rep as $x) if (($x['status'] ?? '') === 'error') { $err = ($x['version'] ?? '?') . ': ' . ($x['message'] ?? 'failed'); break; } }
        catch (Throwable $e) { $err = $e->getMessage(); }
        opsh_upd_maint_off();
        $last = opsh_upd_last($db);
        $done = ['status' => $err === '' ? 'done' : 'db_failed', 'from' => (string)($last['from'] ?? ''), 'to' => OPSH_BUILD, 'backup' => (string)($last['backup'] ?? ''),
                 'at' => date('Y-m-d H:i:s'), 'by' => $by, 'message' => $err, 'db_steps' => count(array_filter($rep, fn($x) => ($x['status'] ?? '') === 'applied'))];
        opsh_upd_last($db, $done);
        audit_log($err === '' ? 'update_installed' : 'update_failed', 'update', null, 'Build ' . OPSH_BUILD, ['Build' => $done['from']],
                  ['Build' => OPSH_BUILD, 'Database steps' => $done['db_steps'], 'Result' => $err === '' ? 'installed' : 'database update failed: ' . $err]);
        if ($err === '') opsh_upd_prune();
        return ['ok' => $err === '', 'build' => OPSH_BUILD, 'db_steps' => $done['db_steps'], 'message' => $err];
    }

    // ───────────── the maintenance window (opt-in) ─────────────

    function opsh_upd_auto_cfg(PDO $db): array {
        $v = json_decode((string)$db->query("SELECT sval FROM opsh_settings WHERE skey='update_auto'")->fetchColumn(), true);
        $c = (is_array($v) ? $v : []) + ['on' => false, 'days' => [6, 7], 'start' => '01:00', 'end' => '05:00'];
        $c['days'] = array_values(array_unique(array_filter(array_map('intval', (array)$c['days']), fn($d) => $d >= 1 && $d <= 7))); sort($c['days']);
        return $c;
    }
    /** Checks and saves the window. Throws InvalidArgumentException with a sentence. */
    function opsh_upd_auto_save(PDO $db, array $b): array {
        $c = opsh_upd_auto_cfg($db);
        if (array_key_exists('on', $b)) $c['on'] = (bool)$b['on'];
        if (array_key_exists('days', $b)) {
            if (!is_array($b['days'])) throw new InvalidArgumentException('Days is a list (1 = Monday … 7 = Sunday).');
            $d = []; foreach ($b['days'] as $x) { if (!is_int($x) && !(is_string($x) && ctype_digit($x))) throw new InvalidArgumentException('Days are 1 (Monday) to 7 (Sunday).'); $x = (int)$x; if ($x < 1 || $x > 7) throw new InvalidArgumentException('Days are 1 (Monday) to 7 (Sunday).'); $d[] = $x; }
            $c['days'] = array_values(array_unique($d)); sort($c['days']);
        }
        foreach (['start', 'end'] as $k) if (array_key_exists($k, $b)) {
            $t = trim((string)$b[$k]); if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) throw new InvalidArgumentException('Times are HH:MM (24-hour), for example 01:00.'); $c[$k] = $t;
        }
        if ($c['start'] === $c['end']) throw new InvalidArgumentException('The window needs a start and an end that are not the same.');
        if ($c['on'] && !$c['days']) throw new InvalidArgumentException('Choose at least one day for the window.');
        $db->prepare("INSERT INTO opsh_settings (skey,sval) VALUES ('update_auto',?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)")->execute([json_encode($c)]);
        return $c;
    }
    /** Is $ts inside the window? A window may cross midnight (22:00–02:00 belongs to the day it starts). */
    function opsh_upd_in_window(array $c, int $ts): bool {
        $hm = date('H:i', $ts); $dow = (int)date('N', $ts);
        if ($c['start'] < $c['end']) return in_array($dow, $c['days'], true) && $hm >= $c['start'] && $hm < $c['end'];
        if ($hm >= $c['start']) return in_array($dow, $c['days'], true);
        if ($hm < $c['end']) return in_array((int)date('N', $ts - 86400), $c['days'], true);
        return false;
    }
}
