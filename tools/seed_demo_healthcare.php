<?php
/**
 * seed_demo_healthcare.php — populate a healthcare (billing-company) tenant with realistic demo
 * data: five providers of different types, each with a plausible set of credentials at varying
 * expiry states (active, expiring soon, expired) so every dashboard and report has something to show.
 *
 * This is for DEMOS and TESTING, not production. It writes through the same repositories the app
 * uses, so encryption, tenant scoping, and audit behavior all apply exactly as in normal use.
 *
 * Usage (from the app root, after installing as a "Billing / credentialing company"):
 *     php tools/seed_demo_healthcare.php                # seeds the first healthcare tenant found
 *     php tools/seed_demo_healthcare.php <tenant_id>    # seeds a specific tenant
 *
 * Safe to run more than once: providers are matched by name and skipped if already present.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

require dirname(__DIR__) . '/app/bootstrap.php';

use FlxCoreHC\Database;
use FlxCoreHC\TenantContext;
use FlxCoreHC\Repository\HolderRepository;
use FlxCoreHC\Repository\CredentialRepository;

$pdo = Database::pdo();

// --- pick the tenant -------------------------------------------------------------------------
$tenantId = $argv[1] ?? null;
if ($tenantId === null) {
    $tenantId = $pdo->query("SELECT id FROM tenants WHERE vertical = 'healthcare' ORDER BY created_at LIMIT 1")
        ->fetchColumn() ?: null;
}
if (!$tenantId) {
    fwrite(STDERR, "No healthcare tenant found. Install as a \"Billing / credentialing company\" first,\n"
        . "or pass a tenant id: php tools/seed_demo_healthcare.php <tenant_id>\n");
    exit(1);
}
$trow = $pdo->prepare("SELECT id, name, vertical FROM tenants WHERE id = ?");
$trow->execute([$tenantId]);
$tenant = $trow->fetch();
if (!$tenant) { fwrite(STDERR, "Tenant $tenantId not found.\n"); exit(1); }
if ($tenant['vertical'] !== 'healthcare') {
    fwrite(STDERR, "Tenant \"{$tenant['name']}\" is {$tenant['vertical']}, not healthcare. Aborting to avoid polluting an insurance tenant.\n");
    exit(1);
}

echo "Seeding demo providers into \"{$tenant['name']}\" ({$tenantId})...\n";

// Build a minimal platform context so repositories accept our writes as this tenant.
$ctx = new TenantContext(
    tenantId: (string) $tenantId,
    userId: (string) ($pdo->query("SELECT id FROM users WHERE tenant_id = " . $pdo->quote($tenantId) . " ORDER BY created_at LIMIT 1")->fetchColumn() ?: '00000000-0000-0000-0000-000000000000'),
    role: 'tenant_owner',
    tenantName: (string) $tenant['name'],
    vertical: 'healthcare',
    orgShape: 'agency',
    userName: 'Demo Seeder',
    isPlatformAdmin: true,
);

$holders = new HolderRepository($ctx);
$creds   = new CredentialRepository($ctx);

/** Days from today as a YYYY-MM-DD date. */
function day(int $offset): string { return (new DateTimeImmutable('today'))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d'); }

/** Provider definitions: [display, holder_type, provider_type, [org_name|first,last], npi, credentials[]] */
$providers = [
    [
        'label' => 'Dr. Ana Rivera (physician)',
        'holder_type' => 'individual', 'provider_type' => 'physician',
        'first_name' => 'Ana', 'last_name' => 'Rivera', 'email' => 'ana.rivera@demo.test',
        'npi' => FlxCoreHC\Npi::fix('1841293742'),
        'credentials' => [
            ['state_medical_license', 'FL Medical License', 'Florida Board of Medicine', day(400)],
            ['npi', 'NPI', 'NPPES', null],
            ['dea', 'DEA Registration', 'US DEA', day(120)],
            ['board_cert', 'Board Certification — Internal Medicine', 'ABIM', day(600)],
            ['malpractice', 'Malpractice Policy', 'The Doctors Company', day(45)],   // expiring soon
            ['caqh', 'CAQH Attestation', 'CAQH ProView', day(20)],                  // expiring soon
        ],
    ],
    [
        'label' => 'Dr. Luis Colón (physician, PR)',
        'holder_type' => 'individual', 'provider_type' => 'physician',
        'first_name' => 'Luis', 'last_name' => 'Colon', 'email' => 'luis.colon@demo.test',
        'npi' => FlxCoreHC\Npi::fix('1275639481'),
        'credentials' => [
            ['pr_medical_license', 'PR Medical License', 'PR Board of Medical Licensure & Discipline', day(300)],
            ['npi', 'NPI', 'NPPES', null],
            ['dea', 'DEA Registration', 'US DEA', day(-15)],                        // expired
            ['pr_good_conduct', 'Certificate of Good Conduct', 'Policía de Puerto Rico', day(200)],
            ['malpractice', 'Malpractice Policy', 'MCS', day(250)],
        ],
    ],
    [
        'label' => 'Maria Santos, NP (nurse)',
        'holder_type' => 'individual', 'provider_type' => 'nurse',
        'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@demo.test',
        'npi' => FlxCoreHC\Npi::fix('1932048571'),
        'credentials' => [
            ['state_medical_license', 'FL APRN License', 'Florida Board of Nursing', day(500)],
            ['npi', 'NPI', 'NPPES', null],
            ['malpractice', 'Malpractice Policy', 'NSO', day(210)],
            ['cme', 'CE Hours', 'FL Board of Nursing', day(-40)],                   // expired
        ],
    ],
    [
        'label' => 'Bahia Clinical Laboratory (lab)',
        'holder_type' => 'organization', 'provider_type' => 'lab',
        'org_name' => 'Bahia Clinical Laboratory', 'npi' => FlxCoreHC\Npi::fix('1588293047'),
        'credentials' => [
            ['clia', 'CLIA Certificate of Compliance', 'CMS', day(320)],
            ['npi_type2', 'Organizational NPI', 'NPPES', null],
            ['ein', 'EIN / CP-575', 'IRS', null],
            ['lab_director_quals', 'Laboratory Director Qualifications', 'CMS 42 CFR 493', null],
            ['group_malpractice', 'Facility Liability Policy', 'Universal', day(35)],  // expiring soon
        ],
    ],
    [
        'label' => 'Costa Medical Group (group)',
        'holder_type' => 'organization', 'provider_type' => 'group',
        'org_name' => 'Costa Medical Group', 'npi' => FlxCoreHC\Npi::fix('1699384756'),
        'credentials' => [
            ['npi_type2', 'Organizational NPI', 'NPPES', null],
            ['ein', 'EIN / CP-575', 'IRS', null],
            ['group_malpractice', 'Group Malpractice Policy', 'The Doctors Company', day(280)],
            ['medicare_ptan', 'Medicare PTAN', 'CMS / MAC', null],
            ['business_license', 'Business License', 'FL DBPR', day(150)],
        ],
    ],
];

$madeHolders = 0; $madeCreds = 0; $skipped = 0;

foreach ($providers as $p) {
    // Skip if this provider already exists (idempotent). Every demo provider has a unique NPI, so we
    // dedup through the repository's blind-index lookup — which is the only correct way to match on an
    // encrypted column without the key. Organizations without an NPI fall back to an org_name match.
    $existingHolder = null;
    if (!empty($p['npi'])) {
        $found = $holders->findByNpi($p['npi']);
        if ($found) $existingHolder = $found['id'];
    } elseif (!empty($p['org_name'])) {
        $e = $pdo->prepare("SELECT id FROM credential_holders WHERE tenant_id = ? AND org_name = ?");
        $e->execute([$tenantId, $p['org_name']]);
        $existingHolder = $e->fetchColumn() ?: null;
    }

    if ($existingHolder) {
        echo "  · {$p['label']} — already present, skipping\n";
        $holderId = (string) $existingHolder;
        $skipped++;
    } else {
        $holderId = $holders->insert([
            'holder_type'  => $p['holder_type'],
            'provider_type'=> $p['provider_type'],
            'first_name'   => $p['first_name'] ?? null,
            'last_name'    => $p['last_name'] ?? null,
            'org_name'     => $p['org_name'] ?? null,
            'npi'          => $p['npi'] ?? null,
            'email'        => $p['email'] ?? null,
            'vertical'     => 'healthcare',
            'status'       => 'active',
        ]);
        if (!is_string($holderId) || $holderId === '') {
            echo "  ! {$p['label']} — could not create holder ({$holderId}), skipping its credentials\n";
            continue;
        }
        echo "  + {$p['label']}\n";
        $madeHolders++;
    }

    foreach ($p['credentials'] as [$type, $name, $body, $expiry]) {
        // Avoid duplicate credentials on re-run.
        $dc = $pdo->prepare("SELECT id FROM credentials WHERE tenant_id = ? AND holder_id = ? AND credential_type = ? AND name = ?");
        $dc->execute([$tenantId, $holderId, $type, $name]);
        if ($dc->fetchColumn()) continue;

        $status = 'active';
        if ($expiry !== null) {
            $d = new DateTimeImmutable($expiry);
            $now = new DateTimeImmutable('today');
            if ($d < $now)                          $status = 'expired';
            elseif ($d <= $now->modify('+60 days'))  $status = 'expiring';
        }
        $creds->insert([
            'holder_id'       => $holderId,
            'credential_type' => $type,
            'name'            => $name,
            'issuing_body'    => $body,
            'expiry_date'     => $expiry,
            'status'          => $status,
        ]);
        $madeCreds++;
    }
}

echo "\nDone. {$madeHolders} provider(s) created, {$madeCreds} credential(s) added, {$skipped} already present.\n";
echo "Sign in and open Providers to see them; the dashboard will show the expiring and expired items.\n";
