<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — Help Center visuals (BT50).
 *
 * Interactive pages (3D views) that explain how the company's systems fit together. They are not for everyone: a
 * Super Admin gives each one to roles and to individual people. Nobody else can list or open it; a Super Admin always
 * can. The pages live in api/lib/visuals/<id>.php (a PHP file that returns the HTML, so a direct request prints
 * nothing) and are sent only by api/routes/help.php after the check below.
 *
 * Who may open what is kept in itops_settings, key "help_visual_access":
 *   { "<visual id>": { "roles": ["<role slug>", …], "users": [<user id>, …] }, … }
 */

if (!function_exists('itops_help_visuals')) {

    /** The visuals this build carries: id => [title, description]. The id is also the file name. */
    function itops_help_visuals(): array {
        // 0001-A003: none in this product yet (the reference app's two — company systems, integration map — were about removed modules)
        return [];
    }

    /** The saved access, cleaned: only known visuals, role slugs as strings, user ids as positive whole numbers. */
    function itops_help_visual_access(PDO $db): array {
        $raw = null;
        try { $s = $db->prepare('SELECT sval FROM itops_settings WHERE skey=?'); $s->execute(['help_visual_access']); $raw = json_decode((string)$s->fetchColumn(), true); }
        catch (Throwable $e) { $raw = null; }
        $out = [];
        foreach (array_keys(itops_help_visuals()) as $id) {
            $a = is_array($raw[$id] ?? null) ? $raw[$id] : [];
            $roles = array_values(array_unique(array_filter(array_map('strval', is_array($a['roles'] ?? null) ? $a['roles'] : []), fn($r) => $r !== '')));
            $users = array_values(array_unique(array_filter(array_map('intval', is_array($a['users'] ?? null) ? $a['users'] : []), fn($u) => $u > 0)));
            $out[$id] = ['roles' => $roles, 'users' => $users];
        }
        return $out;
    }

    function itops_help_visual_access_save(PDO $db, array $acc): void {
        $db->prepare('INSERT INTO itops_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')
           ->execute(['help_visual_access', json_encode($acc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** May this signed-in user open the visual? A Super Admin always; otherwise their role or they themselves must be on it. */
    function itops_help_visual_allowed(array $user, string $id, array $acc): bool {
        if (!isset(itops_help_visuals()[$id])) return false;
        if (($user['role_slug'] ?? '') === 'super_admin') return true;
        $a = $acc[$id] ?? ['roles' => [], 'users' => []];
        if (($user['role_slug'] ?? '') !== '' && in_array((string)$user['role_slug'], $a['roles'], true)) return true;
        return in_array((int)($user['id'] ?? 0), $a['users'], true);
    }

    /**
     * BT51: the systems both visuals show — one list, so the two pages always name the same systems. The list is
     * api/lib/visuals/systems.php; what the company switched on under Settings → Company systems is laid over it
     * (connected → read, tracked by hand → by hand, not used → marked so), and the company's own systems in use are
     * added as systems tracked by hand. Names and texts only: no address, key or account.
     */
    function itops_help_visual_systems(?PDO $db = null): array {
        $cat = require __DIR__ . '/visuals/systems.php';
        $live = [];
        if ($db && is_file(__DIR__ . '/Systems.php')) {
            try {
                if (is_file(__DIR__ . '/SystemConnect.php')) require_once __DIR__ . '/SystemConnect.php';
                require_once __DIR__ . '/Systems.php';
                foreach (itops_sys_all($db) as $s) $live[$s['key']] = $s;
            } catch (Throwable $e) { $live = []; }
        }
        $out = [];
        $add = function (array $s) use (&$out) {
            $out[] = ['id' => (string)$s['id'], 'name' => (string)$s['name'], 'status' => (string)$s['status'], 'role' => (string)$s['role'],
                'what' => (string)($s['what'] ?? ''), 'connect' => (string)($s['connect'] ?? ''), 'who' => (string)($s['who'] ?? ''),
                'reads' => (string)($s['reads'] ?? ''), 'writes' => (string)($s['writes'] ?? ''), 'limits' => (string)($s['limits'] ?? ''),
                'mind' => (string)($s['mind'] ?? ''), 'flows' => array_values($s['flows'] ?? []), 'in_use' => $s['in_use'] ?? null,
                'custom' => !empty($s['custom'])];
        };
        foreach ($cat as $s) {
            $ck = $s['company'] ?? (isset($live[$s['id']]) ? $s['id'] : null);
            // Review WORK-17: a named outside system is drawn only when this installation uses it (Settings → Company systems)
            if ($ck !== null && $live && (!isset($live[$ck]) || ($live[$ck]['mode'] ?? 'off') === 'off')) continue;
            if ($ck !== null && isset($live[$ck])) {
                $m = $live[$ck]['mode'];
                if ($m === 'connected') $s['status'] = 'reads';
                elseif ($m === 'manual') $s['status'] = 'hand';
                $s['in_use'] = $m !== 'off';
            }
            $add($s);
            if ($s['id'] === 'pbx') {   // the company's own systems sit with the other systems tracked by hand
                foreach ($live as $k => $l) {
                    if (empty($l['custom']) || $l['mode'] === 'off') continue;
                    $add(['id' => 'c-' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($k)), 0, 30), 'name' => mb_substr((string)$l['name'], 0, 60),
                        'status' => 'hand', 'role' => 'people', 'in_use' => true, 'custom' => true,
                        'what' => 'A system added by the company under Settings → Company systems.',
                        'connect' => 'None yet. It is tracked by hand.', 'who' => '—',
                        'reads' => 'Nothing: the accounts are recorded by hand.', 'writes' => 'Nothing. A person does the work there and answers in ITOps.',
                        'limits' => 'Tracked by hand until a connection is built for it.', 'mind' => 'The instructions people see on its steps are written on its set-up page.']);
                }
            }
        }
        return $out;
    }

    /**
     * The page itself, as a complete HTML document for an <iframe srcdoc>. The product name in the text follows the
     * app name chosen under Settings → Branding (the pages are written with "ITOps"). BT51: the page gets the shared
     * list of systems (window.ITOPS_SYSTEMS) and follows the light or dark theme the Help Center sends it.
     */
    /** BT90: the script of a visual (or the shared "objects" / "boot"), with the app name as in the page text. */
    function itops_help_visual_script(string $id): ?string {
        if (!preg_match('/^[a-z0-9-]{1,40}$/D', $id)) return null;
        if (!in_array($id, ['boot', 'objects'], true) && !isset(itops_help_visuals()[$id])) return null;
        $f = __DIR__ . '/visuals/' . $id . '.js';
        if (!is_file($f)) return null;
        $js = (string)file_get_contents($f);
        $name = function_exists('itops_app_name') ? itops_app_name() : 'ITOps';
        if ($name !== '' && $name !== 'ITOps' && !in_array($id, ['boot', 'objects'], true)) {
            $nameHtml = str_replace(['\\', "'", '"', "\n", "\r", '`', '${'], '', htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $js = preg_replace('/(?<![-\w])ITOps(?![-\w])/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $nameHtml), $js);
        }
        return $js;
    }

    function itops_help_visual_html(string $id, ?PDO $db = null): ?string {
        if (!isset(itops_help_visuals()[$id]) || !preg_match('/^[a-z0-9-]{1,40}$/D', $id)) return null;
        $f = __DIR__ . '/visuals/' . $id . '.php';
        if (!is_file($f)) return null;
        $html = require $f;
        if (!is_string($html) || $html === '') return null;
        $systems = itops_help_visual_systems($db);
        $name = function_exists('itops_app_name') ? itops_app_name() : 'ITOps';
        if ($name !== '' && $name !== 'ITOps') {
            $rep = function ($t) use ($name) { return is_string($t) ? preg_replace('/(?<![-\w])ITOps(?![-\w])/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $name), $t) : $t; };
            array_walk_recursive($systems, function (&$v) use ($rep) { $v = $rep($v); });   // goes out as JSON, encoded below
            // In the page itself the name is HTML text: escaped, so a name with < " ' cannot change the page (BT51).
            $nameHtml = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = preg_replace('/(?<![-\w])ITOps(?![-\w])/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $nameHtml), $html);
        }
        $data = json_encode($systems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($data === false) $data = '[]';
        // The theme: the Help Center tells the page (postMessage from its parent only; "light" or "dark", nothing else).
        // BT90 (code audit M-40): no inline scripts. The shared 3D objects (BT51) and the start-up come from api/help_script,
        // the list of systems is data in a JSON block (encoded with JSON_HEX_TAG, so it cannot end the block).
        $boot = '<script type="application/json" id="itops-systems">' . $data . '</script><script src="api/help_script/boot"></script><script src="api/help_script/objects"></script>';
        return "<!doctype html>\n<html lang=\"en\" data-theme=\"light\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
             . "<style>html,body{margin:0}[hidden]{display:none!important}</style>" . $boot . "</head><body>\n" . $html . "\n</body></html>";
    }
}
