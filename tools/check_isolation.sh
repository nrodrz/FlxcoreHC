#!/usr/bin/env bash
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
# CI backstop (audit H1, hardened in v1.171.2 after review finding B-7): every tenant-table query must go
# through the repository layer. Fails when:
#   1. Database::pdo() is used in app/ outside the sanctioned files below;
#   2. an allow-listed file no longer exists (stale entries make the list meaningless);
#   3. the front controller (index.php) gains MORE raw Database::pdo() calls than the recorded baseline
#      (tools/isolation_baseline.txt — known debt, to be paid down; lower the number when you remove calls).
set -euo pipefail
cd "$(dirname "$0")/.."

# Files allowed to call Database::pdo() directly (each one scopes its queries by tenant; reviewed):
ALLOW_REGEX='app/src/Repository/|app/bootstrap\.php|app/src/Database\.php|app/src/Auth/AuthService\.php|app/src/Auth/RateLimiter\.php|app/src/AccessLog\.php|app/src/Crypto\.php|app/src/SuperadminService\.php|app/src/TeamService\.php|app/src/PlanService\.php|app/src/BillingService\.php|app/src/OrgService\.php|app/src/Migrator\.php|app/src/CatalogService\.php|app/src/TrainingLibraryService\.php|app/src/TemplateService\.php|app/src/ShareLinkService\.php|app/src/Authz\.php|app/src/RoleService\.php|app/src/Capabilities\.php|app/src/NpiService\.php|app/src/LeieService\.php|app/src/SamService\.php|app/src/MedicaidExclusionService\.php|app/src/AssignmentService\.php|app/src/ConsentService\.php|app/src/SupportAccess\.php|app/src/LocationService\.php|app/src/GroupService\.php|app/src/TenantMailService\.php|app/src/CredentialImportService\.php|app/src/I18n\.php|app/src/ComplianceEventService\.php|app/src/ActivityDigestService\.php|app/src/DocumentRequestService\.php|app/src/ExternalShareService\.php|app/src/CrossTenantService\.php|app/src/SecurityPosture\.php|app/src/SchemaDoctor\.php|app/src/EnrollmentService\.php|app/src/ComplianceLevelService\.php|app/src/RegulatorySources\.php|app/src/VerificationService\.php|app/src/MicrosoftOAuth\.php|app/src/TenantReportService\.php|app/src/CustomFieldService\.php|app/src/ApplicationReadinessService\.php|app/src/SensitiveFields\.php|app/src/DashboardService\.php|app/src/ChecklistService\.php|app/src/DataExportService\.php|app/src/NotificationService\.php|app/src/OnboardingService\.php|app/src/DemoHealthcareService\.php|app/src/MailService\.php|app/src/SettingsService\.php|app/src/ReminderService\.php|app/src/BackupService\.php|app/src/ErrorLogService\.php|app/src/DomainService\.php|app/src/CompliancePostureService\.php|app/src/InvoiceService\.php|app/src/CredentialingCaseService\.php|app/src/AgencyWorkQueueService\.php|app/src/PurgeService\.php|cron/|database/'

fail=0
violations=$(grep -rn "Database::pdo()" app 2>/dev/null | grep -vE "$ALLOW_REGEX" || true)
if [ -n "$violations" ]; then
  echo "✗ ISOLATION CHECK FAILED — Database::pdo() used outside the repository layer:"
  echo "$violations"
  echo "Route tenant-table access through a *Repository class so tenant_id scoping is enforced."
  fail=1
fi

# Stale allow-list entries
IFS='|' read -ra ENTRIES <<< "$ALLOW_REGEX"
for e in "${ENTRIES[@]}"; do
  path=${e//\\./.}
  if [ ! -e "$path" ]; then echo "✗ ISOLATION CHECK FAILED — allow-list entry does not exist: $path"; fail=1; fi
done

# Front-controller baseline
base=$(tr -dc '0-9' < tools/isolation_baseline.txt 2>/dev/null || echo 0)
now=$(grep -c "Database::pdo()" index.php || true)
if [ "$now" -gt "${base:-0}" ]; then
  echo "✗ ISOLATION CHECK FAILED — index.php has $now raw Database::pdo() calls (baseline $base). Move new queries into a repository/service."
  fail=1
elif [ "$now" -lt "${base:-0}" ]; then
  echo "ℹ index.php dropped to $now raw calls — lower tools/isolation_baseline.txt from $base to $now to lock in the gain."
fi

[ $fail -ne 0 ] && exit 1
echo "✓ isolation check passed — no raw DB access outside the repository layer (index.php: $now/${base} baseline)"

# Secondary: warn if a raw SELECT/UPDATE/DELETE on a tenant table appears in the front controller or views
raw=$(grep -nE "(SELECT|UPDATE|DELETE|INSERT).*(credential_holders|credentials|renewal_tasks|credential_documents)" index.php app/views 2>/dev/null || true)
if [ -n "$raw" ]; then
  echo ""
  echo "⚠ WARNING — raw SQL on tenant tables found in the controller/view layer:"
  echo "$raw"
fi
