<?php
/**
 * v1.170.9 — provider-scope (need-to-know) STATIC guards. Runs WITHOUT a database:
 *   php tests/authz/test_provider_scope_static.php
 * S1 ProviderScope SQL generation · S2 no post-query filtering left · S3 every provider-bearing repository declares a scope
 * S4 raw-SQL services apply ProviderScope · S5 fail-closed guards · S6 document routes authorization · S7 migrations registered
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__, 2);
spl_autoload_register(function ($c) use ($ROOT) {
    if (str_starts_with($c, 'FlxCoreHC\\')) { $f = $ROOT . '/app/src/' . str_replace('\\', '/', substr($c, 10)) . '.php'; if (is_file($f)) require $f; }
});
$fail = false;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail = true; };
$src = fn(string $rel) => (string) file_get_contents($ROOT . '/' . $rel);

// ---- S1: SQL generation (restricted instance built by reflection: no DB needed)
$mk = function (bool $restricted): FlxCoreHC\ProviderScope {
    $rc = new ReflectionClass(FlxCoreHC\ProviderScope::class);
    $o = $rc->newInstanceWithoutConstructor();
    foreach (['restricted' => $restricted, 'tenantId' => 'T1', 'userId' => 'U1', 'ctx' => null] as $k => $v) {
        $p = $rc->getProperty($k); $p->setAccessible(true); $p->setValue($o, $v);
    }
    return $o;
};
$open = $mk(false); $shut = $mk(true);
$check($open->sql('c.holder_id') === '' && $open->params() === [] && $open->sqlViaCredential('d.credential_id') === '' && $open->allows('x') === true,
    'S1 unrestricted scope adds nothing (behaviour unchanged for owners/unrestricted users)');
$s = $shut->sql('c.holder_id', false);
$check(str_contains($s, 'c.holder_id IN (SELECT ha.holder_id FROM holder_assignments ha WHERE ha.tenant_id = ? AND ha.user_id = ?)') && !str_contains($s, 'IS NULL'),
    'S1 strict scope = correlated IN-subquery on holder_assignments, tenant + user bound');
$check(str_contains($shut->sql('t.holder_id'), 't.holder_id IS NULL OR'), 'S1 nullable scope lets tenant-wide (NULL provider) rows through');
$check($shut->params() === ['T1', 'U1'], 'S1 bound params = [tenant, user] — no inlined ids');
$check(substr_count($shut->sql('COALESCE(t.holder_id, c.holder_id)'), '?') === 2, 'S1 COALESCE form accepted');
foreach (["c.holder_id; DROP TABLE x", "1=1 OR c.holder_id", "c.holder_id) OR (1=1", "c.`holder_id`"] as $bad) {
    $thrown = false; try { $shut->sql($bad); } catch (\InvalidArgumentException $e) { $thrown = true; }
    $check($thrown, 'S1 rejects hostile column expression: ' . $bad);
}
$check(str_contains($shut->sqlViaCredential('d.credential_id'), 'holder_assignments') && substr_count($shut->sqlViaCredential('credential_id'), '?') === 2, 'S1 credential-relative scope resolves through credentials → assignments');
$check($shut->restricted() === true && $open->restricted() === false, 'S1 restricted() flag');

// ---- S2: no post-query filtering remains
$left = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app/src')) as $f) {
    if ($f->getExtension() !== 'php') continue;
    $t = file_get_contents($f->getPathname());
    if (preg_match('/ntkFilter|ntkRow|ntk_holder/', $t)) $left[] = basename($f->getPathname());
}
$check($left === [], 'S2 no ntkFilter/ntkRow/ntk_holder post-filters remain in app/src' . ($left ? ' (' . implode(',', $left) . ')' : ''));
$repoDir = $ROOT . '/app/src/Repository/';
$post = [];
foreach (glob($repoDir . '*.php') as $f) { if (preg_match('/array_filter\([^;]*visibleIds|visibleIds\(/', file_get_contents($f))) $post[] = basename($f); }
$check($post === [], 'S2 no repository filters rows in PHP by visibleIds()');

// ---- S3: provider-bearing repositories declare their scope
$want = ['Holder' => "'id'", 'Credential' => "'holder_id'", 'RenewalTask' => "'holder_id'", 'Calendar' => "'holder_id'",
    'Application' => "'holder_id'", 'Attestation' => "'holder_id'", 'CaseDocument' => null, 'CeEntry' => "'holder_id'",
    'CredentialingCase' => "'holder_id'", 'HolderRegulator' => "'holder_id'", 'ProducerRequirement' => "'holder_id'",
    'ProviderContact' => "'holder_id'", 'ProviderEducation' => "'holder_id'", 'ProviderWorkHistory' => "'holder_id'",
    'Screening' => "'holder_id'", 'Verification' => "'holder_id'"];
foreach ($want as $repo => $col) {
    $t = $src("app/src/Repository/{$repo}Repository.php");
    $ok = preg_match('/function providerColumn\(\)[^{]*\{\s*return\s+(\'[a-z_]+\')/', $t, $m) === 1;
    $check($ok && ($col === null || $m[1] === $col) || ($col === null && str_contains($t, 'providerColumn')), "S3 {$repo}Repository declares provider scope" . ($ok ? " ({$m[1]})" : ''));
}
$check(preg_match("/function credentialColumn\(\)[^{]*\{\s*return\s+'credential_id'/", $src('app/src/Repository/DocumentRepository.php')) === 1, 'S3 DocumentRepository scopes through its credential');
$tr = $src('app/src/Repository/TenantRepository.php');
foreach (['find' => 'public function find', 'all' => 'public function all', 'count' => 'public function count', 'update' => 'public function update', 'delete' => 'public function delete'] as $n => $sig) {
    $body = substr($tr, (int) strpos($tr, $sig), 900);
    $check(str_contains($body, 'scopeSql()'), "S3 TenantRepository::$n applies the provider scope");
}

// ---- S4: services with raw SQL apply ProviderScope
foreach (['DashboardService', 'AgencyWorkQueueService', 'DocumentRequestService', 'VerificationService', 'ComplianceEventService', 'DataExportService'] as $svc) {
    $check(str_contains($src("app/src/$svc.php"), 'ProviderScope::for'), "S4 $svc applies ProviderScope");
}
$dash = $src('app/src/DashboardService.php');
$check(preg_match('/FROM credentials WHERE tenant_id=\? AND deleted_at IS NULL(?!\{\$S0\})/', str_replace("\n", ' ', $dash)) === 0, 'S4 every dashboard credentials aggregate carries the scope');
$check(str_contains($dash, "'scoped' => \$scope->restricted()") && str_contains($dash, 'queryFailures'), 'S4 dashboard reports scoped + queryFailures');

// ---- S5: fail closed
$as = $src('app/src/AssignmentService.php');
$check(str_contains($as, "return self::\$cache[\$key] = ['mode' => 'assigned', 'ids' => []];") && substr_count($as, "'mode' => 'assigned', 'ids' => []") >= 2, 'S5 AssignmentService: missing user row / lookup failure → assigned + no ids (sees nothing)');
$check(!preg_match("/catch \(\\\\Throwable[^)]*\)\s*\{[^}]*'mode' => 'all'/", $as), 'S5 no catch block widens access to "all"');
$check(str_contains($dash, "\$failed++") && !preg_match('/catch \(\\\\Throwable \$e\) \{ return \[\]; \}/', $dash), 'S5 dashboard failures are counted, not silently zero');
$check(str_contains($src('index.php'), "'system:share-link'") && str_contains($as, 'SYSTEM_ROLES'), 'S5 share-link viewer is an explicit system actor (not a user role)');

// ---- S6: document authorization
$idx = $src('index.php');
$check(preg_match("/in_array\(\\\$seg\[2\] \?\? '', \['verify', 'unverify'\], true\)\) \{\s*CsrfGuard::verify\(\);[^}]*?requireCap\(\\\$ctx, 'documents\.verify'\)/s", $idx) === 1, 'S6 document verify/unverify requires documents.verify');
$check(preg_match("/'documents\.manage'/", $idx) === 1, 'S6 documents.manage still enforced separately from records.write');

$check(preg_match("/\\\$ctx->role === 'producer'/", $idx) === 0, 'S6 no route confines providers by comparing with the single slug "producer" (all portal-only roles are confined)');
$check(str_contains($src('app/src/RoleService.php'), 'function isPortalContext') && str_contains($idx, 'RoleService::isPortalContext($ctx)'), 'S6 portal confinement uses RoleService::isPortalContext');
$check(strpos($idx, '$__isPortalRole = FlxCoreHC\\RoleService::isPortalContext($ctx);') < strpos($idx, '// ---- Dashboard'), 'S6 portal flag is resolved BEFORE the dashboard route (custom portal roles cannot reach the tenant dashboard)');
$nr = $src('app/src/NotificationService.php');
$check(str_contains($nr, 'r.portal_only = 1'), 'S6 notification recipients exclude custom portal-only roles');

// ---- S7: migrations registered in both places
foreach (['124_healthcare_only.sql', '125_healthcare_cleanup.sql'] as $mig) {
    $check(str_contains($src('install.php'), $mig) && str_contains($src('database/manifest.php'), $mig), "S7 $mig registered in install.php and manifest.php");
}
$check(file_exists($ROOT . '/tools/http_smoke/run.sh') && file_exists($ROOT . '/tools/http_smoke/drive.py') && file_exists($ROOT . '/tests/agency/run.php'), 'S7 HTTP smoke + agency runner present');
$check(file_exists($ROOT . '/tests/authz/run.php') && file_exists($ROOT . '/tests/authz/test_authorization.php'), 'S7 DB-backed authz suite + isolated runner present (REQUIRES EXTERNAL RUNTIME VALIDATION)');

echo "\n" . ($fail ? "SOME FAILED\n" : "ALL PASS\n");
exit($fail ? 1 : 0);
