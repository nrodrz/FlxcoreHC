<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT124: Microsoft invoices from Microsoft's billing API (Microsoft Customer Agreement).
 *
 * Same app registration as Microsoft 365 (tenant, client, secret), its own token for Azure Resource Manager
 * (scope https://management.azure.com/.default). No API permission to add: the app's service principal needs the billing
 * role "Billing profile reader" (or "Invoice manager") on the billing profile. Reads only:
 *   GET  /providers/Microsoft.Billing/billingAccounts                                  the billing accounts it can see
 *   GET  …/billingAccounts/{a}/invoices?periodStartDate&periodEndDate                    invoices
 *   GET  …/billingAccounts/{a}/invoices/{n}/transactions                                 the charges on an invoice
 *   POST …/billingAccounts/{a}/invoices/{n}/download?documentName=                       a short-lived link to the PDF
 * An invoice is imported once (vendor "Microsoft", number = Microsoft's invoice name), status "Received": a person
 * approves and pays it in the app as for any vendor. Microsoft's own status is kept in ms_note and refreshed.
 * Not checked on a live tenant yet (built from Microsoft's published API description).
 */
if (!function_exists('itops_msb_token')) {
    if (!defined('ITOPS_MSB_API')) define('ITOPS_MSB_API', '2024-04-01');

    /** One call to Azure Resource Manager. [code, json, headers]. Tests replace it (only defined when missing). */

}
