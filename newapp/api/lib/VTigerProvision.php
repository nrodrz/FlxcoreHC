<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — vTiger provisioning shared helpers.
 *
 * Holds the create routine used by BOTH the standalone route
 * (api/routes/vtiger_provision.php) and the onboarding apply flow
 * (api/routes/onboard_templates.php), so neither has to include the other's
 * routing logic. Requires VTigerClient.php + VTigerDb.php.
 */
require_once __DIR__ . '/VTigerClient.php';
require_once __DIR__ . '/VTigerDb.php';

if (!function_exists('itops_vtiger_cfg')) {
    /** BT89: a temporary password: 16 characters, upper and lower case, digits and symbols, no look-alike characters. */
    function itops_vtiger_temp_password(int $len = 16): string {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%*+-=?@'];
        $all = implode('', $sets); $out = [];
        foreach ($sets as $set) $out[] = $set[random_int(0, strlen($set) - 1)];
        while (count($out) < $len) $out[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($out) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$out[$i], $out[$j]] = [$out[$j], $out[$i]]; }
        return implode('', $out);
    }

    function itops_vtiger_cfg(PDO $db): array {
        $rows = $db->query("SELECT skey, sval FROM itops_settings WHERE skey IN ('vtiger_provision','vtiger_db')")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
        require_once __DIR__ . '/Secrets.php';   // BT14: access key / bridge secrets / DB password stored encrypted
        $F = itops_secret_vtiger_fields();
        $dbc = itops_secret_map_fields(isset($rows['vtiger_db']) ? (json_decode((string)$rows['vtiger_db'], true) ?: []) : [], $F['db'], false);
        // BT124 (code review OPS-7): Settings saves the database name as "name"; the connection reads "db" — both work now
        if (empty($dbc['db']) && !empty($dbc['name'])) $dbc['db'] = $dbc['name'];
        return [
            'prov' => itops_secret_map_fields(isset($rows['vtiger_provision']) ? (json_decode((string)$rows['vtiger_provision'], true) ?: []) : [], $F['prov'], false),
            'db'   => $dbc,
        ];
    }

    /**
     * Create a vTiger user. Dedup via read-only DB (non-fatal); create via the
     * bridge (preferred, ITOps's own), webservice as fallback. Never writes
     * vTiger's DB directly. Returns ['ok','id?','via','error?','password'].
     */
    function itops_vtiger_create_user(PDO $db, array $in): array {
        $cfg  = itops_vtiger_cfg($db);
        $prov = $cfg['prov'];
        $email  = trim((string)($in['email'] ?? ''));
        $last   = trim((string)($in['last_name'] ?? ''));
        $first  = trim((string)($in['first_name'] ?? ''));
        $uname  = trim((string)($in['user_name'] ?? ''));
        $roleid = trim((string)($in['roleid'] ?? ''));
        if ($roleid === '') $roleid = trim((string)($prov['default_roleid'] ?? ''));   // blank on the step = use the default role
        $status = ((string)($in['status'] ?? 'Active')) === 'Inactive' ? 'Inactive' : 'Active';
        $pw     = (string)($in['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'A valid primary email is required.'];
        if ($last === '')   return ['ok' => false, 'error' => 'Last name is required.'];
        if ($roleid === '') return ['ok' => false, 'error' => 'A vTiger role is required.'];
        if ($uname === '')  $uname = strstr($email, '@', true) ?: $email;
        // BT89: a user name vTiger can sign in with (letters, digits, . _ - @), and a typed password that is not weak.
        if (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $uname)) return ['ok' => false, 'error' => 'The vTiger user name can have letters, digits and . _ - @ (2 to 64 characters).'];
        if ($pw !== '' && (strlen($pw) < 12 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw))) return ['ok' => false, 'error' => 'A typed password needs at least 12 characters with letters and digits. Leave it empty to have one generated.'];
        if ($pw === '')     $pw = itops_vtiger_temp_password();

        if (!empty($cfg['db'])) {
            try {
                if (!empty((new ItopsVTigerDb($cfg['db']))->usersByEmails([$email]))) {
                    return ['ok' => false, 'error' => 'A vTiger user with that email already exists.', 'code' => 'already_exists'];
                }
            } catch (Throwable $e) { /* non-fatal */ }
        }

        if (itops_vtiger_bridge_ready($prov)) {
            // Dedupe through the bridge too (build o5+): works without the read-only DB connection.
            $fu = itops_vtiger_bridge_call($prov, 'find_user', ['email' => $email]);
            if (!empty($fu['ok']) && !empty($fu['user'])) {
                return ['ok' => false, 'error' => 'A vTiger user with that email already exists (' . ($fu['user']['user_name'] ?? '') . ', ' . ($fu['user']['status'] ?? '') . ').', 'code' => 'already_exists', 'id' => (string)($fu['user']['id'] ?? '')];
            }
            // BT89 (M-38): the new password goes over HTTPS only; and the role must be one vTiger has.
            if (!itops_vtiger_url_secure((string)($prov['bridge_url'] ?? ''))) return ['ok' => false, 'error' => 'The vTiger bridge address is not HTTPS: ' . itops_app_name() . ' does not send a new password over it. Use https:// in Settings → Company systems → vTiger.', 'code' => 'insecure', 'via' => 'bridge'];
            $lr = itops_vtiger_bridge_call($prov, 'list_roles');
            if (empty($lr['ok'])) return ['ok' => false, 'error' => 'Could not read the vTiger roles: ' . (string)($lr['error'] ?? 'no answer'), 'via' => 'bridge'];
            if (!in_array($roleid, array_map(fn($x) => (string)($x['roleid'] ?? ''), (array)($lr['roles'] ?? [])), true)) return ['ok' => false, 'error' => 'That role does not exist in vTiger. Pick one from the list.', 'via' => 'bridge'];
            $reply = itops_vtiger_bridge_call($prov, 'create_user', ['fields' => [
                'user_name' => $uname, 'last_name' => $last, 'first_name' => $first,
                'email1' => $email, 'roleid' => $roleid, 'user_password' => $pw,
                'is_admin' => 'off', 'status' => $status, 'time_zone' => date_default_timezone_get(),   // BT131: the installation's zone
            ]]);
            if (!empty($reply['ok'])) return ['ok' => true, 'id' => (string)($reply['id'] ?? ''), 'via' => 'bridge', 'password' => $pw];
            return ['ok' => false, 'error' => (string)($reply['error'] ?? 'Bridge create failed.'), 'via' => 'bridge'];
        }

        $endpoint = (string)($prov['endpoint'] ?? '');
        $username = (string)($prov['username'] ?? '');
        $key      = (string)($prov['access_key'] ?? '');
        if ($endpoint === '' || $username === '' || $key === '') {
            return ['ok' => false, 'error' => 'vTiger provisioning is not configured (no bridge and no webservice account).'];
        }
        try {
            (new ItopsVTigerClient($endpoint, $username, $key))->testLogin();
            return ['ok' => false, 'error' => 'Webservice reachable but cannot create Users — deploy the bridge (itops-bridge.php).', 'via' => 'webservice'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'via' => 'webservice'];
        }
    }
}
