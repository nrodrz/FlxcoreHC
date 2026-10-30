<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub BT38 — the organization's own fields on Microsoft 365 accounts, and its company-wide values.
 *
 * Microsoft gives every account fifteen spare text values, "custom attributes 1–15" (Exchange calls them
 * CustomAttribute1…15, Entra extensionAttribute1…15). They have no name of their own, so an organization that uses
 * them has to remember that "attribute 3 is the pronouns". This file stores that naming:
 *
 *   opsh_settings.m365_custom_fields  [{slot, label, section, help, kind, options[], active}, …]
 *   opsh_settings.m365_org_defaults   {companyName, streetAddress, …, ext3, …}   values that are the same for everybody
 *
 * The values themselves stay in Microsoft 365, on each account — nothing about a person is stored here. That is also
 * what makes them usable elsewhere: an email-signature service such as CodeTwo reads the same attributes.
 * Removing a field here only removes its name from this app; what the accounts hold is left as it is.
 */
if (!function_exists('opsh_m365_custom_fields')) {

    function opsh_m365_setting_get(PDO $db, string $key): ?array
    {
        try { $s = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $s->execute([$key]); $j = json_decode((string)$s->fetchColumn(), true); }
        catch (Throwable $e) { return null; }
        return is_array($j) ? $j : null;
    }

    function opsh_m365_setting_put(PDO $db, string $key, array $val): void
    {
        $db->prepare('INSERT INTO opsh_settings (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)')
           ->execute([$key, json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** The custom fields the organization defined, ordered by slot. */
    function opsh_m365_custom_fields(PDO $db): array
    {
        $rows = opsh_m365_setting_get($db, 'm365_custom_fields') ?? [];
        $out = [];
        // BT42: what is stored may have been edited by hand or by an older build: anything that is not a field is passed
        // over, without a warning and without becoming "attribute 1".
        $txt = fn($v): string => is_string($v) || is_int($v) || is_float($v) ? (string)$v : '';
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $sv = $r['slot'] ?? null;
            if (!is_int($sv) && !(is_string($sv) && ctype_digit($sv))) continue;
            $slot = (int)$sv;
            if ($slot < 1 || $slot > 15 || isset($out[$slot])) continue;
            $opts = [];
            foreach (is_array($r['options'] ?? null) ? $r['options'] : [] as $o) { $o = $txt($o); if ($o !== '') $opts[] = $o; }
            $out[$slot] = ['slot' => $slot, 'label' => $txt($r['label'] ?? ''), 'section' => ($r['section'] ?? '') === 'organization' ? 'organization' : 'contact',
                           'help' => $txt($r['help'] ?? ''), 'kind' => ($r['kind'] ?? '') === 'list' ? 'list' : 'text',
                           'options' => $opts, 'active' => !isset($r['active']) || !empty($r['active'])];
        }
        ksort($out);
        return array_values($out);
    }

    /** Check a list of custom fields that came from the screen. Throws InvalidArgumentException with a sentence to show. */
    function opsh_m365_custom_fields_clean($in): array
    {
        if (!is_array($in)) throw new InvalidArgumentException('A list of fields is expected.');
        if (count($in) > 15) throw new InvalidArgumentException('Microsoft offers 15 custom attributes, so at most 15 fields.');
        $one = fn($v, $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string)$v : '')), 0, $max);
        // A custom field may not take the name of a built-in one: the screens and the Audit Log tell fields apart by name.
        $out = []; $labels = []; $builtin = ['manager' => true];
        foreach (OpshM365::profileFields() as $pf) $builtin[mb_strtolower($pf['label'])] = true;
        foreach ($in as $r) {
            if (!is_array($r)) throw new InvalidArgumentException('A list of fields is expected.');
            // BT42: the attribute is a whole number. "true", a list or "3rd" used to be read as a number (true and a list as 1).
            $sv = $r['slot'] ?? null;
            if (!is_int($sv) && !(is_string($sv) && ctype_digit(trim($sv)))) throw new InvalidArgumentException('Choose a custom attribute between 1 and 15 for every field.');
            $slot = (int)(is_string($sv) ? trim($sv) : $sv);
            if ($slot < 1 || $slot > 15) throw new InvalidArgumentException('Choose a custom attribute between 1 and 15 for every field.');
            if (isset($out[$slot])) throw new InvalidArgumentException('Custom attribute ' . $slot . ' is used by two fields. Each attribute holds one field.');
            $label = $one($r['label'] ?? '', 60);
            if ($label === '') throw new InvalidArgumentException('The field on custom attribute ' . $slot . ' needs a name.');
            $lk = mb_strtolower($label);
            if (isset($builtin[$lk])) throw new InvalidArgumentException('"' . $label . '" is already the name of a built-in field. Choose another name, for example "' . $label . ' (internal)".');
            if (isset($labels[$lk])) throw new InvalidArgumentException('Two fields are called "' . $label . '". Give each its own name.');
            $labels[$lk] = true;
            $kind = ($r['kind'] ?? '') === 'list' ? 'list' : 'text';
            $opts = [];
            if ($kind === 'list') {
                $raw = $r['options'] ?? [];
                if (is_string($raw)) $raw = preg_split('/\r?\n/', $raw);
                foreach ((array)$raw as $o) { $o = $one($o, 100); if ($o !== '' && !in_array($o, $opts, true)) $opts[] = $o; }
                if (!$opts) throw new InvalidArgumentException('"' . $label . '" is a list of choices: write at least one choice.');
                if (count($opts) > 60) throw new InvalidArgumentException('"' . $label . '": at most 60 choices.');
            }
            $out[$slot] = ['slot' => $slot, 'label' => $label, 'section' => ($r['section'] ?? '') === 'organization' ? 'organization' : 'contact',
                           'help' => $one($r['help'] ?? '', 200), 'kind' => $kind, 'options' => $opts, 'active' => !isset($r['active']) || !empty($r['active'])];
        }
        ksort($out);
        return array_values($out);
    }

    /** The keys that can have a company-wide value: where everybody shares the same thing. Never a person's own data. */
    function opsh_m365_org_default_keys(): array
    {
        return ['companyName', 'officeLocation', 'businessPhone', 'faxNumber', 'streetAddress', 'city', 'state', 'postalCode', 'country', 'usageLocation', 'preferredLanguage'];
    }

    /** Company-wide values: {key: value}, only keys that still make sense (a built-in one, or an active custom field). */
    function opsh_m365_org_defaults(PDO $db, ?array $custom = null): array
    {
        $saved = opsh_m365_setting_get($db, 'm365_org_defaults') ?? [];
        $custom = $custom ?? opsh_m365_custom_fields($db);
        $ok = array_flip(opsh_m365_org_default_keys());
        foreach ($custom as $c) if ($c['active']) $ok['ext' . $c['slot']] = true;
        $out = [];
        foreach ($saved as $k => $v) if (isset($ok[$k]) && is_scalar($v) && trim((string)$v) !== '') $out[$k] = (string)$v;
        return $out;
    }

    /** Check company-wide values from the screen, with the same rules an account's own value has to meet. */
    function opsh_m365_org_defaults_clean($in, array $custom): array
    {
        if (!is_array($in)) throw new InvalidArgumentException('A set of values is expected.');
        $allowed = array_flip(opsh_m365_org_default_keys());
        $send = [];
        foreach ($in as $k => $v) {
            $k = (string)$k;
            if (!isset($allowed[$k]) && !preg_match('/^ext([1-9]|1[0-5])$/', $k)) throw new InvalidArgumentException('"' . mb_substr($k, 0, 40) . '" cannot have a company-wide value.');
            if ($v === null || trim((string)$v) === '') continue;
            $send[$k] = $v;
        }
        // Validate as a change from "nothing": the same length, format and choice rules as on an account.
        $chk = OpshM365::profileChanges([], $send, $custom, false);
        $out = [];
        foreach ($chk as $k => $c) $out[$k] = $c['to'];
        return $out;
    }
}
