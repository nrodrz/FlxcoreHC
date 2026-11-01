<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Phase 0.1 runner — executes the six scenarios against an ISOLATED flx_char DB, captures decision-level
 * comparisons, verification-store analysis, and the regulator runtime-dependency finding, and writes
 * docs/architecture/CHARACTERIZATION_TEST_BASELINE.json. No production writes. Uses FLX_CONFIG; app/config.php is not modified.
 *
 * Requires PHP 8 CLI + MySQL/MariaDB. If unavailable, exits 3 (baseline stays PENDING).
 * Usage:  php tests/characterization/run.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // CLI only — never runnable from a browser
$ROOT = dirname(__DIR__, 2);
$realCfg = (getenv('FLX_CONFIG') ?: '') !== '' && is_file((string) getenv('FLX_CONFIG')) ? (string) getenv('FLX_CONFIG') : $ROOT.'/app/config.php';
$tmpCfg = sys_get_temp_dir().'/flx_char_config_'.getmypid().'.php';

if (!extension_loaded('pdo_mysql')) { fwrite(STDERR,"SKIP(3): pdo_mysql not available.\n"); exit(3); }
if (!is_file($realCfg)) { fwrite(STDERR,"SKIP(3): no config (app/config.php or FLX_CONFIG) — need DB credentials.\n"); exit(3); }
$src = require $realCfg;
$env = ['db_host'=>$src['db_host']??'127.0.0.1','db_port'=>$src['db_port']??3306,'db_user'=>$src['db_user']??'root','db_pass'=>$src['db_pass']??''];
$restore = function() use ($tmpCfg){ @unlink($tmpCfg); };
register_shutdown_function($restore);

try {
    $pdo = new PDO("mysql:host={$env['db_host']};port={$env['db_port']};charset=utf8mb4",$env['db_user'],$env['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS flx_char");
    $pdo->exec("CREATE DATABASE flx_char CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    putenv('FLX_CONFIG='.$tmpCfg);   // the real app/config.php is never touched
    file_put_contents($tmpCfg, "<?php return ".var_export($env+['db_name'=>'flx_char','app_env'=>'dev','app_key'=>str_repeat('0',64),'app_timezone'=>'America/Puerto_Rico'],true).";\n");

    require $ROOT.'/app/bootstrap.php';
    require __DIR__.'/install_into_char.php';   // schema + migrations into flx_char (mirrors install.php order)
    require __DIR__.'/fixture.php';
    require __DIR__.'/capture.php';

    $mysqlVer = $pdo->query('SELECT VERSION()')->fetchColumn();
    $out = ['version'=>'0.1','application_version'=>'1.166.0',
        'environment'=>['php'=>PHP_VERSION,'mysql'=>$mysqlVer,'db'=>'flx_char (isolated)'],
        'scenarios'=>[], 'comparisons'=>[], 'verification_analysis'=>[], 'runtime_dependencies'=>[], 'limitations'=>[]];

    foreach (['complete','missing_requirement','expiring_credential','expired_credential','verification_discrepancy','hard_stop_exclusion'] as $key) {
        try {
            [$ctx,$holder] = char_scenario($key);
            $block = char_capture_scenario($ctx,$holder);
            $out['scenarios'][$key] = $block;
            foreach ($block['comparisons'] as $pair=>$res) {
                $out['comparisons'][] = ['scenario'=>$key,'pair'=>$pair,'classification'=>$res['classification'],
                    'decision_differences'=>$res['decision_differences']??[], 'representation_differences'=>$res['representation_differences']??[]];
            }
        } catch (\Throwable $e) {
            $out['scenarios'][$key] = ['RUNTIME_FAILURE'=>$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()];
        }
    }

    // §9 verification-store analysis (static + one live probe on scenario E).
    $out['verification_analysis'] = [
        'source_verifications' => ['writer'=>'VerificationService::recordManual/verifyNppes/verifySam (app/src/VerificationService.php)','reader'=>'VerificationService::forHolder','table'=>'source_verifications (migration 111)'],
        'credential_verifications' => ['writer/reader'=>'VerificationRepository (index.php 4 sites)','table'=>'credential_verifications (migration 076)'],
        'same_credential_in_both' => 'PROBE: scenario E writes only source_verifications; check whether any reader consults credential_verifications for the same credential.',
        'treated_as_equivalent' => 'RELATIONSHIP_UNKNOWN unless a reader is shown to consult both.',
        'affects_compliance' => 'ComplianceLevelService::evaluate signature takes no verification arg; coverageDetail takes only creds. → verification does NOT feed coverage/level directly (captured, verify in scenario E output).',
    ];

    // §10 regulator runtime dependency — proven from source; confirmed live if flx threw above.
    $flxFailed = false;
    foreach ($out['scenarios'] as $s) { if (isset($s['engines']['flx_compliance_data']['RUNTIME_FAILURE'])) { $flxFailed = true; break; } }
    $out['runtime_dependencies'][] = [
        'component'=>'flx_compliance_data()',
        'depends_on'=>'RegulatorRequirementRepository::byRegulator() → SELECT * FROM regulator_requirements',
        'table_exists'=>false,
        'call_path'=>'index.php:866/1468/1738/2367 → flx_compliance_data() (index.php:4561) → byRegulator() (app/src/Repository/RegulatorRequirementRepository.php:28) [no try/catch]',
        'observed'=> $flxFailed ? 'UNRESOLVED_RUNTIME_DEPENDENCY (threw at runtime)' : 'did not throw in flx_char — table may have been created by a migration applied to flx_char; INVESTIGATE',
    ];

    $out['limitations'][] = 'Scenario F (hard_stop_exclusion): exclusion-screening state not created; requires the screening write path + import data. Hard stop not exercised end-to-end.';
    $out['limitations'][] = 'Stored credential status is set by the fixture; engines that recompute from dates (ComplianceLevelService, +30d) vs those that read stored status (CatalogService::coverageDetail) may diverge — that divergence is the subject being measured.';

    $outFile = $ROOT.'/docs/architecture/CHARACTERIZATION_TEST_BASELINE.json';
    @mkdir(dirname($outFile),0775,true);
    file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    fwrite(STDOUT, "Baseline: $outFile\nPHP ".PHP_VERSION." · MySQL $mysqlVer\n");
    $agree=0;$rep=0;$real=0;$rf=0;$unk=0;
    foreach ($out['comparisons'] as $c){ switch($c['classification']){case 'AGREEMENT':$agree++;break;case 'REPRESENTATION_DIFFERENCE':$rep++;break;case 'REAL_BUSINESS_DISAGREEMENT':$real++;break;case 'RUNTIME_FAILURE':$rf++;break;default:$unk++;}}
    fwrite(STDOUT, "comparisons: agree=$agree rep=$rep real=$real runtime_fail=$rf unknown=$unk\n");
    exit($real>0 || $rf>0 ? 2 : 0);   // 2 = a real disagreement or runtime failure was recorded (expected at Phase 0.1)
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine()."\n"); exit(1);
} finally { $restore(); }
