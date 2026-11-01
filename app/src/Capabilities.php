<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * The catalog of grantable capabilities — every app feature that a role can be given.
 *
 * This is the single source of truth for "all app features identified to select one or
 * multiple roles." The role builder renders this as a grouped checklist; Authz checks a
 * user's role against the capabilities stored for it. Capability slugs match the strings
 * that routes and views already pass to Authz::can(), so adding the data layer required no
 * new slugs.
 *
 * platform.* is deliberately NOT here: platform-admin (the SaaS console) is a separate
 * axis that a tenant role can never grant.
 */
final class Capabilities
{
    /** group key => [label, [capability => description, ...]] */
    public const CATALOG = [
        'organization' => ['Organization', [
            'org.manage'     => 'Edit organization profile, branding, and settings',
            'team.manage'    => 'Manage users, roles, invitations, and password resets',
            'billing.manage' => 'Change the plan and manage payment',
            'billing.view'   => 'View the plan and invoices',
            'license.view'   => 'View the full About & ownership page (workspace ID, plan, license terms)',
        ]],
        'compliance' => ['Compliance records', [
            'records.write'  => 'Create and edit providers, credentials, and templates',
            'records.delete' => 'Delete providers, credentials, and records',
            'records.import' => 'Bulk import providers and credentials from a spreadsheet',
        ]],
        'sensitive' => ['Sensitive data', [
            'pii.view' => 'View sensitive identity data (date of birth, SSN last 4) — otherwise masked',
        ]],
        'documents' => ['Document management', [
            'documents.manage'  => 'Attach and remove credential documents',
            'documents.view'    => 'View and download credential documents',
            'documents.verify'  => 'Record a document as verified (method: primary source / copy / attestation, as checked by your organization)',
            'documents.request' => 'Request documents from providers and accept or return submissions',
        ]],
        'integrations' => ['Integrations', [
            'npi.lookup'     => 'Look up and validate NPIs against the CMS NPPES registry',
        ]],
        'sharing' => ['Sharing & oversight', [
            'share.manage'    => 'Create and revoke external share links (payers, agencies)',
            'crossorg.manage' => 'Share credentials with linked organizations (cross-organization)',
            'data.export'     => 'Export organization data (bulk download of records)',
            'reports.view'    => 'View compliance reports and dashboards',
            'audit.view'      => 'View the audit trail and activity dashboard',
        ]],
    ];

    /** Every capability slug that exists, flat. */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as [$label, $caps]) {
            foreach ($caps as $slug => $_desc) $out[] = $slug;
        }
        return $out;
    }

    public static function isValid(string $slug): bool
    {
        return in_array($slug, self::all(), true);
    }

    /** Human label for a slug (for audit lines and summaries). */
    public static function label(string $slug): string
    {
        foreach (self::CATALOG as [$_label, $caps]) {
            if (isset($caps[$slug])) return $caps[$slug];
        }
        return $slug;
    }
}
