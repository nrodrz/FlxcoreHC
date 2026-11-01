<?php
/** v1.170.9 HTTP smoke fixture (used by tools/http_smoke/run.sh; CLI only, isolated DB). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
require $ROOT . '/app/bootstrap.php';
require $ROOT . '/tests/characterization/install_into_char.php';
use FlxCoreHC\TenantContext; use FlxCoreHC\Repository\{HolderRepository,CredentialRepository,RenewalTaskRepository,CalendarRepository};
$pdo = FlxCoreHC\Database::pdo(); $now = utc_now();
$auth = new FlxCoreHC\Auth\AuthService();
$r = $auth->registerTenant('Smoke Org', 'medical_group', 'Olivia Owner', 'owner@smoke.test', 'SmokePass12345', 'SmokePass12345', allowPlatform: false);
if (is_string($r)) { fwrite(STDERR, $r . "\n"); exit(1); }
$tid = $r['tenant_id'];
$pdo->prepare("UPDATE tenants SET status='active', vertical='healthcare' WHERE id=?")->execute([$tid]);
FlxCoreHC\Auth\AuthService::seedTemplatesFor($tid); FlxCoreHC\RoleService::seedFor($tid);
$owner = new TenantContext($tid, $r['id'], 'tenant_owner', 'Smoke Org', 'healthcare', 'medical_group', 'Olivia Owner', false);
$mk = fn($n, $ln) => (new HolderRepository($owner))->insert(['holder_type'=>'individual','provider_type'=>'physician','first_name'=>$n,'last_name'=>$ln,'vertical'=>'healthcare','status'=>'active']);
$P1 = $mk('Ann', 'Alpha'); $P2 = $mk('Bob', 'Bravo');
$out = ['tenant'=>$tid,'P1'=>$P1,'P2'=>$P2];
foreach ([['P1',$P1,'+10 days'],['P2',$P2,'-10 days']] as [$k,$h,$e]) {
    $c = (new CredentialRepository($owner))->insert(['holder_id'=>$h,'credential_type'=>'state_license','name'=>"$k License",'status'=>'active','expiry_date'=>date('Y-m-d', strtotime($e))]);
    $d = uuid4(); $dir = sys_get_temp_dir() . '/flx_smoke_docs'; @mkdir($dir, 0777, true); $f = "$dir/$d.pdf"; file_put_contents($f, "%PDF-1.4 smoke");
    $pdo->prepare('INSERT INTO credential_documents (id,tenant_id,credential_id,file_path,original_name,mime_type,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$d,$tid,$c,$f,"$k.pdf",'application/pdf',$now]);
    (new RenewalTaskRepository($owner))->insert(['credential_id'=>$c,'holder_id'=>$h,'title'=>"Renew $k",'status'=>'open','priority'=>'normal']);
    (new CalendarRepository($owner))->insert(['holder_id'=>$h,'kind'=>'task','source'=>'manual','title'=>"$k event",'due_at'=>date('Y-m-d 10:00:00', strtotime('+3 days')),'all_day'=>0,'priority'=>'normal','status'=>'open']);
    $out["cred_$k"]=$c; $out["doc_$k"]=$d;
    [$ok,$case] = (new FlxCoreHC\CredentialingCaseService($owner))->open(['holder_id'=>$h,'case_type'=>'initial']); $out["case_$k"]=$case;
}
$mkUser = function(string $email, string $role, ?string $holder=null, string $access='all') use ($pdo,$tid,$now): string {
    $id = uuid4();
    $pdo->prepare('INSERT INTO users (id,tenant_id,email,password_hash,full_name,role,status,platform_admin,holder_id,provider_access,created_at) VALUES (?,?,?,?,?,?,?,0,?,?,?)')
        ->execute([$id,$tid,$email,password_hash('SmokePass12345', PASSWORD_ARGON2ID),'Test '.$role,$role,'active',$holder,$access,$now]);
    return $id;
};
$uR = $mkUser('restricted@smoke.test','staff',null,'assigned'); FlxCoreHC\AssignmentService::assign($owner, $P1, $uR);
$mkUser('manager@smoke.test','manager'); $mkUser('coord@smoke.test','agent'); $mkUser('staff@smoke.test','staff'); $mkUser('readonly@smoke.test','read_only');
$mkUser('portal@smoke.test','producer',$P1);
$rs = new FlxCoreHC\RoleService($owner); [$ok,,$rid] = $rs->create('Field Portal', ['documents.view'], true);
$slug = (string)$pdo->query("SELECT slug FROM roles WHERE id=".$pdo->quote((string)$rid))->fetchColumn();
$mkUser('customportal@smoke.test',$slug,$P1);
// v1.171.0 fixtures: session re-validation victims, a platform administrator, a provider-scoped notification and audit row
$mkUser('twofa@smoke.test','staff'); $mkUser('stale2fa@smoke.test','staff'); $mkUser('victim3@smoke.test','staff'); $mkUser('victim4@smoke.test','staff'); $mkUser('victim@smoke.test','staff'); $mkUser('victim2@smoke.test','staff'); $mkUser('promo@smoke.test','manager');
// v1.172.0 fixtures: e-mail two-factor, password reset, and a non-portal role WITHOUT records.write (guided-onboarding capability check)
$mkUser('mfaemail@smoke.test','staff'); $mkUser('pwreset@smoke.test','staff');
[$okNw,,$ridNw] = $rs->create('Docs Only', ['documents.view'], false);
$mkUser('nowrite@smoke.test', (string)$pdo->query("SELECT slug FROM roles WHERE id=".$pdo->quote((string)$ridNw))->fetchColumn());
$paId = uuid4();
$pdo->prepare('INSERT INTO users (id,tenant_id,email,password_hash,full_name,role,status,platform_admin,provider_access,totp_enabled,created_at) VALUES (?,?,?,?,?,?,?,1,?,0,?)')
    ->execute([$paId,$tid,'padmin@smoke.test',password_hash('SmokePass12345', PASSWORD_ARGON2ID),'Test Platform Admin','tenant_owner','active','all',$now]);
$rid = (string)$pdo->query("SELECT id FROM users WHERE email='restricted@smoke.test'")->fetchColumn();
FlxCoreHC\NotificationService::push($tid,$rid,'expiring','warn','NOTICE-P1 Ann Alpha license','', '', 'holders',$P1,'smoke:p1');
FlxCoreHC\NotificationService::push($tid,$rid,'expiring','warn','NOTICE-P2 Bob Bravo license','', '', 'holders',$P2,'smoke:p2');
$pdo->prepare("INSERT INTO audit_logs (id,tenant_id,user_id,action,entity_type,entity_id,changes,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([uuid4(),$tid,$r['id'],'provider_updated','holders',$P2,'{"name":"Bob Bravo"}','127.0.0.1',$now]);
$pdo->prepare("INSERT INTO ocs_profiles (id, tenant_id, holder_id, sicro_status, profile_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?)")->execute([uuid4(), $tid, $P1, 'registered', 'SMOKE-OCS-1', $now, $now]);   // row only (no timeline event)   // an existing OCS record must NOT make the PR-only panel appear for an FL-only tenant
$pdo->prepare("INSERT INTO email_outbox (id,tenant_id,to_email,subject,body,category,status,attempts,created_at) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([uuid4(),null,'nobody@smoke.test','SMOKE queued mail','body','general','queued',0,$now]);
$out['custom_slug']=$slug;
file_put_contents(getenv('FLX_IDS') ?: sys_get_temp_dir() . '/flx_smoke_ids.json', json_encode($out, JSON_PRETTY_PRINT));
echo "setup ok\n";
