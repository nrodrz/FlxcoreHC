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

// ===== v1.175.2 — provider import: duplicates, e-mail rule, big files, Excel formats, plan limit, result data =====
$r = (new FlxCoreHC\Auth\AuthService())->registerTenant('Intake Org', 'medical_group', 'Owner Intake', 'o-intake@clean.test', 'Pass123456789', 'Pass123456789', allowPlatform: false);
if (is_string($r)) { fwrite(STDERR, "registerTenant failed: $r\n"); exit(1); }
$pdo->prepare("UPDATE tenants SET status='active', vertical='healthcare' WHERE id=?")->execute([$r['tenant_id']]);
$pdo->prepare("DELETE FROM credential_holders WHERE tenant_id=?")->execute([$r['tenant_id']]);
FlxCoreHC\Auth\AuthService::seedTemplatesFor($r['tenant_id']); FlxCoreHC\RoleService::seedFor($r['tenant_id']);
$ictx = new FlxCoreHC\TenantContext($r['tenant_id'], $r['id'], 'tenant_owner', 'T', 'healthcare', 'medical_group', 'U', false);
$imp = new FlxCoreHC\ProviderImportService($ictx);
$H = FlxCoreHC\ProviderImportService::TEMPLATE_HEADER;
$csvA = "$H\nindividual,physician,Ana,Lopez,,,ana@intake.test,Grupo A,San Juan,\nindividual,nurse,Luis,Peña,,,luis@intake.test,Grupo A,,\n";

$p = $imp->preview($csvA);
$ck(($p['summary']['create'] ?? 0) === 2 && $p['new_groups'] === ['Grupo A'] && $p['new_locations'] === ['San Juan'], 'D1 preview: 2 to create, and it names the group and location that will be created');
$res = $imp->commit($csvA);
$ck($res['created'] === 2 && count($res['ids']) === 2, 'D2 commit creates both and returns their ids');
$p2 = $imp->preview($csvA);
$ck(($p2['summary']['create'] ?? 0) === 0 && ($p2['summary']['skip'] ?? 0) === 2 && $p2['new_groups'] === [], 'D3 uploading the same file again creates nothing (matched by e-mail, no NPI needed)');
$ck(str_contains($p2['rows'][0]['reason'] ?? '', 'e-mail'), 'D3 the skip reason says why');

$pn = $imp->preview("$H\nindividual,physician,No,Mail,,,,,,\n");
$ck(($pn['summary']['error'] ?? 0) === 1 && str_contains($pn['rows'][0]['reason'], 'e-mail'), 'D4 an individual without an e-mail is refused, as in the Add provider form');
$po = $imp->preview("$H\norganization,lab,,,Bayview Lab LLC,,,,,\norganization,lab,,,bayview lab llc,,,,,\n");
$ck(($po['summary']['create'] ?? 0) === 1 && ($po['summary']['skip'] ?? 0) === 1, 'D5 an organization needs no e-mail; the same name twice in a file is caught regardless of case');

$big = $H . "\n" . str_repeat("individual,physician,A,B,,,x@y.test,,,\n", FlxCoreHC\ProviderImportService::MAX_ROWS + 1);
$pb = $imp->preview($big);
$ck(empty($pb['ok']) && str_contains((string) $pb['error'], '2000'), 'D6 a file over 2,000 rows is refused with a clear message');

// Excel exports: semicolons, Windows-1252, a quoted cell with a line break
$semi = "holder_type;provider_type;first_name;last_name;org_name;npi;email;group;location;role_title\nindividual;physician;José;Rodríguez;;;jose@intake.test;\"Grupo\nSur\";;\n";
$ps = $imp->preview($semi);
$ck(($ps['summary']['create'] ?? 0) === 1 && $ps['rows'][0]['last_name'] === 'Rodríguez' && $ps['rows'][0]['group'] === "Grupo\nSur", 'D7 a semicolon-separated file with a quoted line break is read correctly');
$ps2 = $imp->preview(mb_convert_encoding($semi, 'Windows-1252', 'UTF-8'));
$ck(($ps2['summary']['create'] ?? 0) === 1 && $ps2['rows'][0]['last_name'] === 'Rodríguez' && $ps2['rows'][0]['first_name'] === 'José', 'D8 a Windows-1252 file keeps its accents');
$pt = $imp->preview("first_name\tlast_name\temail\nMaría\tPérez\tmaria@intake.test\n");
$ck(($pt['summary']['create'] ?? 0) === 1, 'D9 a tab-separated file is read');

// Plan limit: the preview marks the rows over the limit, and the commit adds exactly the rows that fit
$pdo->exec("INSERT INTO plans (id, code, name, max_holders, active, is_default, sort_order, created_at) VALUES ('plan-cap', 'cap', 'CapPlan', 4, 1, 0, 99, UTC_TIMESTAMP())");
$pdo->prepare("UPDATE tenants SET plan_id = 'plan-cap' WHERE id = ?")->execute([$ictx->tenantId]);
$have = (int) $pdo->query("SELECT COUNT(*) FROM credential_holders WHERE tenant_id = " . $pdo->quote($ictx->tenantId) . " AND deleted_at IS NULL")->fetchColumn();
$room = FlxCoreHC\PlanService::holderRoom($ictx->tenantId);
$ck($room === max(0, 4 - $have), "D10 holderRoom = plan limit minus providers on file ($room)");
$csvC = "$H\n" . implode("", array_map(fn($n) => "individual,physician,Cap$n,Test,,,cap$n@intake.test,,,\n", range(1, 5)));
$pc = $imp->preview($csvC);
$ck(($pc['summary']['create'] ?? 0) === $room && ($pc['summary']['limit'] ?? 0) === 5 - $room, 'D11 the preview shows how many rows fit the plan and how many are over');
$rc = $imp->commit($csvC);
$ck($rc['created'] === $room && !$rc['capacity'] && count($rc['rejected']) === 5 - $room && $rc['rejected'][0]['status'] === 'limit', 'D12 commit adds exactly the rows that fit and lists the rest as rejected');
$ck(isset($rc['no_checklist']) && $rc['no_checklist'] <= $rc['created'], 'D13 commit reports how many providers arrived without a checklist');

echo $fail ? "CLEANUP SUITE: FAIL\n" : "CLEANUP SUITE: PASS\n";
return $fail ? 1 : 0;
