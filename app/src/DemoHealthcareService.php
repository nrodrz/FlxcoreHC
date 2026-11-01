<?php

declare(strict_types=1);

namespace FlxCoreHC;

use FlxCoreHC\Auth\AuthService;

/**
 * Self-contained HEALTHCARE demo — FIVE distinct organizations so every dashboard fills with
 * realistic, varied data: a billing company, a solo doctor's office, a self-managed group practice,
 * an IPA with multiple locations, and an anesthesiology group. Each is its own is_demo tenant, so
 * teardown removes it cleanly and never touches real data. Build is idempotent (tears down existing
 * demo tenants, then rebuilds a known dataset).
 */
final class DemoHealthcareService
{
    public const ORG_NAME    = 'Coastal Credentialing Partners';
    public const OWNER_EMAIL = 'demo-admin@coastalcred.example';
    public const OWNER_PASS  = 'coastal-demo-2026';
    /** Provider-portal demo logins (all use DEMO_PASSWORD). */
    public const PORTAL = [
        'ana.rivera@coastalcred.example',
        'luis.colon@coastalcred.example',
        'ana.rivera@bayamonmg.example',
    ];

    private const ORGS = [
        ['Coastal Credentialing Partners', 'demo-admin@coastalcred.example', 'coastal-demo-2026', 'billing_company', 'Sofia Marin'],
        ['Dr. Ramirez Family Practice',    'office@ramirezfp.example',       'ramirez-demo-2026', 'solo_practice',   'Carmen Ramirez'],
        ['Bayamon Medical Group',          'admin@bayamonmg.example',        'bayamon-demo-2026', 'medical_group',   'Jose Delgado'],
        ['Isla IPA',                       'admin@islaipa.example',          'isla-demo-2026',    'medical_group',   'Patricia Ortiz'],
        ['Anesthesia Associates PR',       'admin@anesthesiapr.example',     'anes-demo-2026',    'medical_group',   'Rafael Nieves'],
    ];

