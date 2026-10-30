<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — vTiger provisioning client.
 *
 * Two channels:
 *   1. itops_vtiger_bridge_call() — the PREFERRED path for creating users, since
 *      vTiger's Web Services CANNOT create Users. Talks to ITOps's own bridge
 *      (itops-bridge.php) over an HMAC-signed request. ITOps's own secret.
 *   2. ItopsVTigerClient — the webservice (getchallenge → login), used as a
 *      fallback and to verify credentials. Rarely completes a Users create on
 *      its own, which is why the bridge exists.
 *
 * Config from itops_settings key 'vtiger_provision' (JSON):
 *   endpoint, username, access_key, default_roleid, bridge_url, bridge_secret
 */


if (!class_exists('ItopsVTigerClient')) {
    /**
     * Minimal vTiger Web Services client: getchallenge → login. Used to verify
     * credentials (testLogin) and as a create fallback. No session-cache
     * stampede handling (onboarding is a single call, not the parallel dashboard).
     */
    }
