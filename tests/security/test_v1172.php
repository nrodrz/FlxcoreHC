<?php
/**
 * v1.172.0 — behavioural tests (DB-level; each asserts an OUTCOME, not source text) for:
 *   P   platform console two-factor policy (a setting, off on a new installation)
 *   Q   QR code encoder (matrices compared with an independent reference implementation)
 *   E   e-mailed codes as a second factor
 *   R   password reset by e-mail
 *   O   guided onboarding (person / organization) — capability and stored fields
 *   C   no inline event handlers in views (the Content-Security-Policy blocks them)  [source guard, labelled as such]
 * Runs after test_v1171.php in the same isolated database (flx_security) through tests/security/run.php.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use FlxCoreHC\Auth\AuthService;
use FlxCoreHC\Auth\Totp;
use FlxCoreHC\Auth\RateLimiter;
use FlxCoreHC\SettingsService;
use FlxCoreHC\SuperadminService;
use FlxCoreHC\QrCode;
use FlxCoreHC\MailService;

$fail2 = false;
// Note: three calls below are prefixed with @ because session_regenerate_id() warns when there is no HTTP session (CLI runner only).
$ck = function (bool $ok, string $label) use (&$fail2): void { echo ($ok ? 'PASS' : 'FAIL') . " — $label\n"; if (!$ok) $fail2 = true; };
$pdo = FlxCoreHC\Database::pdo(); $now = utc_now(); $ROOT = dirname(__DIR__, 2);
$one = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();
$auth = new AuthService(); $lim = new RateLimiter();

// ---- P: platform console two-factor policy --------------------------------------------------------------------------
$pdo->exec("DELETE FROM platform_settings WHERE skey IN ('platform_require_2fa','mfa_email_allowed')"); SettingsService::put([]);
$ck(AuthService::platformRequires2fa() === false, 'P a new installation does NOT require two-factor for the platform console (setting absent = off)');
$ck(AuthService::emailCodesAllowed() === true, 'P e-mailed codes are allowed by default');
[$tP, $oP] = $mkTenant('v172pol'); $svcP = new SuperadminService($ctx($tP, $oP, 'tenant_owner', true));
$uNo = $mkUser($tP, 'manager', 'active', 'all', null, 0, 0); $uYes = $mkUser($tP, 'manager', 'active', 'all', null, 0, 1);
[$okOff] = $svcP->setPlatformAdmin($tP, $uNo, true);
$ck($okOff === true && (int) $one('SELECT platform_admin FROM users WHERE id = ?', [$uNo]) === 1, 'P while the requirement is off, a user without two-factor can be made a platform administrator');
$svcP->setPlatformAdmin($tP, $uNo, false);
SettingsService::put(['platform_require_2fa' => '1']);
[$okOn, $mOn] = $svcP->setPlatformAdmin($tP, $uNo, true); [$okOn2] = $svcP->setPlatformAdmin($tP, $uYes, true);
$ck(AuthService::platformRequires2fa() === true && $okOn === false && stripos($mOn, 'two-factor') !== false && $okOn2 === true,
    'P once the requirement is on, only a user who already uses two-factor can be made a platform administrator');
// migration 133: an installation that already has a 2FA platform administrator keeps the requirement ON; one without keeps it off
$sql133 = preg_replace('~^\s*--.*$~m', '', (string) file_get_contents($ROOT . '/database/migrations/133_signin_options.sql'));
$ins133 = null; foreach (array_filter(array_map('trim', preg_split('~;\s*[\r\n]+~', $sql133))) as $st) if (str_starts_with($st, 'INSERT INTO platform_settings')) $ins133 = $st;
$pdo->exec("DELETE FROM platform_settings WHERE skey = 'platform_require_2fa'");
$pdo->exec("UPDATE users SET platform_admin = 0"); $pdo->exec((string) $ins133); SettingsService::put([]);
$noAdmins = AuthService::platformRequires2fa();
$pdo->prepare("UPDATE users SET platform_admin = 1 WHERE id = ?")->execute([$uNo]); $pdo->exec((string) $ins133); SettingsService::put([]);
$adminNo2fa = AuthService::platformRequires2fa();
$pdo->prepare("UPDATE users SET platform_admin = 1 WHERE id = ?")->execute([$uYes]); $pdo->exec((string) $ins133); SettingsService::put([]);
$admin2fa = AuthService::platformRequires2fa();
$ck($ins133 !== null && $noAdmins === false && $adminNo2fa === false && $admin2fa === true,
    'P migration 133 keeps the requirement ON only where a platform administrator already uses two-factor (upgrade), and leaves it off on a new install');
$pdo->exec("UPDATE users SET platform_admin = 0"); $pdo->exec("DELETE FROM platform_settings WHERE skey = 'platform_require_2fa'"); SettingsService::put([]);

// ---- Q: QR code ------------------------------------------------------------------------------------------------------
// Reference matrices: python "qrcode" library (independent implementation), byte mode, level M, the mask stated here.
$golden = [
    ['otpauth://totp/owner%40example.com?secret=ZPDS56CUZTWGIZSAZDJBYP3N3UDE6QWD&issuer=FlxCoreHC', 2, 6, 'b856d9e584528b7df0da8a757815f18e4576e8e2a17dc67cca61e81d24989226'],
    ['otpauth://totp/mar%C3%ADa.jos%C3%A9%2Btest%40clinica-ejemplo.pr?secret=ABCDEFGHABCDEFGHABCDEFGHABCDEFGH&issuer=Cl%C3%ADnica%20Ejemplo%20PR', 5, 8, '4b12fc4cad2c609f284a19b750ec053e3e6cbf26beb75bfde491f61c29c22c30'],
    ['FLXCOREHC', 0, 1, '94b92c2be93d3cb7fccf3a3be572edd4ea15435580103c056f9ff533ea47dbbe'],
    [str_repeat('x', 122), 7, 7, 'd8ac890813e7069b89c0640dc9b50fd99e99d920d896c5a747028a1f8ed69103'],
    [str_repeat('y', 331), 3, 13, '29ec4eeedbc1db9383fa74221bdbb42c52d29950ba2cac01804ba09d51adea9f'],
    [str_repeat('z', 666), 1, 20, 'fa9035c46dd0773a7c158204d82edd5c35a5a2b806c34051702c43588ce146cd'],
];
$badQ = [];
foreach ($golden as [$txt, $mask, $ver, $sha]) {
    $q = QrCode::encode($txt, $mask);
    $rows = implode("\n", array_map(fn($r) => implode('', array_map(fn($b) => $b ? '1' : '0', $r)), $q['modules'] ?? []));
    if (($q['version'] ?? 0) !== $ver || hash('sha256', $rows) !== $sha) $badQ[] = strlen($txt) . ' bytes';
}
$ck(!$badQ, 'Q the QR encoder produces exactly the reference matrices for versions 1, 6, 7, 8, 13 and 20 (differences: ' . json_encode($badQ) . ')');
$enrol = AuthService::beginTotpEnrolment('owner@example.com', 'FlxCoreHC'); $svg = QrCode::svg($enrol['uri']);
$auto = QrCode::encode($enrol['uri']);
$ck(str_starts_with($svg, '<svg ') && str_contains($svg, 'fill="#ffffff"') && !preg_match('~https?://(?!www\.w3\.org)~', $svg) && !str_contains($svg, '<script') && !str_contains($svg, $enrol['secret'])
    && is_array($auto) && $auto['size'] === 17 + 4 * $auto['version'] && QrCode::encode(str_repeat('x', 667)) === null && QrCode::svg(str_repeat('x', 667)) === '',
    'Q the enrolment QR is a self-contained inline SVG (no external address, no script, secret not in clear text); oversized input is refused');

// ---- E: e-mailed codes ------------------------------------------------------------------------------------------------
[$tE, $oE] = $mkTenant('v172mail'); $uE = $mkUser($tE, 'staff');
$c1 = AuthService::issueEmailCode($uE, 'login');
$stored = (string) $one("SELECT code_hash FROM auth_email_codes WHERE user_id = ? AND purpose = 'login'", [$uE]);
$ck(preg_match('/^\d{6}$/', $c1) === 1 && strlen($stored) === 64 && !str_contains($stored, $c1) && (int) $one('SELECT COUNT(*) FROM auth_email_codes WHERE user_id = ?', [$uE]) === 1,
    'E a code is 6 digits and only its keyed hash is stored');
$ck(AuthService::checkEmailCode($uE, 'enrol', $c1) === false && AuthService::checkEmailCode($mkUser($tE, 'staff'), 'login', $c1) === false,
    'E a code does not work for another purpose or another user');
$wrong = $c1 === '000000' ? '111111' : '000000';
$ck(AuthService::checkEmailCode($uE, 'login', $wrong) === false && AuthService::checkEmailCode($uE, 'login', $c1) === true && AuthService::checkEmailCode($uE, 'login', $c1) === false,
    'E a wrong code is refused, the right code works once, and a used code is refused');
$c2 = AuthService::issueEmailCode($uE, 'login'); $c3 = AuthService::issueEmailCode($uE, 'login');
$ck(AuthService::checkEmailCode($uE, 'login', $c2) === ($c2 === $c3) && AuthService::checkEmailCode($uE, 'login', $c3) === true, 'E asking for a new code stops the earlier one from working');
$c4 = AuthService::issueEmailCode($uE, 'login'); $w4 = $c4 === '999999' ? '888888' : '999999';
for ($i = 0; $i < 5; $i++) AuthService::checkEmailCode($uE, 'login', $w4);
$ck(AuthService::checkEmailCode($uE, 'login', $c4) === false, 'E after 5 wrong tries the code is dead, even when the right one is then entered');
$c5 = AuthService::issueEmailCode($uE, 'login');
$pdo->prepare("UPDATE auth_email_codes SET expires_at = ? WHERE user_id = ? AND purpose = 'login'")->execute([gmdate('Y-m-d H:i:s', time() - 5), $uE]);
$ck(AuthService::checkEmailCode($uE, 'login', $c5) === false, 'E an expired code is refused');
// enrolment and the second step at sign-in
$cE = AuthService::issueEmailCode($uE, 'enrol');
[$eBad] = AuthService::confirmEmailMfa($uE, $cE === '123456' ? '654321' : '123456');
[$eOk, , $bk] = AuthService::confirmEmailMfa($uE, $cE);
$rowE = $pdo->query("SELECT totp_enabled, mfa_method, totp_secret FROM users WHERE id = " . $pdo->quote($uE))->fetch();
$ck($eBad === false && $eOk === true && (int) $rowE['totp_enabled'] === 1 && $rowE['mfa_method'] === 'email' && $rowE['totp_secret'] === null && count($bk) === 10
    && AuthService::secondFactorRequired($rowE) && AuthService::mfaMethodFor($uE) === 'email',
    'E confirming the enrolment code turns two-factor on with the e-mail method and issues 10 backup codes; a wrong code does not');
$emE = (string) $one('SELECT email FROM users WHERE id = ?', [$uE]);
$_SERVER['REMOTE_ADDR'] = '203.0.113.172';
$resE = $auth->attemptLogin($emE, 'OldPass123456');
$ck(is_array($resE) && AuthService::secondFactorRequired($resE) && AuthService::mfaMethod($resE) === 'email', 'E a correct password for an e-mail-2FA account still needs the second step (no session yet)');
$cL = AuthService::issueEmailCode($uE, 'login'); unset($_SESSION['auth']);
[$l0] = $auth->completeTwoFactor($uE, $cL === '222222' ? '333333' : '222222'); $noSess = empty($_SESSION['auth']);
[$l1] = @$auth->completeTwoFactor($uE, $cL); $sess = ($_SESSION['auth']['user_id'] ?? '') === $uE;
unset($_SESSION['auth']); [$l2] = $auth->completeTwoFactor($uE, $cL);
$ck($l0 === false && $noSess && $l1 === true && $sess && $l2 === false && empty($_SESSION['auth']),
    'E sign-in completes only with the current e-mailed code; a wrong or replayed code gives no session');
[$l3] = @$auth->completeTwoFactor($uE, $bk[0]); $s3 = ($_SESSION['auth']['user_id'] ?? '') === $uE; unset($_SESSION['auth']);
[$l4] = $auth->completeTwoFactor($uE, $bk[0]);
$ck($l3 === true && $s3 && $l4 === false, 'E a backup code signs in once when the mailbox is unreachable');
[$aOn] = AuthService::confirmTotp($uE, Totp::generateSecret(), '000000');
$sec = Totp::generateSecret(); [$aOn2, $mOn2] = AuthService::confirmTotp($uE, $sec, Totp::codeAt($sec, (int) floor(time() / 30)));
$ck($aOn === false && $aOn2 === false && str_contains($mOn2, 'already on') && $one('SELECT mfa_method FROM users WHERE id = ?', [$uE]) === 'email',
    'E while e-mail two-factor is on, enrolling an authenticator is refused (turn it off first): the method cannot be swapped silently');
[$d0] = AuthService::disableTotp($uE, '000000'); $cD = AuthService::issueEmailCode($uE, 'disable'); $cLg = AuthService::issueEmailCode($uE, 'login');
[$d1] = AuthService::disableTotp($uE, $cLg); [$d2] = AuthService::disableTotp($uE, $cD);
$ck($d0 === false && $d1 === false && $d2 === true && (int) $one('SELECT totp_enabled FROM users WHERE id = ?', [$uE]) === 0 && $one('SELECT mfa_method FROM users WHERE id = ?', [$uE]) === 'totp'
    && (int) $one('SELECT COUNT(*) FROM auth_email_codes WHERE user_id = ?', [$uE]) === 0,
    'E turning e-mail two-factor off needs a code issued for that purpose (a sign-in code does not do it); leftover codes are removed');
// the reverse swap: with an authenticator on, e-mail codes cannot take over without "turn off" first
$secT = Totp::generateSecret(); $stepT = (int) floor(time() / 30); [$tOn] = AuthService::confirmTotp($uE, $secT, Totp::codeAt($secT, $stepT));
$cX = AuthService::issueEmailCode($uE, 'enrol'); [$xOk, $xMsg] = AuthService::confirmEmailMfa($uE, $cX);
$rowT = $pdo->query("SELECT mfa_method, totp_secret FROM users WHERE id = " . $pdo->quote($uE))->fetch();
[$tOff] = AuthService::disableTotp($uE, Totp::codeAt($secT, $stepT + 1));
$ck($tOn === true && $xOk === false && str_contains($xMsg, 'already on') && $rowT['mfa_method'] === 'totp' && !empty($rowT['totp_secret']) && $tOff === true,
    'E while an authenticator is on, a valid e-mail enrolment code cannot replace it (method and secret unchanged)');
// policy: e-mail codes can be disallowed; nothing is sent without working outbound mail; sending is rate-limited
SettingsService::put(['mfa_email_allowed' => '0']); $cP = AuthService::issueEmailCode($uE, 'enrol'); [$pOk] = AuthService::confirmEmailMfa($uE, $cP);
SettingsService::put(['mfa_email_allowed' => '1']);
$ck($pOk === false && (int) $one('SELECT totp_enabled FROM users WHERE id = ?', [$uE]) === 0, 'E when e-mailed codes are not allowed, enrolment is refused even with a valid code');
SettingsService::put(['mail_enabled' => '0']); $before = (int) $one('SELECT COUNT(*) FROM email_outbox');
[$sOk, $sMsg] = AuthService::sendEmailCode($uE, 'enrol');
$ck(MailService::canSendFor($tE) === false && $sOk === false && str_contains($sMsg, 'not set up') && (int) $one('SELECT COUNT(*) FROM email_outbox') === $before,
    'E with outbound e-mail off, no code is issued or queued and the user is told why');
// a delivery that fails leaves no readable code behind (transport: SMTP on a closed port; the connection errors it logs are expected, so the log is muted here)
$prevLog = ini_set('error_log', '/dev/null');
SettingsService::put(['mail_enabled' => '1', 'mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_secure' => 'none', 'smtp_user' => '', 'mail_from' => 'no-reply@sec.test']);
$lim->clear('otp_send|' . $uE);
[$fOk] = AuthService::sendEmailCode($uE, 'enrol');
$ob = $pdo->query("SELECT status, body, category FROM email_outbox ORDER BY created_at DESC, id DESC LIMIT 1")->fetch();
$ck($fOk === false && $ob['category'] === 'security' && $ob['status'] === 'failed' && !preg_match('/\b\d{6}\b/', (string) $ob['body']),
    'E a security message that cannot be delivered is marked failed and its content is removed (no code left readable in the outbox)');
[$f1, $f2] = MailService::flush(50);
$ck((string) $one("SELECT status FROM email_outbox WHERE category = 'security' ORDER BY created_at DESC LIMIT 1") === 'failed', 'E a failed security message is never retried by the outbox');
$lim->clear('otp_send|' . $uE); $sent = 0; for ($i = 0; $i < 7; $i++) { [$x, $xm] = AuthService::sendEmailCode($uE, 'enrol'); if (!str_contains($xm, 'Too many')) $sent++; }
$ck($sent === 5, "E at most 5 codes can be requested per 15 minutes for one account (attempted: $sent)");
SettingsService::put(['mail_enabled' => '0']); $lim->clear('otp_send|' . $uE); ini_set('error_log', (string) $prevLog);
$ck(AuthService::maskEmail('nina@example.com') === 'n***@example.com' && AuthService::maskEmail('') === '***', 'E the address shown on the verification page is masked');

// ---- R: password reset by e-mail --------------------------------------------------------------------------------------
[$tR, $oR] = $mkTenant('v172reset'); $emR = 'o-v172reset@sec.test';
$ck(AuthService::createPasswordResets('nobody-here@sec.test') === [] && AuthService::createPasswordResets('not-an-email') === [] && (int) $one('SELECT COUNT(*) FROM password_resets') === 0,
    'R an unknown or malformed address creates no reset link');
$accts = AuthService::createPasswordResets($emR); $tok = (string) ($accts[0]['token'] ?? '');
$hashR = (string) $one('SELECT token_hash FROM password_resets WHERE user_id = ?', [$oR]);
$ck(count($accts) === 1 && preg_match('/^[a-f0-9]{64}$/', $tok) === 1 && $hashR === hash('sha256', $tok) && $hashR !== $tok
    && (int) $one("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'password_reset_requested'", [$oR]) === 1,
    'R a request creates one single-use link; only the SHA-256 hash of its token is stored; the request is audited');
$ck(AuthService::findPasswordReset($tok) !== null && AuthService::findPasswordReset(str_repeat('a', 64)) === null && AuthService::findPasswordReset('x') === null, 'R a valid token resolves to its account; a guessed token does not');
$accts2 = AuthService::createPasswordResets($emR); $tok2 = (string) $accts2[0]['token'];
$ck(AuthService::findPasswordReset($tok) === null && AuthService::findPasswordReset($tok2) !== null && (int) $one('SELECT COUNT(*) FROM password_resets WHERE user_id = ?', [$oR]) === 1,
    'R a new request replaces the earlier link (the old one stops working)');
$oldHash = (string) $one('SELECT password_hash FROM users WHERE id = ?', [$oR]);
[$r1, $m1] = AuthService::completePasswordReset($tok2, 'short', 'short'); [$r2, $m2] = AuthService::completePasswordReset($tok2, 'NewPass-123456', 'Different-123456');
$ck($r1 === false && $r2 === false && (string) $one('SELECT password_hash FROM users WHERE id = ?', [$oR]) === $oldHash && AuthService::findPasswordReset($tok2) !== null,
    'R a too-short or mismatched new password is refused, the password is unchanged and the link is still usable');
// a live session of the account, to prove the reset ends it
$uRow = $pdo->query("SELECT u.*, t.name AS tenant_name, t.vertical, t.org_shape FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.id = " . $pdo->quote($oR))->fetch();
@$auth->establishSession($uRow); $alive = AuthService::revalidateSession();
[$r3, $m3] = AuthService::completePasswordReset($tok2, 'NewPass-123456', 'NewPass-123456');
$dead = AuthService::revalidateSession() === false; unset($_SESSION['auth']);
[$r4] = AuthService::completePasswordReset($tok2, 'Another-123456', 'Another-123456');
$newHash = (string) $one('SELECT password_hash FROM users WHERE id = ?', [$oR]);
$ck($alive === true && $r3 === true && $dead && $r4 === false && password_verify('NewPass-123456', $newHash) && str_starts_with($newHash, '$argon2id$')
    && (int) $one("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'password_reset_completed'", [$oR]) === 1,
    'R the link sets the new password (Argon2id) exactly once, ends the open sessions of the account, and is audited');
$lim->clear($emR . '|203.0.113.172'); $lim->clear('acct|' . $emR);
$ck(is_string($auth->attemptLogin($emR, 'Pass123456789')) && is_array($auth->attemptLogin($emR, 'NewPass-123456')), 'R the old password no longer signs in; the new one does');
// expiry, inactive account, and two-factor is NOT bypassed
$tok3 = (string) AuthService::createPasswordResets($emR)[0]['token'];
$pdo->prepare('UPDATE password_resets SET expires_at = ? WHERE user_id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 5), $oR]);
[$r5] = AuthService::completePasswordReset($tok3, 'Expired-123456', 'Expired-123456');
$ck(AuthService::findPasswordReset($tok3) === null && $r5 === false && password_verify('NewPass-123456', (string) $one('SELECT password_hash FROM users WHERE id = ?', [$oR])), 'R an expired link does nothing');
$tok4 = (string) AuthService::createPasswordResets($emR)[0]['token'];
$pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$oR]);
$susp = AuthService::findPasswordReset($tok4) === null && AuthService::createPasswordResets($emR) === [];
$pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$oR]);
$ck($susp, 'R a suspended account cannot use a link it already holds, and cannot request a new one');
$secR = Totp::generateSecret(); AuthService::confirmTotp($oR, $secR, Totp::codeAt($secR, (int) floor(time() / 30)));
$tok5 = (string) AuthService::createPasswordResets($emR)[0]['token']; unset($_SESSION['auth']);
[$r6] = AuthService::completePasswordReset($tok5, 'After2fa-123456', 'After2fa-123456');
$lim->clear($emR . '|203.0.113.172'); $lim->clear('acct|' . $emR);
$after = $auth->attemptLogin($emR, 'After2fa-123456');
$ck($r6 === true && empty($_SESSION['auth']) && is_array($after) && AuthService::secondFactorRequired($after) && (int) $one('SELECT totp_enabled FROM users WHERE id = ?', [$oR]) === 1,
    'R a reset does not sign anyone in and does not switch two-factor off: the next sign-in still needs the second step');
// one address in two organizations: one link per account, each tied to its own organization
[$tR2, $oR2] = $mkTenant('v172reset2');
$pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$emR, $oR2]);
$multi = AuthService::createPasswordResets($emR);
$own = AuthService::findPasswordReset((string) $multi[0]['token']); $own2 = AuthService::findPasswordReset((string) $multi[1]['token']);
$ck(count($multi) === 2 && $own['tenant_id'] !== $own2['tenant_id'] && in_array($own['user_id'], [$oR, $oR2], true) && $own['user_id'] !== $own2['user_id'],
    'R an address used in two organizations gets one link per account; each link changes only its own account');
foreach (['203.0.113.172'] as $ip) { $lim->clear($emR . '|' . $ip); $lim->clear('ip|' . $ip); } $lim->clear('acct|' . $emR); unset($_SERVER['REMOTE_ADDR'], $_SESSION['auth']);

// ---- O: guided onboarding — what is stored per record type ------------------------------------------------------------
$ck(FlxCoreHC\HolderInput::orgTypes() === ['lab', 'clinic', 'group'] && !array_intersect(FlxCoreHC\HolderInput::individualTypes(), FlxCoreHC\HolderInput::orgTypes())
    && in_array('physician', FlxCoreHC\HolderInput::individualTypes(), true),
    'O person types and organization types are two separate lists (an organization is never offered "Physician")');
[, $eOrgPt] = FlxCoreHC\HolderInput::providerType(['provider_type' => 'physician'], 'organization');
[$sIndPt, $vIndPt] = FlxCoreHC\HolderInput::providerType(['provider_type' => 'lab'], 'individual');
$ck($eOrgPt === null && $sIndPt === false && $vIndPt === null, 'O a person type is not accepted for an organization record, nor an organization type for a person');

// the shared parser keeps the two kinds of record apart even if a request carries the other kind's fields
$cH = $ctx($tP, $oP, 'tenant_owner', false);
$ckPost = ['org_name' => 'Org X', 'first_name' => 'P', 'last_name' => 'Q', 'birth_month' => '4', 'role_title' => 'Dir', 'middle_name' => 'M', 'gender' => 'female', 'primary_specialty' => 'Lab', 'provider_type' => 'lab'];
[$fo, $eo] = FlxCoreHC\HolderInput::fromPost($cH, $ckPost, 'organization', true);
[$fi, $ei] = FlxCoreHC\HolderInput::fromPost($cH, ['first_name' => 'P', 'last_name' => 'Q', 'org_name' => 'Org X', 'provider_type' => 'lab', 'birth_month' => '4'], 'individual', true);
$ck($eo === null && ($fo['org_name'] ?? '') === 'Org X' && !array_key_exists('first_name', $fo) && !array_key_exists('last_name', $fo) && !array_key_exists('birth_month', $fo) && !array_key_exists('role_title', $fo) && !array_key_exists('middle_name', $fo) && !array_key_exists('gender', $fo) && ($fo['provider_type'] ?? '') === 'lab' && ($fo['primary_specialty'] ?? '') === 'Lab',
    'O an organization POST that also carries person fields stores only the organization fields');
$ck($ei === null && ($fi['first_name'] ?? '') === 'P' && !array_key_exists('org_name', $fi) && !array_key_exists('provider_type', $fi) && ($fi['birth_month'] ?? 0) === 4,
    'O a person POST that also carries an organization name or organization type stores only the person fields');

// ---- H: Database health does not warn about tables that are global on purpose ----------------------------------------------------
$hl = FlxCoreHC\SchemaDoctor::health();
$scope = null; foreach ($hl['checks'] as $hc) { if ($hc['label'] === 'Tenant scoping') $scope = $hc; }
$ck($scope !== null && $scope['state'] === 'ok', 'H Database health: "Tenant scoping" is OK on a complete schema (auth_email_codes and requirement_sources are global by design)', json_encode($scope));
$dd = []; foreach (FlxCoreHC\SchemaDoctor::dictionary() as $dr) $dd[$dr['table']] = $dr;
$ck(isset($dd['password_resets']) && $dd['password_resets']['tenant_scoped'] === true && isset($dd['auth_email_codes']) && $dd['auth_email_codes']['tenant_expected'] === false,
    'H password_resets stays tenant-scoped (it still carries tenant_id); only keyed-by-user and catalogue tables are exempt');

// ---- C: views carry no inline event handlers (the CSP blocks them, so they would silently do nothing) — SOURCE GUARD ----
$inline = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app/views', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if ($f->isFile() && preg_match_all('/\son[a-z]+\s*=\s*"/i', (string) file_get_contents($f->getPathname()), $mm)) $inline[] = str_replace($ROOT . '/', '', $f->getPathname()) . ' ×' . count($mm[0]); }
$ck(!$inline, 'C [source guard] no view uses an inline event handler such as onclick= / onchange= (found: ' . json_encode($inline) . ')');

echo $fail2 ? "\nSOME FAILED\n" : "\nALL PASS\n";
return $fail2 ? 1 : 0;
