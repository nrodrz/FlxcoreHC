<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Table names that the migrations create, rename or drop along the way but that do not exist in the finished schema (retired insurance-era
 * tables). They are prefixed like the others while the migrations run, so a rename or a drop finds the right table.
 * Kept in step with the migrations by tools/db_tables.php --check.
 */
return [
    'auth_email_codes',
    'beneficiary_interactions',
    'booking_types',
    'bookings',
    'carrier_cert_schedule',
    'carrier_interactions',
    'carrier_requirements',
    'carriers',
    'cms_sync_log',
    'cms_sync_source',
    'commissions',
    'credential_reminders',
    'cross_share_access',
    'entity_reminders',
    'external_share_access',
    'holder_requirements',
    'invoice_counter',
    'network_invites',
    'nipr_interest',
    'password_resets',
    'platform_carrier_aliases',
    'platform_carrier_plans',
    'platform_carriers',
    'policies',
    'requirement_defs',
    'soa_forms',
];