    public static function existingTenantId(): ?string
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT id FROM tenants WHERE is_demo = 1 AND vertical = 'healthcare' AND name = ? LIMIT 1");
        $st->execute([self::ORG_NAME]);
        $id = $st->fetchColumn();
        return $id !== false ? (string)$id : null;
    }

    private static function allDemoIds(): array
    {
        $names = array_column(self::ORGS, 0);
        $in = implode(',', array_fill(0, count($names), '?'));
        $st = Database::pdo()->prepare("SELECT id FROM tenants WHERE is_demo = 1 AND name IN ($in)");
        $st->execute($names);
        return array_column($st->fetchAll(), 'id');
    }

    public static function build(): array
    {
        foreach (self::allDemoIds() as $tid) { self::teardown($tid); }
        $first = null;
        $built = [];
        foreach (self::ORGS as [$name, $email, $pass, $shape, $ownerName]) {
            $tid = self::buildOrg($name, $email, $pass, $shape, $ownerName);
            $built[$name] = $tid;
            if ($first === null) $first = $tid;
        }
        // Newer features, seeded so they can be exercised in the demo without setup.
        try { self::seedFeatureDemos($built); } catch (\Throwable $e) { error_log('demo extras: ' . $e->getMessage()); }
        // Every healthcare tenant: auto-apply each provider's profession template so ALL demo
        // providers arrive with a populated credential checklist (not just the feature-demo orgs).
        try {
            $pdo = Database::pdo();
            foreach ($pdo->query("SELECT id, name, vertical, org_shape FROM tenants WHERE vertical = 'healthcare'")->fetchAll() as $t) {
                $ow = $pdo->prepare("SELECT id, full_name FROM users WHERE tenant_id = ? AND role = 'tenant_owner' LIMIT 1");
                $ow->execute([$t['id']]); $owner = $ow->fetch();
                if (!$owner) continue;
                $c = new TenantContext($t['id'], $owner['id'], 'tenant_owner', $t['name'], 'healthcare', $t['org_shape'], $owner['full_name'], false);
                $R = new Repository\ProducerRequirementRepository($c); $T = new TemplateService($c);
                foreach ((new Repository\HolderRepository($c))->all([], 'last_name', 1000) as $h) {
                    if (!empty($h['provider_type'])) { try { $T->applyByProviderType($h['id'], $h['provider_type'], $R); } catch (\Throwable $e) {} }
                }
            }
        } catch (\Throwable $e) { error_log('demo template sweep: ' . $e->getMessage()); }
        return [$first, self::OWNER_EMAIL, self::OWNER_PASS];
    }

    private static function buildOrg(string $name, string $email, string $pass, string $shape, string $ownerName): string
    {
        $auth = new AuthService();
        $reg = $auth->registerTenant($name, $shape, $ownerName, $email, $pass, $pass, allowPlatform: false);
        if (!is_array($reg)) { throw new \RuntimeException('Demo org failed: ' . (is_string($reg) ? $reg : 'unknown')); }
        $tid = $reg['tenant_id']; $uid = $reg['id'];
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE tenants SET is_demo = 1, status = 'active', operating_states = 'PR' WHERE id = ?")->execute([$tid]);
        $ctx = new TenantContext($tid, $uid, 'tenant_owner', $name, 'healthcare', $shape, $ownerName, false);
        $pdo->prepare("DELETE FROM credential_holders WHERE tenant_id = ? AND holder_type = 'organization' AND org_name = ?")
            ->execute([$tid, $name]);

        switch ($shape === 'solo_practice' ? 'solo' : $name) {
            case 'Coastal Credentialing Partners': self::buildBillingCompany($ctx); break;
            case 'solo':                            self::buildSoloOffice($ctx); break;
            case 'Bayamon Medical Group':           self::buildGroupPractice($ctx); break;
            case 'Isla IPA':                        self::buildIpa($ctx); break;
            case 'Anesthesia Associates PR':        self::buildAnesthesia($ctx); break;
        }
        return $tid;
    }

    private static function buildBillingCompany(TenantContext $ctx): void
    {
        $H = new Repository\HolderRepository($ctx);
        $gA = self::group($ctx, 'Grupo Medico del Norte', 'GMN');
        $gB = self::group($ctx, 'Clinica Bayamon', 'CB');
        $loc1 = self::location($ctx, 'San Juan Office', 'San Juan', 'PR');
        $loc2 = self::location($ctx, 'Caguas Office', 'Caguas', 'PR');
        $specs = [
            ['physician', 'Ana', 'Rivera', '1841293742', $gA, $loc1, 'active'],
            ['physician', 'Luis', 'Colon', '1275639481', $gA, $loc1, 'expired'],
            ['np', 'Maria', 'Santos', '1932048571', $gA, $loc2, 'expiring'],
            ['pa', 'Jorge', 'Vega', '1023456789', $gB, $loc2, 'active'],
            ['therapist', 'Elena', 'Cruz', '1112223334', $gB, $loc1, 'active'],
            ['physician', 'Pedro', 'Marin', '1445566778', null, $loc1, 'expiring'],
            ['nurse', 'Sonia', 'Diaz', '1556677889', $gB, $loc2, 'active'],
            ['behavioral', 'Rosa', 'Mendez', '1667788990', null, $loc2, 'active'],
        ];
        foreach ($specs as $i => [$pt, $fn, $ln, $npi, $g, $l, $state]) {
            $hid = self::provider($H, 'individual', $pt, ['first_name'=>$fn,'last_name'=>$ln,'npi'=>$npi,'email'=>strtolower("$fn.$ln@coastalcred.example")]);
            if ($g) GroupService::add($ctx, $hid, $g, null, true);
            if ($l) LocationService::affiliate($ctx, $hid, $l, null, true);
            self::credentialsFor($ctx, $hid, $pt, $state, $i);
        }
        $lab = self::provider($H, 'organization', 'lab', ['org_name'=>'Bahia Clinical Laboratory','npi'=>'1588293047']);
        self::cred($ctx, $lab, 'clia', 'CLIA Certificate', 'CMS', self::day(300), 'active', 'certification', true);
        self::screen($ctx, $lab, 'clear');
    }

    private static function buildSoloOffice(TenantContext $ctx): void
    {
        // The owner (Carmen, the Dr's spouse / office manager) already exists as tenant_owner from
        // registration — no need to add her again. Registration may also seed a self-holder for the
        // owner; remove any provider record that isn't one of the two we model below.
        Database::pdo()->prepare("DELETE FROM credential_holders WHERE tenant_id = ? AND holder_type = 'individual' AND (provider_type IS NULL OR provider_type = '')")
            ->execute([$ctx->tenantId]);
        $H = new Repository\HolderRepository($ctx);
        $dr = self::provider($H, 'individual', 'physician', ['first_name'=>'Miguel','last_name'=>'Ramirez','npi'=>'1700112233','email'=>'dr.ramirez@ramirezfp.example']);
        self::credentialsFor($ctx, $dr, 'physician', 'active', 0);
        $rn = self::provider($H, 'individual', 'nurse', ['first_name'=>'Lourdes','last_name'=>'Perez','npi'=>'1700445566','email'=>'lourdes@ramirezfp.example']);
        self::credentialsFor($ctx, $rn, 'nurse', 'expiring', 1);
        self::screen($ctx, $dr, 'clear');
        // A front-desk medical assistant on the team (does data entry), distinct from the owner.
        self::teamUser($ctx, 'Yolanda Ruiz', 'frontdesk@ramirezfp.example', 'regular');
    }

    private static function buildGroupPractice(TenantContext $ctx): void
    {
        $H = new Repository\HolderRepository($ctx);
        $loc = self::location($ctx, 'Bayamon Main', 'Bayamon', 'PR');
        $roster = [
            ['physician','Jose','Delgado','1801122334','active'],
            ['physician','Ivette','Morales','1802233445','expiring'],
            ['np','Hector','Nieves','1803344556','active'],
            ['nurse','Wanda','Torres','1804455667','active'],
            ['pa','Ramon','Ortiz','1805566778','expired'],
            ['therapist','Gladys','Rosario','1806677889','active'],
        ];
        foreach ($roster as $i => [$pt,$fn,$ln,$npi,$st]) {
            $hid = self::provider($H, 'individual', $pt, ['first_name'=>$fn,'last_name'=>$ln,'npi'=>$npi,'email'=>strtolower("$fn.$ln@bayamonmg.example")]);
            LocationService::affiliate($ctx, $hid, $loc, null, true);
            self::credentialsFor($ctx, $hid, $pt, $st, $i);
        }
    }

    private static function buildIpa(TenantContext $ctx): void
    {
        $H = new Repository\HolderRepository($ctx);
        $locs = [
            self::location($ctx, 'Ponce Center', 'Ponce', 'PR'),
            self::location($ctx, 'Mayaguez Clinic', 'Mayaguez', 'PR'),
            self::location($ctx, 'Arecibo Office', 'Arecibo', 'PR'),
        ];
        $grp = self::group($ctx, 'Primary Care Division', 'PCD');
        $grp2 = self::group($ctx, 'Specialty Division', 'SPD');
        $types = ['physician','physician','np','nurse','pa','physician','therapist','behavioral','np','nurse','physician','pharmacist'];
        $first = ['Carlos','Diana','Efrain','Fabiola','Gilberto','Hilda','Ismael','Julia','Kevin','Lucia','Manuel','Noelia'];
        $last  = ['Vazquez','Reyes','Cordero','Padilla','Soto','Figueroa','Rincon','Beltran','Malave','Quinones','Serrano','Acosta'];
        $states = ['active','active','expiring','active','expired','active','active','expiring','active','active','active','active'];
        for ($i = 0; $i < 12; $i++) {
            $hid = self::provider($H, 'individual', $types[$i], ['first_name'=>$first[$i],'last_name'=>$last[$i],'npi'=>'19'.str_pad((string)(100000+$i*137),8,'0',STR_PAD_LEFT),'email'=>strtolower($first[$i].'.'.$last[$i].'@islaipa.example')]);
            LocationService::affiliate($ctx, $hid, $locs[$i % 3], null, true);
            if ($i % 4 === 0) LocationService::affiliate($ctx, $hid, $locs[($i+1) % 3], null, false);
            GroupService::add($ctx, $hid, in_array($types[$i], ['physician','np','nurse'], true) ? $grp : $grp2, null, true);
            self::credentialsFor($ctx, $hid, $types[$i], $states[$i], $i);
        }
    }

    private static function buildAnesthesia(TenantContext $ctx): void
    {
        $H = new Repository\HolderRepository($ctx);
        $loc = self::location($ctx, 'Centro Medico OR Suites', 'San Juan', 'PR');
        $grp = self::group($ctx, 'Anesthesiology', 'ANES');
        $drs = [
            ['Rafael','Nieves','1900111222','active'],
            ['Sandra','Colon','1900222333','active'],
            ['Tomas','Rivera','1900333444','expiring'],
            ['Ursula','Medina','1900444555','active'],
            ['Victor','Guzman','1900555666','expired'],
        ];
        foreach ($drs as $i => [$fn,$ln,$npi,$st]) {
            $hid = self::provider($H, 'individual', 'physician', ['first_name'=>$fn,'last_name'=>$ln,'npi'=>$npi,'email'=>strtolower("$fn.$ln@anesthesiapr.example")]);
            LocationService::affiliate($ctx, $hid, $loc, 'Attending anesthesiologist', true);
            GroupService::add($ctx, $hid, $grp, 'Anesthesiologist', true);
            self::credentialsFor($ctx, $hid, 'physician', $st, $i);
        }
        foreach ([['Yara','Rios','1900666777'],['Zoe','Aponte','1900777888']] as $i => [$fn,$ln,$npi]) {
            $hid = self::provider($H, 'individual', 'nurse', ['first_name'=>$fn,'last_name'=>$ln,'npi'=>$npi,'email'=>strtolower("$fn.$ln@anesthesiapr.example")]);
            LocationService::affiliate($ctx, $hid, $loc, 'CRNA', true);
            GroupService::add($ctx, $hid, $grp, 'CRNA', true);
            self::credentialsFor($ctx, $hid, 'nurse', 'active', $i);
        }
    }

    /** Password used for the demo external share link (shown in the admin flash). */
    public const SHARE_PASSWORD = 'DemoShare2026';

    /** One password for every demo staff / portal login, so testing is simple. */
    public const DEMO_PASSWORD = 'FlxDemo2026!';

    /** Extra staff logins seeded into the billing company so every role can be tested. */
    private const DEMO_STAFF = [
        ['Marta Manager',     'manager@coastalcred.example',     'manager'],
        ['Sara Staff',        'staff@coastalcred.example',       'staff'],
        ['Carlos Coordinator','coordinator@coastalcred.example', 'agent'],
        ['Rita Readonly',     'readonly@coastalcred.example',    'read_only'],
    ];

    /**
     * Seeds the newer features so they are visible immediately in the demo: document requests
     * (one waiting, one accepted), an external share link, extra document kinds, notifications,
     * and a fully consented cross-organization link with one credential shared.
     */
    private static function seedFeatureDemos(array $built): void
    {
        $pdo = Database::pdo();
        $ctxFor = function (string $name) use ($built, $pdo): ?TenantContext {
            if (empty($built[$name])) return null;
            $tid = $built[$name];
            $t = $pdo->prepare('SELECT name, vertical, org_shape FROM tenants WHERE id = ?'); $t->execute([$tid]); $row = $t->fetch();
            $u = $pdo->prepare("SELECT id, full_name FROM users WHERE tenant_id = ? AND role = 'tenant_owner' LIMIT 1"); $u->execute([$tid]); $usr = $u->fetch();
            if (!$row || !$usr) return null;
            return new TenantContext($tid, $usr['id'], 'tenant_owner', $row['name'], $row['vertical'], $row['org_shape'], $usr['full_name'], false);
        };
        $A = $ctxFor('Coastal Credentialing Partners');
        $B = $ctxFor('Bayamon Medical Group');
        if (!$A) return;
        $HA = new Repository\HolderRepository($A);
        $ana = null; $luis = null;
        foreach ($HA->all([], 'last_name ASC', 500) as $h) {
            if (($h['first_name'] ?? '') === 'Ana')  $ana = $h;
            if (($h['first_name'] ?? '') === 'Luis') $luis = $h;
        }
        $CA = new Repository\CredentialRepository($A);

        if ($ana) {
            $svc = new DocumentRequestService($A);
            $creds = $CA->all(['holder_id' => $ana['id']], 'expiry_date ASC', 50);
            $lic = $creds[0]['id'] ?? null;
            $svc->create($ana['id'], $lic, 'malpractice', 'Please send the 2027 certificate of insurance.', self::day(14));
            [$ok, , $rid] = $svc->create($ana['id'], $lic, 'cv', 'Updated CV for payer enrollment.', null);
            if ($ok && $rid && $lic) {
                $did = self::docStub($A, (string)$lic, 'CV 2026', 'supporting');
                if ($did) { $svc->submit($rid, $did, 'Ana Rivera'); $svc->accept($rid); }
            }
            $ids = array_slice(array_column($creds, 'id'), 0, 3);
            if ($ids) { (new ExternalShareService($A))->create($ana['id'], 'payer', 'Triple-S Salud', $ids, true, 30, self::SHARE_PASSWORD, null); }
        }
        if ($luis) {
            $creds = $CA->all(['holder_id' => $luis['id']], 'expiry_date ASC', 50);
            if (!empty($creds[0]['id'])) {
                self::docStub($A, (string)$creds[0]['id'], 'Renewal letter', 'renewal');
                self::docStub($A, (string)$creds[0]['id'], 'Name change affidavit', 'supporting');
            }
        }
        if ($B && $ana && !empty($ana['npi'])) {
            $HB = new Repository\HolderRepository($B);
            $bAna = $HB->insert(['holder_type' => 'individual', 'provider_type' => 'physician',
                'first_name' => 'Ana', 'last_name' => 'Rivera', 'npi' => $ana['npi'],
                'email' => 'ana.rivera@bayamonmg.example', 'vertical' => 'healthcare', 'status' => 'active']);
            self::credentialsFor($B, $bAna, 'physician', 'active', 3);
            (new CrossTenantService($B))->requestLinks($bAna);
            $bidx = Crypto::blindIndex(preg_replace('/\D/', '', (string)$ana['npi']));
            foreach (CrossTenantService::pendingForProvider($bidx) as $pl) {
                CrossTenantService::providerConsent($pl['id'], $bidx, 'demo-provider', true);
            }
            $svcA = new CrossTenantService($A);
            foreach ($svcA->linksForTenant() as $l) {
                if ($l['status'] === 'pending_owner') $svcA->ownerDecide($l['id'], true);
            }
            $creds = $CA->all(['holder_id' => $ana['id']], 'expiry_date ASC', 50);
            foreach ($svcA->activeLinksForHolder($ana['id']) as $l) {
                if (!empty($creds[0]['id'])) $svcA->shareCredential((string)$creds[0]['id'], $l['link_id'], true);
            }
        }
        // --- Payers, program enrollment, OCS: a realistic mix so the compliance ladder shows every level ---
        try {
            $E = new EnrollmentService($A);
            $payerIds = []; foreach ($E->payers() as $p) { if ($p['short_code']) $payerIds[$p['short_code']] = $p['id']; }
            $all = $HA->all([], 'last_name ASC', 500); $i = 0;
            foreach ($all as $h) {
                if (($h['holder_type'] ?? '') !== 'individual') continue;
                $i++;
                // everyone: Triple-S participating; alternate MCS / First Medical / Menonita states
                if (isset($payerIds['SSS'])) $E->savePayer($h['id'], ['payer_id' => $payerIds['SSS'], 'status' => 'participating', 'effective_date' => self::day(-300), 'recredentialing_date' => self::day(365 + $i * 20)]);
                if (isset($payerIds['MCS'])) $E->savePayer($h['id'], ['payer_id' => $payerIds['MCS'], 'status' => ['credentialed','application_pending','recredentialing','denied'][$i % 4], 'effective_date' => self::day(-200), 'recredentialing_date' => self::day(30 + $i * 15)]);
                if ($i % 2 === 0 && isset($payerIds['FMHP'])) $E->savePayer($h['id'], ['payer_id' => $payerIds['FMHP'], 'status' => 'contracted', 'effective_date' => self::day(-100)]);
                if ($i % 3 === 0 && isset($payerIds['PSM'])) $E->savePayer($h['id'], ['payer_id' => $payerIds['PSM'], 'status' => 'under_review', 'application_date' => self::day(-20)]);
                // programs
                $E->saveProgram($h['id'], ['program' => 'medicaid', 'status' => $i % 5 === 0 ? 'revalidation_due' : 'enrolled', 'enrollment_id' => 'PRM' . (100000 + $i), 'effective_date' => self::day(-600), 'revalidation_date' => self::day($i % 5 === 0 ? 25 : 700), 'enrollment_type' => 'individual', 'locations_registered' => $i % 7 !== 0 ? 1 : 0]);
                if ($i % 2 === 1) $E->saveProgram($h['id'], ['program' => 'medicare', 'status' => 'enrolled', 'enrollment_id' => 'PT' . (200000 + $i), 'effective_date' => self::day(-500), 'revalidation_date' => self::day(900), 'mac_or_region' => 'First Coast (JN)']);
                // OCS: most attested, some registered only, one expired
                $E->saveOcs($h['id'], ['sicro_status' => $i % 4 === 0 ? 'registered' : ($i === 3 ? 'expired' : 'attested'), 'profile_id' => 'OCS-' . (5000 + $i), 'last_attestation' => self::day(-120), 'attestation_expires' => self::day($i === 3 ? -10 : 245)]);
                if ($i % 2 === 0) $HA->update($h['id'], ['telehealth_enabled' => 1]);
            }
        } catch (\Throwable $e) { error_log('demo enrollment: ' . $e->getMessage()); }

        // --- Logins for testing: one per role, plus provider portal accounts ---
        foreach (self::DEMO_STAFF as [$nm, $em, $role]) { self::teamUser($A, $nm, $em, $role); }
        if ($ana)  self::portalUser($A, $ana,  'ana.rivera@coastalcred.example');
        if ($luis) self::portalUser($A, $luis, 'luis.colon@coastalcred.example');
        if ($B) {
            $HB2 = new Repository\HolderRepository($B);
            foreach ($HB2->all([], 'last_name ASC', 500) as $h) {
                if (($h['first_name'] ?? '') === 'Ana') { self::portalUser($B, $h, 'ana.rivera@bayamonmg.example'); break; }
            }
        }
        foreach ([$A, $B] as $c) { if ($c) { try { NotificationService::sweep($c, true); } catch (\Throwable $e) {} } }
        // Auto-apply each provider's profession template so demo checklists arrive populated.
        foreach ([$A, $B] as $c) {
            if (!$c) continue;
            try {
                $R = new Repository\ProducerRequirementRepository($c); $T = new TemplateService($c);
                foreach ((new Repository\HolderRepository($c))->all([], 'last_name', 500) as $h) {
                    if (!empty($h['provider_type'])) { try { $T->applyByProviderType($h['id'], $h['provider_type'], $R); } catch (\Throwable $e) {} }
                }
            } catch (\Throwable $e) {}
        }
    }

    /** A provider portal login bound to their holder record (so /me and consent screens work). */
    private static function portalUser(TenantContext $ctx, array $holder, string $email): void
    {
        try {
            $name = trim(($holder['first_name'] ?? '') . ' ' . ($holder['last_name'] ?? '')) ?: 'Provider';
            Database::pdo()->prepare(
                "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, holder_id, created_at)
                 VALUES (?,?,?,?,?, 'producer', 'active', ?, ?)"
            )->execute([uuid4(), $ctx->tenantId, $email, password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID), $name, $holder['id'], utc_now()]);
        } catch (\Throwable $e) { /* duplicate email — ignore */ }
    }

    private static function credentialsFor(TenantContext $ctx, string $hid, string $pt, string $state, int $seed): void
    {
        $prescriber = in_array($pt, ['physician','np','pa','dentist'], true);
        $license = self::stateOf($state, 0, $seed);
        self::cred($ctx, $hid, 'state_medical_license', 'PR Professional License', 'PR Board of Medical Licensure', $license[0], $license[1], 'license', true);
        self::cred($ctx, $hid, 'npi', 'NPI', 'CMS', null, 'active', 'identifier');
        $mal = self::stateOf($state, 1, $seed);
        self::cred($ctx, $hid, 'malpractice', 'Malpractice Policy', 'The Doctors Company', $mal[0], $mal[1], 'insurance', $state !== 'expired');
        if ($prescriber) {
            $dea = self::stateOf($state, 2, $seed);
            self::cred($ctx, $hid, 'dea', 'DEA Registration', 'DEA', $dea[0], $dea[1], 'certification', false);
        }
        self::cred($ctx, $hid, 'caqh', 'CAQH ProView', 'CAQH', self::day(200 + $seed), 'active', 'certification');
        self::cred($ctx, $hid, 'cme', 'CME 2026', 'AMA', self::day(150 + $seed), 'active', 'training');
        self::screen($ctx, $hid, 'clear');
    }

    private static function stateOf(string $target, int $variant, int $seed): array
    {
        $roll = ($seed + $variant) % 3;
        if ($target === 'expired' && $variant === 0) return [self::day(-40 - $seed), 'expired'];
        if ($target === 'expired' && $roll === 0)    return [self::day(-20 - $seed), 'expired'];
        if ($target === 'expiring' && $variant <= 1) return [self::day(18 + $seed), 'expiring'];
        if ($roll === 1)                             return [self::day(25 + $seed), 'expiring'];
        return [self::day(300 + $seed * 5), 'active'];
    }

    private static function group(TenantContext $ctx, string $name, string $code): string
    {
        return (new Repository\GroupRepository($ctx))->insert(['name' => $name, 'code' => $code, 'status' => 'active']);
    }

    private static function location(TenantContext $ctx, string $name, string $city, string $state): string
    {
        return (new Repository\LocationRepository($ctx))->insert(['name' => $name, 'city' => $city, 'state' => $state, 'status' => 'active']);
    }

    private static function teamUser(TenantContext $ctx, string $name, string $email, string $role): void
    {
        try {
            Database::pdo()->prepare(
                "INSERT INTO users (id, tenant_id, email, password_hash, full_name, role, status, created_at)
                 VALUES (?,?,?,?,?,?, 'active', ?)"
            )->execute([uuid4(), $ctx->tenantId, $email, password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID), $name, $role, utc_now()]);
        } catch (\Throwable $e) {}
    }

    private static function screen(TenantContext $ctx, string $hid, string $result): void
    {
        try {
            Database::pdo()->prepare(
                "INSERT INTO exclusion_screenings (id, tenant_id, holder_id, source, screened_on, result, reference, screened_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([uuid4(), $ctx->tenantId, $hid, 'oig_leie', today_local(), $result, 'LEIE ' . date('Y-m'), 'Automated (demo)', utc_now()]);
        } catch (\Throwable $e) {}
    }

    private static function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    public static function teardown(?string $tid = null): bool
    {
        $tid = $tid ?? self::existingTenantId();
        if ($tid === null) return false;
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id' AND TABLE_NAME <> 'tenants'");
        $tables = array_column($stmt->fetchAll(), 'TABLE_NAME');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $t) {
                try { $pdo->prepare("DELETE FROM `$t` WHERE tenant_id = ?")->execute([$tid]); }
                catch (\Throwable $e) {}
            }
            $pdo->prepare("DELETE FROM tenants WHERE id = ? AND is_demo = 1")->execute([$tid]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return true;
    }

    public static function teardownAll(): bool
    {
        $ids = self::allDemoIds();
        foreach ($ids as $tid) { self::teardown($tid); }
        return $ids !== [];
    }

    private static function provider(Repository\HolderRepository $holders, string $holderType, string $providerType, array $in): string
    {
        return $holders->insert([
            'holder_type'   => $holderType,
            'provider_type' => $providerType,
            'first_name'    => $in['first_name'] ?? null,
            'last_name'     => $in['last_name'] ?? null,
            'org_name'      => $in['org_name'] ?? null,
            'npi'           => isset($in['npi']) ? Npi::fix((string) $in['npi']) : null,
            'email'         => $in['email'] ?? null,
            'vertical'      => 'healthcare',
            'status'        => 'active',
        ]);
    }

    private static function cred(TenantContext $ctx, string $holderId, string $type, string $name,
        string $body, ?string $expiry, string $status, string $category, bool $withDoc = false): void
    {
        $cid = (new Repository\CredentialRepository($ctx))->insert([
            'holder_id'       => $holderId,
            'credential_type' => $type,
            'name'            => $name,
            'issuing_body'    => $body,
            'issued_date'     => self::day(-400),
            'expiry_date'     => $expiry,
            'status'          => $status,
            'category'        => $category,
        ]);
        ComplianceEventService::record($ctx, 'credential_added', 'Credential added: ' . $name,
            ['holder_id' => $holderId, 'credential_id' => $cid, 'effective_on' => self::day(-400)]);
        if ($withDoc) {
            $did = self::docStub($ctx, $cid, $name);
            if ($did) {
                ComplianceEventService::record($ctx, 'document_uploaded', 'Document uploaded: ' . $name . '.pdf',
                    ['holder_id' => $holderId, 'credential_id' => $cid, 'document_id' => $did]);
                ComplianceEventService::record($ctx, 'document_verified', 'Document verified (primary source): ' . $name . '.pdf',
                    ['holder_id' => $holderId, 'credential_id' => $cid, 'document_id' => $did, 'effective_on' => self::day(-390),
                     'detail' => ['method' => 'primary_source']]);
            }
        }
    }

    private static function docStub(TenantContext $ctx, string $credentialId, string $name, string $kind = 'primary'): ?string
    {
        try {
            $dir = rtrim((string) config('docs_path', FLXCOREHC_APP . '/storage/docs'), '/') . '/' . $ctx->tenantId;
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $file = $dir . '/' . uuid4() . '.pdf';
            $stub = "%PDF-1.4\n% demo placeholder\n%%EOF";
            if (!EncryptedFile::writeBytes($file, $stub)) { @file_put_contents($file, $stub); }
            $bytes = strlen($stub);
            return (new Repository\DocumentRepository($ctx))->insert([
                'credential_id'       => $credentialId,
                'kind'                => $kind,
                'file_path'           => $file,
                'original_name'       => preg_replace('/[^A-Za-z0-9]+/', '-', $name) . '.pdf',
                'mime_type'           => 'application/pdf',
                'size_bytes'          => $bytes,
                'sha256'              => hash('sha256', $file),
                'uploaded_by'         => $ctx->userId,
                'verified_at'         => utc_now(),
                'verified_by'         => $ctx->userId,
                'verification_method' => 'primary_source',
            ]);
        } catch (\Throwable $e) { return null; }
    }
}
