<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/** v1.175.1 table clean-up checks (run by tests/cleanup/run.php on a freshly installed isolated database). Returns 0 = all pass. */
declare(strict_types=1);
use FlxCoreHC\Database;
$fail = false;
$ck = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$pdo = Database::pdo();
$has = fn(string $t) => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote(Database::table($t)))->fetchColumn() === 1;

foreach (['credential_reminders', 'entity_reminders', 'auth_email_codes', 'password_resets', 'external_share_access', 'cross_share_access',
          'invoice_counter', 'holder_requirements', 'requirement_defs', 'nipr_interest'] as $old) {
    $ck(!$has($old), "C1 retired table $old is gone from a fresh installation");
}
foreach (['reminder_log', 'auth_tokens', 'share_access_log'] as $new) $ck($has($new), "C2 merged table $new exists");
$total = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
$ck($total === 86, "C3 the schema has 86 tables (87 with schema_migrations, which the web installer adds) (got $total)");

// C4 invoice numbers come from platform_settings, one step at a time, never repeating
$m = new ReflectionMethod(FlxCoreHC\InvoiceService::class, 'nextNumber'); $m->setAccessible(true);
$a = $m->invoke(null); $b = $m->invoke(null); $c = $m->invoke(null);
$ck($a === 'INV-000001' && $b === 'INV-000002' && $c === 'INV-000003', "C4 invoice numbers run 1,2,3 from the platform_settings counter ($a, $b, $c)");
$ck((string) $pdo->query("SELECT sval FROM platform_settings WHERE skey = 'invoice_seq'")->fetchColumn() === '3', 'C4 the counter is stored under invoice_seq');

// C5 reminder_log: a claim is exclusive per (tenant, type, subject, marker)
$tid = uuid4(); $now = gmdate('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO tenants (id, name, vertical, org_shape, status, is_demo, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$tid, 'Cleanup Org', 'healthcare', 'practice', 'active', 0, $now]);
$ins = $pdo->prepare("INSERT IGNORE INTO reminder_log (id, tenant_id, subject_type, subject_id, marker, queued_at, sent_at) VALUES (?,?,?,?,?,?,?)");
$sid = uuid4();
$ins->execute([uuid4(), $tid, 'credential', $sid, '30:2030-01-01', $now, $now]); $r1 = $ins->rowCount();
$ins->execute([uuid4(), $tid, 'credential', $sid, '30:2030-01-01', $now, $now]); $r2 = $ins->rowCount();
$ins->execute([uuid4(), $tid, 'credential', $sid, '30:2031-01-01', $now, $now]); $r3 = $ins->rowCount();
$ck($r1 === 1 && $r2 === 0 && $r3 === 1, "C5 reminder_log claims once per threshold and expiry date; a renewal (new date) starts a new cycle ($r1,$r2,$r3)");

echo $fail ? "CLEANUP SUITE: FAIL\n" : "CLEANUP SUITE: PASS\n";
return $fail ? 1 : 0;
