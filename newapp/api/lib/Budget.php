<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps BT58 — IT budget.
 *
 *   Sites        offices and regions (itops_budget_sites). Lines and actuals may belong to one; NULL = the whole
 *                organization. The first visit seeds two neutral sites (see itops_budget_seed_sites): "Main office" and
 *                "Remote and field staff"; the organization adds its own offices and addresses.
 *   Lines        one planned cost of one fiscal year (itops_budget_lines). amount = unit_cost × qty × periods in the year,
 *                always computed here, never taken from a request. needs_price = a quantity is known, the price is not.
 *   Actuals      money spent, per month (itops_budget_actuals), against a line or a cost pool.
 *   Settings     itops_settings.budget_cfg (fiscal year start, currency, pricing, contingency, the organization's figures,
 *                refresh cycles); itops_settings.budget_m365_prices (price per Microsoft 365 license, per sku id).
 *
 * Categories follow the TBM (Technology Business Management) taxonomy: cost pools (what money is spent on) and IT towers
 * (what it runs). Run / Grow / Transform and OpEx / CapEx are the two other usual cuts.
 *
 * Microsoft does not tell an application what a license costs. Seats, use and renewal dates are read from the tenant;
 * the price per license is a suggestion from Microsoft's published list (nonprofit or commercial) until a person saves
 * the price on the invoice. Nothing here writes to Microsoft 365.
 */
if (!function_exists('itops_budget_cfg')) {

    /** TBM cost pools (TBM Taxonomy). key => [label, what goes in it] */
    function itops_budget_pools(): array {
        return [
            'internal_labor'   => ['Internal labor', 'IT staff salaries, benefits and payroll taxes.'],
            'external_labor'   => ['External labor', 'Contractors and staff augmentation billed by the hour.'],
            'outside_services' => ['Outside services', 'Managed services (MSP), consulting, support contracts, maintenance.'],
            'hardware'         => ['Hardware', 'Computers, monitors, phones, tablets, printers, network gear, servers.'],
            'software'         => ['Software & SaaS', 'Subscriptions and licenses: Microsoft 365, CRM, e-signature, forms, security tools.'],
            'cloud'            => ['Cloud & hosting', 'Hosting, servers rented by the month, cloud compute and storage, backups.'],
            'telecom'          => ['Telecom & internet', 'Internet circuits, phone system and lines, mobile plans and hotspots, fax.'],
            'facilities'       => ['Facilities & power', 'Server room, power protection (UPS), cabling, office moves for IT.'],
            'other'            => ['Other', 'Training, certifications, memberships, travel, shipping.'],
        ];
    }

    /** TBM IT towers (shortened to what a small organization uses). */
    function itops_budget_towers(): array {
        return [
            'end_user'    => 'End user (devices, productivity, collaboration)',
            'application' => 'Business applications (CRM, HR, finance, case work)',
            'network'     => 'Network & connectivity',
            'telecom'     => 'Voice & fax',
            'compute'     => 'Servers, hosting & storage',
            'security'    => 'Security & compliance',
            'it_mgmt'     => 'IT management & service desk',
        ];
    }

    function itops_budget_natures(): array {
        return ['run' => 'Run (keep it working)', 'grow' => 'Grow (more of what exists)', 'transform' => 'Transform (something new)'];
    }
    function itops_budget_billings(): array {
        return ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Twice a year', 'annual' => 'Yearly', 'one_time' => 'One time'];
    }
    function itops_budget_site_kinds(): array {
        return ['office' => 'Office', 'regional' => 'Regional office', 'remote' => 'Remote and field staff', 'datacenter' => 'Hosting / data center'];
    }

    function itops_budget_cfg_defaults(): array {
        return ['fy_start' => 1, 'currency' => 'USD', 'pricing' => 'nonprofit', 'contingency_pct' => 5.0, 'increase_pct' => 3.0,
                'require_vendor' => true, 'require_contract' => 'recurring',   // BT59
                'headcount' => null, 'operating_budget' => null,
                // BT60: years before replacement, per Equipment category (0 = not replaced on a cycle); network and server
                // are kept for the office network line (they are not Equipment categories)
                'refresh' => ['laptop' => 4, 'desktop' => 5, 'monitor' => 6, 'phone' => 3, 'tablet' => 3, 'headset' => 3, 'dock' => 5, 'printer' => 5,
                              'projector' => 6, 'badge' => 0, 'key' => 0, 'other' => 0, 'network' => 6, 'server' => 5],
                'replace_price' => []];   // BT60: replacement price per Equipment category typed by a person (else the average paid)
    }

    function itops_budget_cfg(PDO $db): array {
        $d = itops_budget_cfg_defaults();
        try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='budget_cfg'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        if (!is_array($j)) return $d;
        $o = array_merge($d, array_intersect_key($j, $d));
        $ok = fn($k) => is_string($k) && preg_match('/^[a-z0-9][a-z0-9 _\/-]{0,39}$/', $k);
        $o['refresh'] = $d['refresh']; foreach ((array)($j['refresh'] ?? []) as $k => $v) if ($ok($k) && is_numeric($v)) $o['refresh'][$k] = max(0, min(15, (int)$v));
        $o['replace_price'] = []; foreach ((array)($j['replace_price'] ?? []) as $k => $v) if ($ok($k) && is_numeric($v) && (float)$v > 0) $o['replace_price'][$k] = round((float)$v, 2);
        return $o;
    }

    /** Check a settings change. Returns the full new config. Throws InvalidArgumentException with a sentence. */
    function itops_budget_cfg_clean(array $cur, array $in): array {
        $o = $cur;
        if (array_key_exists('fy_start', $in)) { $m = $in['fy_start']; if (!is_int($m) && !(is_string($m) && ctype_digit($m))) throw new InvalidArgumentException('The fiscal year starts in a month: 1 to 12.'); $m = (int)$m; if ($m < 1 || $m > 12) throw new InvalidArgumentException('The fiscal year starts in a month: 1 to 12.'); $o['fy_start'] = $m; }
        if (array_key_exists('currency', $in)) { $c = strtoupper(trim((string)$in['currency'])); if (!preg_match('/^[A-Z]{3}$/', $c)) throw new InvalidArgumentException('The currency is a three-letter code, for example USD.'); $o['currency'] = $c; }
        if (array_key_exists('pricing', $in)) { if (!in_array($in['pricing'], ['nonprofit', 'commercial'], true)) throw new InvalidArgumentException('Microsoft 365 pricing is "nonprofit" or "commercial".'); $o['pricing'] = $in['pricing']; }
        foreach (['contingency_pct' => [0, 50], 'increase_pct' => [-50, 100]] as $k => [$lo, $hi]) if (array_key_exists($k, $in)) {
            if (!is_numeric($in[$k])) throw new InvalidArgumentException('A percentage is a number.'); $v = round((float)$in[$k], 2);
            if ($v < $lo || $v > $hi) throw new InvalidArgumentException(($k === 'contingency_pct' ? 'The contingency' : 'The yearly increase') . " is between $lo and $hi percent."); $o[$k] = $v; }
        foreach (['headcount' => 100000, 'operating_budget' => 1e12] as $k => $max) if (array_key_exists($k, $in)) {
            $v = $in[$k]; if ($v === null || $v === '') { $o[$k] = null; continue; }
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > $max) throw new InvalidArgumentException($k === 'headcount' ? 'The number of employees is a whole number.' : 'The operating budget is an amount.');
            $o[$k] = $k === 'headcount' ? (int)$v : round((float)$v, 2); }
        if (array_key_exists('require_vendor', $in)) { if (!is_bool($in['require_vendor'])) throw new InvalidArgumentException('"Every line names its vendor" is on or off.'); $o['require_vendor'] = $in['require_vendor']; }
        if (array_key_exists('require_contract', $in)) { if (!in_array($in['require_contract'], ['all', 'recurring', 'none'], true)) throw new InvalidArgumentException('A contract is required on every line, on recurring lines, or never.'); $o['require_contract'] = $in['require_contract']; }
        if (array_key_exists('refresh', $in)) {
            if (!is_array($in['refresh'])) throw new InvalidArgumentException('Refresh cycles are years per kind of equipment.');
            foreach ($in['refresh'] as $k => $v) {
                $k = mb_strtolower(trim((string)$k)); if (!preg_match('/^[a-z0-9][a-z0-9 _\/-]{0,39}$/', $k)) throw new InvalidArgumentException('That kind of equipment is not one of the Equipment categories.');
                if (!is_numeric($v) || (int)$v < 0 || (int)$v > 15 || (float)$v != (int)$v) throw new InvalidArgumentException('A refresh cycle is 0 to 15 whole years (0 = not replaced on a cycle).'); $o['refresh'][$k] = (int)$v; }
        }
        if (array_key_exists('replace_price', $in)) {   // BT60
            if (!is_array($in['replace_price'])) throw new InvalidArgumentException('Replacement prices are an amount per kind of equipment.');
            foreach ($in['replace_price'] as $k => $v) {
                $k = mb_strtolower(trim((string)$k)); if (!preg_match('/^[a-z0-9][a-z0-9 _\/-]{0,39}$/', $k)) throw new InvalidArgumentException('That kind of equipment is not one of the Equipment categories.');
                if ($v === null || $v === '' || (is_numeric($v) && (float)$v == 0)) { unset($o['replace_price'][$k]); continue; }
                if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1e6) throw new InvalidArgumentException('A replacement price is an amount of 0 or more.'); $o['replace_price'][$k] = round((float)$v, 2); }
        }
        return $o;
    }

    /** Fiscal year $fy (the calendar year it starts in): first and last day, label, months as YYYY-MM. */
    function itops_budget_fy(array $cfg, int $fy): array {
        $m = (int)$cfg['fy_start'];
        $start = sprintf('%04d-%02d-01', $fy, $m);
        $end = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
        $months = []; for ($i = 0; $i < 12; $i++) $months[] = date('Y-m', strtotime($start . " +$i month"));
        $label = $m === 1 ? ('FY ' . $fy) : ('FY ' . $fy . '–' . substr((string)($fy + 1), 2));
        return ['fy' => $fy, 'start' => $start, 'end' => $end, 'label' => $label, 'months' => $months];
    }
    /** The fiscal year a date falls in. */
    function itops_budget_fy_of(array $cfg, string $ymd): int {
        $y = (int)substr($ymd, 0, 4); $mo = (int)substr($ymd, 5, 2);
        return $mo >= (int)$cfg['fy_start'] ? $y : $y - 1;
    }

    /** How many times a line is billed in the year. */
    function itops_budget_periods(string $billing, int $months): float {
        $months = max(1, min(12, $months));
        switch ($billing) {
            case 'monthly': return (float)$months;
            case 'quarterly': return (float)ceil($months / 3);
            case 'semi_annual': return (float)ceil($months / 6);
            default: return 1.0;   // annual, one_time
        }
    }
    function itops_budget_amount(array $l): float {
        return round((float)$l['unit_cost'] * (float)$l['qty'] * itops_budget_periods((string)$l['billing'], (int)$l['months']), 2);
    }

    // ── Microsoft 365 prices ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Microsoft's published prices per user per month, annual commitment billed yearly, in USD.
     * [name, nonprofit, commercial] — the prices in force from July 1, 2026 (existing subscriptions move at their first
     * renewal on or after that date). null = no published price for that program. 0 = free.
     * Sources (read 2026-10-09): TechSoup, "Microsoft cloud license prices" (nonprofit table, July 1, 2026);
     * Microsoft licensing news "2026 Microsoft 365 packaging and pricing updates" as reported by partners (commercial).
     * Microsoft adds 5% when an annual subscription is billed monthly.
     */
    function itops_budget_m365_catalog(): array {
        return [
            'O365_BUSINESS_ESSENTIALS' => ['Microsoft 365 Business Basic', 1.00, 7.00],
            'O365_BUSINESS_PREMIUM'    => ['Microsoft 365 Business Standard', 3.50, 14.00],
            'SPB'                      => ['Microsoft 365 Business Premium', 5.50, 22.00],
            'SPE_E3'                   => ['Microsoft 365 E3', 9.75, 39.00],
            'SPE_E5'                   => ['Microsoft 365 E5', 24.00, 60.00],
            'SPE_F1'                   => ['Microsoft 365 F3', 1.20, 10.00],
            'STANDARDPACK'             => ['Office 365 E1', 2.50, null],
            'ENTERPRISEPACK'           => ['Office 365 E3', 6.50, null],
            'ENTERPRISEPREMIUM'        => ['Office 365 E5', 16.40, null],
            'Microsoft_365_Copilot'    => ['Microsoft 365 Copilot', null, null],   // no confirmed price for the nonprofit program: enter the invoice price
            'FLOW_FREE'                => ['Power Automate Free', 0.0, 0.0],
            'POWER_BI_STANDARD'        => ['Power BI (free)', 0.0, 0.0],
            'TEAMS_EXPLORATORY'        => ['Teams Exploratory', 0.0, 0.0],
            'MICROSOFT_BUSINESS_CENTER'=> ['Microsoft Business Center', 0.0, 0.0],
            'WINDOWS_STORE'            => ['Windows Store for Business', 0.0, 0.0],
            'CCIBOTS_PRIVPREV_VIRAL'   => ['Copilot Studio (viral trial)', 0.0, 0.0],
            'POWERAPPS_DEV'            => ['Power Apps Developer', 0.0, 0.0],
            'POWERAPPS_VIRAL'          => ['Power Apps (viral)', 0.0, 0.0],
            'RIGHTSMANAGEMENT_ADHOC'   => ['Rights Management Adhoc', 0.0, 0.0],
        ];
    }
    function itops_budget_m365_sources(): array {
        return [['TechSoup: Microsoft cloud license prices, nonprofit (from July 1, 2026)', 'https://blog.techsoup.org/en-ca/posts/microsoft-cloud-license-prices-are-increasing-on-july-1-2027'],
                ['Microsoft: 2026 Microsoft 365 packaging and pricing updates', 'https://www.microsoft.com/licensing/news/2026-m365-packaging-pricing-updates']];
    }

    /**
     * BT59: whom Microsoft 365 is bought from, and under which agreement (setting budget_m365_link). When nothing is
     * saved, the vendor named "Microsoft" is proposed if there is exactly one; the agreement is never guessed.
     */
    function itops_budget_m365_link(PDO $db): array {
        $j = null; try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='budget_m365_link'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) {}
        $o = ['vendor_id' => is_array($j) && !empty($j['vendor_id']) ? (int)$j['vendor_id'] : null, 'contract_id' => is_array($j) && !empty($j['contract_id']) ? (int)$j['contract_id'] : null, 'saved' => is_array($j)];
        if (!$o['vendor_id'] && !$o['saved']) { try { $r = $db->query("SELECT id FROM itops_vendors WHERE LOWER(name) LIKE 'microsoft%' AND COALESCE(status,'active')<>'archived'")->fetchAll(PDO::FETCH_COLUMN); if (count($r) === 1) $o['vendor_id'] = (int)$r[0]; } catch (Throwable $e) {} }
        return $o;
    }

    function itops_budget_prices(PDO $db): array {
        try { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey='budget_m365_prices'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        return is_array($j) ? $j : [];
    }

    /** The price used for one license: the saved one, else the suggestion. ['price','billing','from'=>saved|suggested|unknown,'suggested'] */
    function itops_budget_m365_price(array $saved, string $skuId, string $part, string $pricing): array {
        $cat = itops_budget_m365_catalog()[$part] ?? null;
        $sug = $cat ? ($pricing === 'commercial' ? $cat[2] : $cat[1]) : null;
        if (isset($saved[$skuId]) && is_numeric($saved[$skuId]['price'] ?? null)) {
            return ['price' => round((float)$saved[$skuId]['price'], 2), 'billing' => ($saved[$skuId]['billing'] ?? 'annual') === 'monthly' ? 'monthly' : 'annual',
                    'from' => 'saved', 'suggested' => $sug, 'note' => (string)($saved[$skuId]['note'] ?? ''), 'by' => (string)($saved[$skuId]['by'] ?? ''), 'at' => (string)($saved[$skuId]['at'] ?? '')];
        }
        return ['price' => $sug, 'billing' => 'annual', 'from' => $sug === null ? 'unknown' : 'suggested', 'suggested' => $sug, 'note' => '', 'by' => '', 'at' => ''];
    }

    /**
     * The tenant's licenses with what they cost. Reads subscribedSkus (seats) and directory/subscriptions (renewal dates;
     * needs Organization.Read.All — without it the dates are missing and the rest still works). Reclaimable seats come
     * from the license report the Microsoft 365 pages keep (not read here when it is not kept).
     */
    /** What Microsoft is asked: the licenses and the subscriptions (kept 15 minutes by the route; prices are applied after). */
    function itops_budget_m365_read(): array {
        $svc = ItopsM365::fromConfig();
        $skus = $svc->skus()['list'];
        $subs = []; $subsErr = null;
        try { $subs = $svc->subscriptions(); } catch (Throwable $e) { $subsErr = $e->getMessage(); }
        return ['skus' => $skus, 'subs' => $subs, 'subs_err' => $subsErr, 'at' => date('c')];
    }

    function itops_budget_m365(PDO $db, ?array $raw = null): array {
        $cfg = itops_budget_cfg($db); $saved = itops_budget_prices($db);
        if ($raw === null) $raw = itops_budget_m365_read();
        $skus = $raw['skus']; $subs = $raw['subs']; $subsErr = $raw['subs_err'];
        $reclaim = []; $reclaimAt = null;
        if (function_exists('itops_m365_cache_file')) {
            $f = itops_m365_cache_file('license-report-30');
            $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
            if (is_array($j) && is_array($j['payload']['skus'] ?? null)) { foreach ($j['payload']['skus'] as $r) $reclaim[(string)$r['sku_id']] = (int)($r['reclaimable'] ?? 0); $reclaimAt = date('c', (int)$j['ts']); }
        }
        $rows = []; $tot = ['monthly' => 0.0, 'annual' => 0.0, 'unused_annual' => 0.0, 'reclaim_annual' => 0.0, 'unpriced' => 0, 'seats' => 0, 'used' => 0];
        foreach ($skus as $k) {
            $id = (string)$k['sku_id']; $part = (string)$k['part'];
            $p = itops_budget_m365_price($saved, $id, $part, (string)$cfg['pricing']);
            $cat = itops_budget_m365_catalog()[$part] ?? null;
            $mine = array_values(array_filter($subs, fn($s) => ($s['sku_id'] ?? '') === $id));
            $seats = (int)$k['total']; $used = (int)$k['consumed']; $unused = max(0, $seats - $used);
            $unit = $p['price'] === null ? null : round($p['price'] * ($p['billing'] === 'monthly' ? 1.05 : 1.0), 2);   // review OPS-14: cents, as stored
            $monthly = $unit === null ? null : round($unit * $seats, 2);
            $row = ['sku_id' => $id, 'part' => $part, 'name' => $cat[0] ?? $k['name'], 'seats' => $seats, 'used' => $used, 'unused' => $unused,
                'reclaimable' => $reclaim[$id] ?? null, 'price' => $p, 'unit_month' => $unit,
                'monthly' => $monthly, 'annual' => $monthly === null ? null : round($monthly * 12, 2),
                'unused_annual' => $unit === null ? null : round($unit * $unused * 12, 2),
                'reclaim_annual' => ($unit === null || !isset($reclaim[$id])) ? null : round($unit * $reclaim[$id] * 12, 2),
                'free' => $p['price'] === 0.0,
                'subscriptions' => array_map(fn($s) => ['status' => $s['status'], 'trial' => $s['trial'], 'seats' => $s['seats'], 'renews' => $s['renews'], 'created' => $s['created']], $mine)];
            $rows[] = $row;
            $tot['seats'] += $seats; $tot['used'] += $used;
            if ($monthly === null) $tot['unpriced']++; else { $tot['monthly'] += $monthly; $tot['annual'] += $row['annual']; $tot['unused_annual'] += $row['unused_annual']; if ($row['reclaim_annual'] !== null) $tot['reclaim_annual'] += $row['reclaim_annual']; }
        }
        usort($rows, fn($a, $b) => ($b['annual'] ?? -1) <=> ($a['annual'] ?? -1));
        foreach ($tot as $k => $v) if (is_float($v)) $tot[$k] = round($v, 2);
        return ['rows' => $rows, 'totals' => $tot, 'pricing' => $cfg['pricing'], 'currency' => $cfg['currency'], 'read_at' => $raw['at'] ?? null,
                'renewals' => $subsErr === null, 'renewals_error' => $subsErr, 'reclaim_at' => $reclaimAt, 'sources' => itops_budget_m365_sources()];
    }

    // ── Sites ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The starting sites, seeded once, on the first visit, only when no site exists: one office (its address, region
     * and match names are for a person to fill in) and the staff who work from home or in the field. Add, rename or
     * switch off sites on the page.
     * [name, kind, address, region, match names (office location or city in Microsoft 365 / Equipment)]
     */
    function itops_budget_seed_list(): array {
        return [
            ['Main office', 'office', '', '', 'Main office, Home Office, Headquarters'],
            ['Remote and field staff', 'remote', '', '', ''],
        ];
    }

    function itops_budget_seed_sites(PDO $db): int {
        $n = (int)$db->query('SELECT COUNT(*) FROM itops_budget_sites')->fetchColumn();
        if ($n > 0) return 0;
        $ins = $db->prepare('INSERT IGNORE INTO itops_budget_sites (name, kind, address, region, match_names, notes, sort_order) VALUES (?,?,?,?,?,?,?)');
        $i = 0;
        foreach (itops_budget_seed_list() as $r) {
            $note = $r[1] === 'office' ? 'Add the address and the names this office goes by (as written on Microsoft 365 → Accounts and in Equipment).'
                  : 'Staff who work from home or meet people in the field.';
            $ins->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $note, ($i + 1) * 10]); $i++;
        }
        return $i;
    }

    function itops_budget_sites(PDO $db, bool $activeOnly = false): array {
        $rows = $db->query('SELECT * FROM itops_budget_sites' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'kind' => (string)$r['kind'], 'address' => (string)$r['address'], 'region' => (string)$r['region'],
            'match_names' => (string)$r['match_names'], 'notes' => (string)($r['notes'] ?? ''), 'active' => (bool)$r['active'], 'sort_order' => (int)$r['sort_order']], $rows);
    }

    function itops_budget_site_clean(array $in, ?array $cur): array {
        $o = $cur ?? ['name' => '', 'kind' => 'office', 'address' => '', 'region' => '', 'match_names' => '', 'notes' => '', 'active' => true];
        foreach (['name' => 120, 'address' => 300, 'region' => 80, 'match_names' => 500, 'notes' => 2000] as $k => $max) if (array_key_exists($k, $in)) {
            if (!is_string($in[$k])) throw new InvalidArgumentException('The site is not in the expected form (text values). Nothing was saved.');
            $v = trim(preg_replace('/\s+/u', ' ', $in[$k]) ?? ''); if ($k === 'notes') $v = trim($in[$k]);
            $o[$k] = mb_substr($v, 0, $max); }
        if (array_key_exists('kind', $in)) { if (!isset(itops_budget_site_kinds()[$in['kind']])) throw new InvalidArgumentException('Choose what kind of site it is.'); $o['kind'] = $in['kind']; }
        if (array_key_exists('active', $in)) { if (!is_bool($in['active'])) throw new InvalidArgumentException('"In use" is on or off.'); $o['active'] = $in['active']; }
        if (mb_strlen($o['name']) < 2) throw new InvalidArgumentException('Give the site a name (at least two characters).');
        return $o;
    }
    function itops_budget_site_view(array $s): array {
        return ['Name' => $s['name'], 'Kind' => itops_budget_site_kinds()[$s['kind']] ?? $s['kind'], 'Address' => $s['address'], 'Region' => $s['region'],
                'Matches' => $s['match_names'], 'Notes' => $s['notes'], 'In use' => !empty($s['active']) ? 'yes' : 'no'];
    }

    /** Which site a text (an office location, a city, an asset location) belongs to, by the site's match names. */
    function itops_budget_site_for(array $sites, string $text): ?int {
        $t = mb_strtolower(trim($text)); if ($t === '') return null;
        foreach ($sites as $s) {
            foreach (array_filter(array_map('trim', explode(',', mb_strtolower($s['match_names'])))) as $m) if ($m !== '' && ($t === $m || mb_strpos($t, $m) !== false)) return (int)$s['id'];
        }
        return null;
    }

    /**
     * People and equipment per site, from what the app already knows: Microsoft 365 accounts (enabled members with a
     * license; office location, else city) and Equipment (location). Nothing is guessed: what matches no site is counted
     * apart with its values, so the matches can be set on the site.
     */
    function itops_budget_site_counts(PDO $db, array $sites): array {
        $out = ['people' => [], 'people_total' => 0, 'people_unmatched' => [], 'people_ok' => false, 'people_error' => null,
                'assets' => [], 'assets_unmatched' => [], 'refresh_due' => []];
        try {
            $grid = null;
            if (function_exists('itops_m365_cache_file')) { $f = itops_m365_cache_file('m365-grid'); $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; if (is_array($j['payload'] ?? null)) $grid = $j['payload']; }
            if ($grid === null && function_exists('itops_m365_build')) { $grid = itops_m365_build('m365-grid'); if (function_exists('itops_m365_cache_put') && empty($grid['_nocache'])) itops_m365_cache_put('m365-grid', $grid); }
            foreach ((array)$grid as $u) {
                if (!is_array($u) || empty($u['enabled']) || ($u['user_type'] ?? 'Member') === 'Guest' || empty($u['licenses'])) continue;
                $out['people_total']++;
                $loc = trim((string)($u['profile']['officeLocation'] ?? '')); if ($loc === '') $loc = trim((string)($u['profile']['city'] ?? ''));
                $sid = itops_budget_site_for($sites, $loc);
                if ($sid === null) { $k = $loc === '' ? '(no office or city set)' : $loc; $out['people_unmatched'][$k] = ($out['people_unmatched'][$k] ?? 0) + 1; }
                else $out['people'][$sid] = ($out['people'][$sid] ?? 0) + 1;
            }
            $out['people_ok'] = true;
        } catch (Throwable $e) { $out['people_error'] = $e->getMessage(); }
        try {
            foreach ($db->query("SELECT category, location, purchase_date, purchase_cost, warranty_expiry, status FROM itops_assets WHERE status NOT IN ('retired','disposed','lost')")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $sid = itops_budget_site_for($sites, (string)($a['location'] ?? '')); $cat = mb_strtolower((string)$a['category']) ?: 'other';
                if ($sid === null) { $k = trim((string)$a['location']) === '' ? '(no location set)' : (string)$a['location']; $out['assets_unmatched'][$k] = ($out['assets_unmatched'][$k] ?? 0) + 1; $sid = 0; }
                $out['assets'][$sid][$cat] = ($out['assets'][$sid][$cat] ?? 0) + 1;
            }
        } catch (Throwable $e) {}
        arsort($out['people_unmatched']); arsort($out['assets_unmatched']);
        return $out;
    }

    /**
     * The standard IT lines of one site for one year: communications (internet, backup internet, phone seats, mobile,
     * fax) and equipment (computer refresh, monitors and docks, network, printer). Quantities from the site's people and
     * equipment where the app knows them; prices are left at 0 and marked "needs a price" — they come from the invoices.
     * Returns the proposed lines; nothing is stored here.
     */
    function itops_budget_site_standard(PDO $db, array $site, int $fy, array $counts, array $cfg): array {
        $sid = (int)$site['id']; $people = (int)($counts['people'][$sid] ?? 0); $as = $counts['assets'][$sid] ?? [];
        $laptops = (int)($as['laptop'] ?? 0); $desktops = (int)($as['desktop'] ?? 0); $phones = (int)($as['phone'] ?? 0);
        $office = in_array($site['kind'], ['office', 'regional'], true); $remote = $site['kind'] === 'remote';
        $fyd = itops_budget_fy($cfg, $fy);
        $due = function (string $cat, int $years) use ($db, $sid, $site, $fyd): int {   // computers of this site whose age reaches the cycle before the year ends
            $n = 0; $sites = itops_budget_sites($db);
            foreach ($db->query("SELECT location, purchase_date FROM itops_assets WHERE LOWER(category)=" . $db->quote($cat) . " AND status NOT IN ('retired','disposed','lost')")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if (itops_budget_site_for($sites, (string)$a['location']) !== $sid) continue;
                if (!empty($a['purchase_date']) && strtotime($a['purchase_date'] . " +$years years") <= strtotime($fyd['end'])) $n++;
            }
            return $n;
        };
        $L = [];
        $add = function ($name, $pool, $tower, $billing, $qty, $why, $cost = 'opex', $nature = 'run') use (&$L) { $L[] = ['name' => $name, 'pool' => $pool, 'tower' => $tower, 'billing' => $billing, 'qty' => $qty, 'cost_type' => $cost, 'nature' => $nature, 'why' => $why]; };
        if ($office) {
            $add('Internet — primary circuit', 'telecom', 'network', 'monthly', 1, 'One business internet circuit for the office.');
            $add('Internet — backup (second carrier or LTE/5G)', 'telecom', 'network', 'monthly', 1, 'A second path keeps phones and Microsoft 365 working when the main circuit is down.');
            $add('Firewall / network security subscription', 'hardware', 'security', 'annual', 1, 'Security services and support for the office firewall.');
            $add('Network refresh (switch, Wi-Fi access points)', 'hardware', 'network', 'one_time', 0, 'Set the quantity when the office network reaches its refresh cycle (' . (int)$cfg['refresh']['network'] . ' years).', 'capex');
            $add('Printer / copier lease and service', 'outside_services', 'end_user', 'monthly', 1, 'Lease and per-page service for the office copier, if there is one.');
        }
        $add('Phone system seats (per person)', 'telecom', 'telecom', 'monthly', $people, $people ? "$people people of this site with a Microsoft 365 license." : 'Set the number of people; none are matched to this site yet.');
        $add('Mobile phones and hotspots (plans)', 'telecom', 'telecom', 'monthly', $remote ? $people : $phones, $remote ? 'Field staff usually need a plan each.' : ($phones ? "$phones phones recorded in Equipment for this site." : 'Set how many company mobile plans this site has.'));
        if ($office) $add('Fax line / online fax', 'telecom', 'telecom', 'monthly', 1, 'A fax number for the office (online fax counts here too).');
        // BT60: computer and other equipment replacements come from Equipment (IT Budget → Equipment), item by item
        $add('New-hire computers (growth)', 'hardware', 'end_user', 'one_time', 0, 'Set how many people this site will add this year.', 'capex', 'grow');
        $add('Accessories (cables, keyboards, mice, small items)', 'hardware', 'end_user', 'one_time', $people, 'One allowance per person as a starting point. Monitors, docks, headsets and computers that reach their cycle come from Equipment.');
        return $L;
    }

    // ── Equipment replacement plan (BT60) ──────────────────────────────────────────────────────────────────────────

    /** Equipment categories: the ones Equipment offers plus any already used on items, with a label. */
    function itops_budget_equipment_cats(PDO $db): array {
        $base = ['laptop', 'desktop', 'monitor', 'phone', 'tablet', 'headset', 'dock', 'printer', 'projector', 'badge', 'key', 'other'];
        try { foreach ($db->query("SELECT DISTINCT LOWER(TRIM(category)) FROM itops_assets WHERE category <> ''")->fetchAll(PDO::FETCH_COLUMN) as $c) if (preg_match('/^[a-z0-9][a-z0-9 _\/-]{0,39}$/', (string)$c) && !in_array($c, $base, true)) $base[] = (string)$c; } catch (Throwable $e) {}
        return $base;
    }
    function itops_budget_cat_label(string $c, bool $plural = false): string {
        $m = ['laptop' => ['Laptop', 'Laptops'], 'desktop' => ['Desktop', 'Desktops'], 'monitor' => ['Monitor', 'Monitors'], 'phone' => ['Phone', 'Phones'], 'tablet' => ['Tablet', 'Tablets'],
              'headset' => ['Headset', 'Headsets'], 'dock' => ['Dock', 'Docks'], 'printer' => ['Printer', 'Printers'], 'projector' => ['Projector', 'Projectors'], 'badge' => ['Badge', 'Badges'],
              'key' => ['Key', 'Keys'], 'other' => ['Other equipment', 'Other equipment'], 'network' => ['Network equipment', 'Network equipment'], 'server' => ['Server', 'Servers']];
        if (isset($m[$c])) return $m[$c][$plural ? 1 : 0];
        $l = ucfirst($c); return $plural && !preg_match('/s$/', $l) ? $l . 's' : $l;
    }

    /**
     * What Equipment says must be replaced in fiscal year $fy: every item in use (not retired or lost) whose purchase
     * date + the category's refresh cycle falls on or before the last day of the year. Items with no purchase date are
     * counted apart (their age is unknown). Price per category: the price saved in Settings, else the average paid for
     * that category in Equipment over the last three years (else ever), else unknown. Nothing is stored here.
     */
    function itops_budget_equipment_plan(PDO $db, int $fy): array {
        $cfg = itops_budget_cfg($db); $fyd = itops_budget_fy($cfg, $fy); $sites = itops_budget_sites($db); $siteName = [];
        foreach ($sites as $s) $siteName[$s['id']] = $s['name'];
        $cats = itops_budget_equipment_cats($db);
        $rows = [];
        try { $rows = $db->query("SELECT a.id, a.asset_tag, LOWER(TRIM(a.category)) AS cat, a.make, a.model, a.location, a.status, a.purchase_date, a.purchase_cost, a.warranty_expiry, u.display_name AS holder
                                  FROM itops_assets a LEFT JOIN itops_users u ON u.id = a.assigned_to WHERE a.status NOT IN ('retired','lost','disposed') ORDER BY a.purchase_date, a.asset_tag")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        // prices
        $paid = []; $since = date('Y-m-d', strtotime('-3 years'));
        try { foreach ($db->query("SELECT LOWER(TRIM(category)) AS cat, purchase_date, purchase_cost FROM itops_assets WHERE purchase_cost > 0")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $paid[$r['cat']]['all'][] = (float)$r['purchase_cost']; if ($r['purchase_date'] && $r['purchase_date'] >= $since) $paid[$r['cat']]['recent'][] = (float)$r['purchase_cost']; } } catch (Throwable $e) {}
        $price = function (string $c) use ($cfg, $paid): array {
            if (!empty($cfg['replace_price'][$c])) return [(float)$cfg['replace_price'][$c], 'saved', 0];
            foreach (['recent', 'all'] as $k) if (!empty($paid[$c][$k])) return [round(array_sum($paid[$c][$k]) / count($paid[$c][$k]), 2), $k === 'recent' ? 'average_recent' : 'average', count($paid[$c][$k])];
            return [0.0, 'none', 0];
        };
        $C = []; foreach ($cats as $c) { [$p, $pf, $pn] = $price($c); $C[$c] = ['key' => $c, 'label' => itops_budget_cat_label($c, true), 'cycle' => (int)($cfg['refresh'][$c] ?? 0), 'items' => 0, 'dated' => 0, 'due' => 0, 'overdue' => 0, 'unknown' => 0, 'price' => $p, 'price_from' => $pf, 'price_n' => $pn, 'cost' => 0.0, 'saved_price' => $cfg['replace_price'][$c] ?? null]; }
        $items = []; $groups = []; $unknownItems = [];
        foreach ($rows as $a) {
            $c = (string)($a['cat'] ?: 'other'); if (!isset($C[$c])) { [$p, $pf, $pn] = $price($c); $C[$c] = ['key' => $c, 'label' => itops_budget_cat_label($c, true), 'cycle' => (int)($cfg['refresh'][$c] ?? 0), 'items' => 0, 'dated' => 0, 'due' => 0, 'overdue' => 0, 'unknown' => 0, 'price' => $p, 'price_from' => $pf, 'price_n' => $pn, 'cost' => 0.0, 'saved_price' => null]; }
            $C[$c]['items']++; $cyc = $C[$c]['cycle'];
            $sid = itops_budget_site_for($sites, (string)$a['location']);
            if (empty($a['purchase_date'])) { $C[$c]['unknown']++; if ($cyc > 0) $unknownItems[] = ['id' => (int)$a['id'], 'tag' => (string)$a['asset_tag'], 'cat' => $c, 'item' => trim($a['make'] . ' ' . $a['model']), 'location' => (string)$a['location'], 'holder' => (string)($a['holder'] ?? '')]; continue; }
            $C[$c]['dated']++;
            if ($cyc <= 0) continue;
            $on = date('Y-m-d', strtotime($a['purchase_date'] . " +$cyc years"));
            if ($on > $fyd['end']) continue;
            $over = $on < $fyd['start'];
            $C[$c]['due']++; if ($over) $C[$c]['overdue']++; $C[$c]['cost'] += $C[$c]['price'];
            $age = round((strtotime($fyd['start']) - strtotime($a['purchase_date'])) / (365.25 * 86400), 1);
            $items[] = ['id' => (int)$a['id'], 'tag' => (string)$a['asset_tag'], 'cat' => $c, 'item' => trim($a['make'] . ' ' . $a['model']), 'location' => (string)$a['location'], 'site_id' => $sid,
                        'site' => $sid ? ($siteName[$sid] ?? '') : '', 'purchased' => $a['purchase_date'], 'age' => max(0, $age), 'replace_on' => $on, 'overdue' => $over, 'holder' => (string)($a['holder'] ?? ''),
                        'status' => (string)$a['status'], 'price' => $C[$c]['price']];
            $gk = $c . ':' . ($sid ?: 0); $groups[$gk][] = end($items);
        }
        $lines = [];
        foreach ($groups as $gk => $its) {
            [$c, $sid] = explode(':', $gk); $sid = (int)$sid; $cc = $C[$c]; $n = count($its);
            $tags = array_map(fn($i) => $i['tag'] . ' (' . substr((string)$i['purchased'], 0, 4) . ')', array_slice($its, 0, 12));
            $pTxt = $cc['price_from'] === 'saved' ? 'replacement price saved in Settings' : ($cc['price_from'] === 'average_recent' ? 'average paid for ' . $cc['price_n'] . ' in Equipment over the last three years'
                  : ($cc['price_from'] === 'average' ? 'average paid for ' . $cc['price_n'] . ' in Equipment' : 'no price in Equipment yet — enter it'));
            $lines[] = ['name' => itops_budget_cat_label($c) . ' replacement' . ($sid ? ' — ' . ($siteName[$sid] ?? '') : ' — location not matched'), 'pool' => 'hardware',
                'tower' => in_array($c, ['phone'], true) ? 'telecom' : 'end_user', 'nature' => 'run', 'cost_type' => 'capex', 'billing' => 'one_time', 'unit_cost' => $cc['price'], 'qty' => $n, 'months' => 12,
                'site_id' => $sid ?: null, 'source' => 'equipment', 'source_ref' => $c . ':' . $sid, 'renewal_date' => null, 'needs_price' => $cc['price'] > 0 ? 0 : 1, 'vendor_id' => null, 'contract_id' => null,
                'cat' => $c, 'items' => array_map(fn($i) => $i['id'], $its),
                'why' => $n . ' ' . mb_strtolower(itops_budget_cat_label($c, $n !== 1)) . ' reach ' . $cc['cycle'] . ' years by ' . $fyd['end'] . ': ' . implode(', ', $tags) . ($n > 12 ? ' and ' . ($n - 12) . ' more' : '') . '. Price: ' . $pTxt . '.'];
        }
        usort($lines, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($C as &$cc) $cc['cost'] = round($cc['cost'], 2); unset($cc);
        $catList = array_values(array_filter($C, fn($x) => $x['items'] > 0 || $x['cycle'] > 0));
        return ['fy' => $fyd, 'cats' => $catList, 'items' => $items, 'unknown_items' => $unknownItems, 'lines' => $lines,
                'totals' => ['items' => array_sum(array_column($catList, 'items')), 'due' => count($items), 'overdue' => count(array_filter($items, fn($i) => $i['overdue'])),
                             'unknown' => array_sum(array_column($catList, 'unknown')), 'cost' => round(array_sum(array_column($catList, 'cost')), 2),
                             'needs_price' => count(array_filter($catList, fn($x) => $x['due'] > 0 && $x['price'] <= 0))]];
    }

    // ── Vendors and contracts (BT59) ────────────────────────────────────────────────────────────────────────────────

    /**
     * A contract term and the term in force now: a renewed term points to the next one (renewed_to_id), which is
     * followed so a line made against last year's term shows this year's. Returns null when the id is unknown.
     */
    function itops_budget_contract(PDO $db, ?int $id): ?array {
        if (!$id) return null;
        $st = $db->prepare('SELECT c.id, c.title, c.vendor_id, c.contract_type, c.status, c.start_date, c.end_date, c.auto_renew, c.payment_frequency, c.payment_amount, c.total_value, c.series_id, c.term_no, c.renewed_to_id, c.ended_at, v.name AS vendor_name
            FROM itops_contracts c LEFT JOIN itops_vendors v ON v.id = c.vendor_id WHERE c.id = ?');
        $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC); if (!$r) return null;
        $first = $r; $n = 0;
        while (!empty($r['renewed_to_id']) && $n++ < 20) { $st->execute([(int)$r['renewed_to_id']]); $nx = $st->fetch(PDO::FETCH_ASSOC); if (!$nx) break; $r = $nx; }
        $ref = fn($x) => sprintf('CT-%04d', (int)($x['series_id'] ?: $x['id'])) . ((int)$x['term_no'] > 1 ? '-R' . ((int)$x['term_no'] - 1) : '');
        return ['id' => (int)$first['id'], 'current_id' => (int)$r['id'], 'ref' => $ref($r), 'linked_ref' => $ref($first), 'renewed' => (int)$r['id'] !== (int)$first['id'],
                'title' => (string)$r['title'], 'vendor_id' => $r['vendor_id'] === null ? null : (int)$r['vendor_id'], 'vendor_name' => (string)($r['vendor_name'] ?? ''),
                'type' => (string)$r['contract_type'], 'status' => (string)$r['status'], 'start' => $r['start_date'], 'end' => $r['end_date'], 'auto_renew' => (bool)$r['auto_renew'],
                'frequency' => (string)$r['payment_frequency'], 'payment' => $r['payment_amount'] === null ? null : (float)$r['payment_amount'],
                'closed' => in_array($r['status'], ['renewed', 'terminated', 'archived'], true) || !empty($r['ended_at'])];
    }

    /** What a line still lacks to be complete: 'vendor', 'contract'. IT staff (Internal labor) and cut lines need neither. */
    function itops_budget_line_missing(array $l, array $cfg): array {
        if (($l['status'] ?? '') === 'cut' || ($l['pool'] ?? '') === 'internal_labor') return [];
        $m = [];
        if (!empty($cfg['require_vendor']) && empty($l['vendor_id'])) $m[] = 'vendor';
        $rc = $cfg['require_contract'] ?? 'recurring';
        if (empty($l['contract_id']) && ($rc === 'all' || ($rc === 'recurring' && ($l['billing'] ?? '') !== 'one_time'))) $m[] = 'contract';
        return $m;
    }
    function itops_budget_missing_text(array $m): string {
        if ($m === ['vendor']) return 'Choose the vendor (or add a new one) before saving.';
        if ($m === ['contract']) return 'Choose the contract or agreement this cost is paid under (or add a new one) before saving.';
        return 'Choose the vendor and the contract or agreement (or add new ones) before saving.';
    }

    /**
     * How the contract stands for the year of a line: 'ok', 'ends' (it ends inside the year and does not renew by itself:
     * renew or replace it), 'ended' (ended before the year starts), 'closed' (terminated or archived), and whether the
     * line's price differs from the contract's payment for the same billing.
     */
    function itops_budget_contract_flags(?array $c, array $l, array $fyd): array {
        if (!$c) return ['state' => 'none'];
        $state = 'ok';
        if ($c['closed'] && !$c['renewed']) $state = 'closed';
        elseif ($c['end'] && !$c['auto_renew'] && $c['end'] < $fyd['start']) $state = 'ended';
        elseif ($c['end'] && !$c['auto_renew'] && $c['end'] < $fyd['end']) $state = 'ends';
        $diff = null;
        if ($c['payment'] !== null && $c['payment'] > 0 && $c['frequency'] === ($l['billing'] ?? '') && abs($c['payment'] - (float)$l['unit_cost'] * (float)$l['qty']) > 0.009) $diff = $c['payment'];
        return ['state' => $state, 'payment_differs' => $diff];
    }

    // ── Lines ───────────────────────────────────────────────────────────────────────────────────────────────────────

    function itops_budget_line_clean(PDO $db, array $in, ?array $cur): array {
        $o = $cur ?? ['fy' => null, 'name' => '', 'pool' => 'other', 'tower' => '', 'nature' => 'run', 'cost_type' => 'opex', 'billing' => 'annual',
                      'unit_cost' => 0, 'qty' => 1, 'months' => 12, 'site_id' => null, 'vendor_id' => null, 'department_id' => null, 'owner_id' => null,
                      'renewal_date' => null, 'status' => 'planned', 'needs_price' => 0, 'notes' => '', 'contract_id' => null];
        $bad = fn($m) => throw new InvalidArgumentException($m);
        if (array_key_exists('fy', $in)) { if (!is_numeric($in['fy']) || (int)$in['fy'] < 2000 || (int)$in['fy'] > 2100) $bad('Choose the fiscal year.'); $o['fy'] = (int)$in['fy']; }
        if (array_key_exists('name', $in)) { if (!is_string($in['name'])) $bad('The name is text.'); $o['name'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $in['name']) ?? ''), 0, 160); }
        if (array_key_exists('pool', $in)) { if (!isset(itops_budget_pools()[$in['pool']])) $bad('Choose a category.'); $o['pool'] = $in['pool']; }
        if (array_key_exists('tower', $in)) { if ($in['tower'] !== '' && !isset(itops_budget_towers()[$in['tower']])) $bad('Choose what it supports, or leave it empty.'); $o['tower'] = (string)$in['tower']; }
        if (array_key_exists('nature', $in)) { if (!isset(itops_budget_natures()[$in['nature']])) $bad('Run, Grow or Transform.'); $o['nature'] = $in['nature']; }
        if (array_key_exists('cost_type', $in)) { if (!in_array($in['cost_type'], ['opex', 'capex'], true)) $bad('Operating (OpEx) or capital (CapEx).'); $o['cost_type'] = $in['cost_type']; }
        if (array_key_exists('billing', $in)) { if (!isset(itops_budget_billings()[$in['billing']])) $bad('Choose how often it is billed.'); $o['billing'] = $in['billing']; }
        foreach (['unit_cost' => [0, 1e9, 'The price is an amount of 0 or more.'], 'qty' => [0, 1e6, 'The quantity is 0 or more.']] as $k => [$lo, $hi, $m])
            if (array_key_exists($k, $in)) { if (!is_numeric($in[$k]) || (float)$in[$k] < $lo || (float)$in[$k] > $hi) $bad($m); $o[$k] = round((float)$in[$k], 2); }
        if (array_key_exists('months', $in)) { if (!is_numeric($in['months']) || (int)$in['months'] < 1 || (int)$in['months'] > 12) $bad('Months in the year: 1 to 12.'); $o['months'] = (int)$in['months']; }
        foreach (['site_id' => 'itops_budget_sites', 'vendor_id' => 'itops_vendors', 'department_id' => 'itops_departments', 'owner_id' => 'itops_users'] as $k => $tbl) if (array_key_exists($k, $in)) {
            $v = $in[$k]; if ($v === null || $v === '' || $v === 0 || $v === '0') { $o[$k] = null; continue; }
            if (!is_numeric($v)) $bad('That choice is not in the list.');
            $st = $db->prepare("SELECT COUNT(*) FROM $tbl WHERE id=?"); $st->execute([(int)$v]); if (!$st->fetchColumn()) $bad('That choice is not in the list any more. Reload the page.');
            $o[$k] = (int)$v; }
        if (array_key_exists('contract_id', $in)) {   // BT59: the contract or agreement; it must be the vendor's
            $v = $in['contract_id'];
            if ($v === null || $v === '' || $v === 0 || $v === '0') $o['contract_id'] = null;
            else {
                if (!is_numeric($v)) $bad('That contract is not in the list.');
                $c = itops_budget_contract($db, (int)$v); if (!$c) $bad('That contract does not exist any more. Reload the page.');
                if ($c['status'] === 'archived') $bad('That contract is archived. Choose a current one.');
                if (empty($o['vendor_id']) && $c['vendor_id']) $o['vendor_id'] = $c['vendor_id'];
                if ($c['vendor_id'] && (int)$o['vendor_id'] !== $c['vendor_id']) $bad('The contract "' . $c['title'] . '" is with ' . ($c['vendor_name'] ?: 'another vendor') . ', not with the vendor chosen. Choose one of this vendor\'s contracts.');
                $o['contract_id'] = (int)$v;
            }
        } elseif (!empty($o['contract_id']) && array_key_exists('vendor_id', $in)) {
            $c = itops_budget_contract($db, (int)$o['contract_id']);
            if ($c && $c['vendor_id'] && (int)$o['vendor_id'] !== $c['vendor_id']) $bad('The line\'s contract is with ' . ($c['vendor_name'] ?: 'another vendor') . '. Choose a contract of the new vendor too.');
        }
        if (array_key_exists('renewal_date', $in)) { $v = $in['renewal_date']; if ($v === null || $v === '') $o['renewal_date'] = null; elseif (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) $bad('The renewal date is a date.'); else $o['renewal_date'] = $v; }
        if (array_key_exists('status', $in)) { if (!in_array($in['status'], ['planned', 'approved', 'cut'], true)) $bad('Planned, approved or cut.'); $o['status'] = $in['status']; }
        if (array_key_exists('notes', $in)) { if (!is_string($in['notes'])) $bad('Notes are text.'); $o['notes'] = mb_substr(trim($in['notes']), 0, 2000); }
        if (mb_strlen((string)$o['name']) < 2) $bad('Give the line a name (at least two characters).');
        if (!$o['fy']) $bad('Choose the fiscal year.');
        if ($o['billing'] === 'one_time' || $o['billing'] === 'annual') $o['months'] = 12;
        // a price typed clears "needs a price"; a line whose price is still 0 with a quantity keeps it
        if (array_key_exists('unit_cost', $in)) $o['needs_price'] = ((float)$o['unit_cost'] <= 0 && (float)$o['qty'] > 0 && !empty($cur['needs_price'])) ? 1 : 0;
        $o['needs_price'] = !empty($o['needs_price']) ? 1 : 0;   // a flag read back from the table arrives as bool
        $o['amount'] = itops_budget_amount($o);
        return $o;
    }

    function itops_budget_line_row(array $r): array {
        foreach (['id', 'fy', 'months'] as $k) $r[$k] = (int)$r[$k];
        foreach (['site_id', 'vendor_id', 'contract_id', 'department_id', 'owner_id', 'created_by', 'updated_by'] as $k) $r[$k] = ($r[$k] ?? null) === null ? null : (int)$r[$k];
        foreach (['unit_cost', 'qty', 'amount'] as $k) $r[$k] = (float)$r[$k];
        $r['needs_price'] = (bool)$r['needs_price'];
        return $r;
    }

    /** What the Audit Log keeps of a line: the same labels before and after. */
    function itops_budget_line_view(PDO $db, array $l): array {
        static $sites = null, $vend = [];
        if ($sites === null) { $sites = []; try { foreach (itops_budget_sites($db) as $s) $sites[$s['id']] = $s['name']; } catch (Throwable $e) {} }
        $vn = '';
        if (!empty($l['vendor_id'])) { if (!isset($vend[$l['vendor_id']])) { $st = $db->prepare('SELECT name FROM itops_vendors WHERE id=?'); $st->execute([(int)$l['vendor_id']]); $vend[$l['vendor_id']] = (string)$st->fetchColumn(); } $vn = $vend[$l['vendor_id']]; }
        return ['Year' => (string)$l['fy'], 'Name' => (string)$l['name'], 'Category' => itops_budget_pools()[$l['pool']][0] ?? $l['pool'],
                'Supports' => $l['tower'] ? (itops_budget_towers()[$l['tower']] ?? $l['tower']) : '', 'Kind' => (itops_budget_natures()[$l['nature']] ?? $l['nature']) . ' · ' . strtoupper($l['cost_type']),
                'Billing' => itops_budget_billings()[$l['billing']] ?? $l['billing'], 'Price' => number_format((float)$l['unit_cost'], 2, '.', ''), 'Quantity' => rtrim(rtrim(number_format((float)$l['qty'], 2, '.', ''), '0'), '.'),
                'Months' => (string)$l['months'], 'Planned' => number_format((float)$l['amount'], 2, '.', ''), 'Site' => $l['site_id'] ? ($sites[$l['site_id']] ?? ('#' . $l['site_id'])) : 'Whole organization',
                'Vendor' => $vn, 'Contract' => !empty($l['contract_id']) ? (($cc = itops_budget_contract($db, (int)$l['contract_id'])) ? $cc['linked_ref'] . ' ' . $cc['title'] : '#' . $l['contract_id']) : '',
                'Renews' => (string)($l['renewal_date'] ?? ''), 'Status' => (string)$l['status'], 'Notes' => (string)($l['notes'] ?? '')];
    }

    function itops_budget_diff(array $a, array $b): array {
        $o = []; $n = [];
        foreach ($b as $k => $v) if (($a[$k] ?? null) !== $v) { $o[$k] = $a[$k] ?? ''; $n[$k] = $v; }
        return [$o, $n];
    }

    // ── Imports (preview, then apply) ────────────────────────────────────────────────────────────────────────────────

    /** Lines proposed from Microsoft 365: one per paid license with seats. Existing lines of that year are updated. */
    function itops_budget_from_m365(PDO $db, int $fy): array {
        $m = itops_budget_m365($db); $cfg = itops_budget_cfg($db); $fyd = itops_budget_fy($cfg, $fy);
        $link = itops_budget_m365_link($db);
        $out = [];
        foreach ($m['rows'] as $r) {
            if ($r['seats'] <= 0 || $r['free']) continue;
            $ren = null; foreach ($r['subscriptions'] as $s) if (!empty($s['renews']) && ($ren === null || $s['renews'] < $ren)) $ren = substr((string)$s['renews'], 0, 10);
            $out[] = ['name' => $r['name'] . ' (' . $r['seats'] . ' seats)', 'pool' => 'software', 'tower' => 'end_user', 'nature' => 'run', 'cost_type' => 'opex',
                'vendor_id' => $link['vendor_id'], 'contract_id' => $link['contract_id'],
                'billing' => 'monthly', 'unit_cost' => $r['unit_month'] ?? 0, 'qty' => $r['seats'], 'months' => 12, 'source' => 'm365', 'source_ref' => $r['sku_id'],
                'renewal_date' => $ren, 'needs_price' => $r['unit_month'] === null ? 1 : 0,
                'why' => $r['seats'] . ' seats, ' . $r['used'] . ' in use' . ($r['unused'] ? ', ' . $r['unused'] . ' unused' : '') . '. Price: ' . ($r['price']['from'] === 'saved' ? 'saved from the invoice' : ($r['price']['from'] === 'suggested' ? 'Microsoft\'s published ' . $m['pricing'] . ' price — confirm it' : 'unknown — enter it'))];
        }
        return $out;
    }

    /** Lines proposed from Contracts: active terms with a payment amount that run into the year. */
    function itops_budget_from_contracts(PDO $db, int $fy): array {
        $cfg = itops_budget_cfg($db); $fyd = itops_budget_fy($cfg, $fy);
        $st = $db->prepare("SELECT c.id, c.title, c.vendor_id, c.contract_type, c.status, c.start_date, c.end_date, c.auto_renew, c.payment_frequency, c.payment_amount, c.total_value, v.category AS vcat, v.name AS vname
            FROM itops_contracts c LEFT JOIN itops_vendors v ON v.id = c.vendor_id
            WHERE c.status IN ('active','expiring') AND (c.start_date IS NULL OR c.start_date <= ?) AND (c.end_date IS NULL OR c.end_date >= ? OR c.auto_renew = 1)");
        $st->execute([$fyd['end'], $fyd['start']]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $freq = (string)($c['payment_frequency'] ?: 'annual'); if (!isset(itops_budget_billings()[$freq])) $freq = 'annual';
            $amt = (float)($c['payment_amount'] ?? 0);
            if ($amt <= 0 && $freq === 'annual') {   // review OPS-27: the whole contract value spread over its years
                $amt = (float)($c['total_value'] ?? 0);
                if ($amt > 0 && !empty($c['start_date']) && !empty($c['end_date'])) { $yrs = max(1, (int)round((strtotime($c['end_date']) - strtotime($c['start_date'])) / (365.25 * 86400))); $amt = round($amt / $yrs, 2); }
            }
            $vc = mb_strtolower((string)$c['vcat']);
            $pool = 'software'; $tower = 'application';
            if (preg_match('/isp|connectiv|phone|sms|fax|telecom/', $vc)) { $pool = 'telecom'; $tower = preg_match('/isp|connectiv/', $vc) ? 'network' : 'telecom'; }
            elseif (preg_match('/host|infra|cloud platform/', $vc)) { $pool = 'cloud'; $tower = 'compute'; }
            elseif (preg_match('/security/', $vc)) { $pool = 'software'; $tower = 'security'; }
            elseif (in_array($c['contract_type'], ['service', 'maintenance'], true)) { $pool = 'outside_services'; $tower = 'it_mgmt'; }
            // months of the year the contract covers when it ends inside the year and does not renew by itself
            $months = 12;
            if ($freq === 'monthly' && !empty($c['end_date']) && !$c['auto_renew'] && $c['end_date'] < $fyd['end']) { $months = 0; foreach ($fyd['months'] as $ym) if ($ym . '-01' <= $c['end_date']) $months++; $months = max(1, $months); }
            $out[] = ['name' => (string)$c['title'] . ($c['vname'] ? ' — ' . $c['vname'] : ''), 'pool' => $pool, 'tower' => $tower, 'nature' => 'run', 'cost_type' => 'opex',
                'billing' => $freq, 'unit_cost' => round($amt, 2), 'qty' => 1, 'months' => $months, 'vendor_id' => $c['vendor_id'] ? (int)$c['vendor_id'] : null, 'contract_id' => (int)$c['id'],
                'source' => 'contract', 'source_ref' => (string)$c['id'], 'renewal_date' => $c['end_date'] ?: null, 'needs_price' => $amt > 0 ? 0 : 1,
                'why' => 'Contract ' . ($c['end_date'] ? 'ends ' . $c['end_date'] . ($c['auto_renew'] ? ' (renews by itself)' : '') : 'with no end date') . '; ' . (itops_budget_billings()[$freq] ?? $freq) . ' payment ' . ($amt > 0 ? number_format($amt, 2) : 'not recorded') . '.'];
        }
        return $out;
    }

    /** Apply proposed lines: insert, or update the line of the same year and source (only price, quantity, months, renewal). */
    function itops_budget_apply(PDO $db, int $fy, array $lines, array $user, string $label): array {
        $res = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $find = $db->prepare('SELECT * FROM itops_budget_lines WHERE fy=? AND source=? AND source_ref=? AND site_id <=> ? LIMIT 1');
        foreach ($lines as $p) {
            $p['fy'] = $fy; $p['amount'] = itops_budget_amount($p + ['months' => 12]);
            $find->execute([$fy, $p['source'], $p['source_ref'], $p['site_id'] ?? null]); $cur = $find->fetch(PDO::FETCH_ASSOC);
            if ($cur) {
                $cur = itops_budget_line_row($cur);
                $new = array_merge($cur, ['unit_cost' => (float)$p['unit_cost'], 'qty' => (float)$p['qty'], 'months' => (int)($p['months'] ?? 12), 'renewal_date' => $p['renewal_date'] ?? $cur['renewal_date'],
                                          'vendor_id' => $cur['vendor_id'] ?: ($p['vendor_id'] ?? null), 'contract_id' => $cur['contract_id'] ?: ($p['contract_id'] ?? null)]);   // a vendor / contract chosen by a person stays
                if ($cur['needs_price'] && (float)$p['unit_cost'] <= 0) $new['unit_cost'] = $cur['unit_cost'];   // a price typed by a person is not wiped by an unknown one
                $new['amount'] = itops_budget_amount($new); $new['needs_price'] = ((float)$new['unit_cost'] <= 0 && (float)$new['qty'] > 0) ? 1 : 0;
                // BT124 (code review OPS-5): an import that changes the amount of an approved line sends it back to planned
                if (($cur['status'] ?? '') === 'approved' && abs((float)$new['amount'] - (float)$cur['amount']) >= 0.01) $new['status'] = 'planned';
                [$o, $n] = itops_budget_diff(itops_budget_line_view($db, $cur), itops_budget_line_view($db, $new));
                if (!$n) { $res['unchanged']++; continue; }
                $db->prepare('UPDATE itops_budget_lines SET unit_cost=?, qty=?, months=?, renewal_date=?, amount=?, needs_price=?, vendor_id=?, contract_id=?, status=?, updated_by=? WHERE id=?')
                   ->execute([$new['unit_cost'], $new['qty'], $new['months'], $new['renewal_date'], $new['amount'], $new['needs_price'], $new['vendor_id'], $new['contract_id'], $new['status'], (int)$user['id'], $cur['id']]);
                audit_log('budget_line_save', 'budget', $cur['id'], $cur['name'] . ' (' . $label . ')', $o, $n);
                $res['updated']++;
            } else {
                $db->prepare('INSERT INTO itops_budget_lines (fy,name,pool,tower,nature,cost_type,billing,unit_cost,qty,months,amount,site_id,vendor_id,contract_id,source,source_ref,renewal_date,needs_price,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$fy, $p['name'], $p['pool'], $p['tower'], $p['nature'], $p['cost_type'], $p['billing'], (float)$p['unit_cost'], (float)$p['qty'], (int)($p['months'] ?? 12), $p['amount'],
                              $p['site_id'] ?? null, $p['vendor_id'] ?? null, $p['contract_id'] ?? null, $p['source'], $p['source_ref'], $p['renewal_date'] ?? null, (int)($p['needs_price'] ?? 0), (string)($p['why'] ?? ''), (int)$user['id']]);
                $id = (int)$db->lastInsertId();
                audit_log('budget_line_add', 'budget', $id, $p['name'] . ' (' . $label . ')', null, itops_budget_line_view($db, $p + ['site_id' => $p['site_id'] ?? null, 'vendor_id' => $p['vendor_id'] ?? null, 'contract_id' => $p['contract_id'] ?? null, 'status' => 'planned', 'notes' => (string)($p['why'] ?? '')]));
                $res['created']++;
            }
        }
        return $res;
    }

    // ── Summary and benchmarks ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Reference figures shown next to the organization's own. Each with its source and what it does and does not
     * count — a benchmark is a question to ask, not a target.
     */
    function itops_budget_benchmarks(): array {
        return [
            ['key' => 'np_pct', 'label' => 'Nonprofits: IT as a share of the operating budget', 'value' => 'median about 1.1% (middle half 0.5%–2.1%, organizations of $3M–$50M)',
             'note' => 'From IRS Form 990 filings (tax year 2023). That IT line excludes IT staff pay, telecom and depreciation, so compare it with your budget without Internal labor and Telecom.',
             'source' => ['Scottship Solutions, Nonprofit IT Spending Benchmark 2026', 'https://scottshipsolutions.com/research/it-spending-benchmark-2026/']],
            ['key' => 'np_emp', 'label' => 'Nonprofits: IT spend per employee', 'value' => 'median about $1,281 per W-2 employee ($3M–$50M organizations)', 'per_employee' => 1281,
             'note' => 'Same source and the same exclusions (no IT staff pay, telecom or depreciation).',
             'source' => ['Scottship Solutions, Nonprofit IT Spending Benchmark 2026', 'https://scottshipsolutions.com/research/it-spending-benchmark-2026/']],
            ['key' => 'growth', 'label' => 'Yearly change of IT operating budgets', 'value' => 'median +3.0% (second year in a row)',
             'note' => 'Used as the default "yearly increase" when a year is copied forward. Change it in Settings.',
             'source' => ['Avasant, IT Spending and Staffing Benchmarks 2025/2026', 'https://avasant.com/?p=229871']],
            ['key' => 'security', 'label' => 'Security as a share of the IT budget', 'value' => 'about 5.4% (up from 1.5% ten years ago)',
             'source' => ['Avasant, IT Spending and Staffing Benchmarks 2025/2026', 'https://avasant.com/?p=229871']],
            ['key' => 'personnel', 'label' => 'IT staff as a share of the IT budget', 'value' => 'about 30.8% (down from 40.9% ten years ago)',
             'note' => 'Organizations that buy managed services instead of hiring show this cost under Outside services.',
             'source' => ['Avasant, IT Spending and Staffing Benchmarks 2025/2026', 'https://avasant.com/?p=229871']],
            ['key' => 'run', 'label' => 'Run vs. Grow and Transform', 'value' => 'often cited: about 70% of IT spend keeps things running',
             'note' => 'A rule of thumb repeated across the industry, not one measured figure. Watch the trend of your own share year to year.', 'source' => null],
            ['key' => 'contingency', 'label' => 'Contingency', 'value' => 'commonly 5%–10% of the total, for price increases and failures nobody planned',
             'note' => 'Set yours in Settings; it is added on top of the lines.', 'source' => null],
            ['key' => 'm365', 'label' => 'Microsoft 365 prices', 'value' => 'changed on July 1, 2026; existing subscriptions move at their first renewal after that date; monthly billing of an annual plan costs 5% more',
             'source' => ['TechSoup: Microsoft cloud license prices (nonprofit)', 'https://blog.techsoup.org/en-ca/posts/microsoft-cloud-license-prices-are-increasing-on-july-1-2027']],
        ];
    }

    function itops_budget_summary(PDO $db, int $fy): array {
        $cfg = itops_budget_cfg($db); $fyd = itops_budget_fy($cfg, $fy);
        $st = $db->prepare('SELECT * FROM itops_budget_lines WHERE fy=?'); $st->execute([$fy]);
        $lines = array_map('itops_budget_line_row', $st->fetchAll(PDO::FETCH_ASSOC));
        $live = array_values(array_filter($lines, fn($l) => $l['status'] !== 'cut'));
        $sum = fn(array $ls) => round(array_sum(array_column($ls, 'amount')), 2);
        $by = function (string $k, array $names) use ($live) { $o = []; foreach ($live as $l) { $kk = (string)($l[$k] ?? ''); $o[$kk] = ($o[$kk] ?? 0) + $l['amount']; }
            $r = []; foreach ($o as $kk => $v) $r[] = ['key' => $kk, 'label' => $names[$kk] ?? ($kk === '' ? 'Not set' : $kk), 'amount' => round($v, 2)]; usort($r, fn($a, $b) => $b['amount'] <=> $a['amount']); return $r; };
        $pools = array_map(fn($p) => $p[0], itops_budget_pools());
        $siteNames = ['' => 'Whole organization']; foreach (itops_budget_sites($db) as $s) $siteNames[(string)$s['id']] = $s['name'];
        $planned = $sum($live); $cont = round($planned * (float)$cfg['contingency_pct'] / 100, 2);
        // actuals
        $sa = $db->prepare('SELECT * FROM itops_budget_actuals WHERE fy=?'); $sa->execute([$fy]); $acts = $sa->fetchAll(PDO::FETCH_ASSOC);
        $actTotal = round(array_sum(array_map(fn($a) => (float)$a['amount'], $acts)), 2);
        $actPool = []; foreach ($acts as $a) $actPool[$a['pool']] = ($actPool[$a['pool']] ?? 0) + (float)$a['amount'];
        $byPool = $by('pool', $pools); $seen = array_column($byPool, 'key');
        foreach ($actPool as $k => $v) if (!in_array($k, $seen, true)) $byPool[] = ['key' => $k, 'label' => $pools[$k] ?? $k, 'amount' => 0.0];
        foreach ($byPool as &$p) { $p['actual'] = round($actPool[$p['key']] ?? 0, 2); } unset($p);
        $monthly = []; foreach ($fyd['months'] as $ym) $monthly[$ym] = 0.0; foreach ($acts as $a) if (isset($monthly[$a['period']])) $monthly[$a['period']] += (float)$a['amount'];
        // elapsed share of the year (for a straight-line expectation)
        $now = date('Y-m'); $elapsed = 0; foreach ($fyd['months'] as $ym) if ($ym <= $now) $elapsed++;
        $opex = $sum(array_filter($live, fn($l) => $l['cost_type'] === 'opex'));
        $run = $sum(array_filter($live, fn($l) => $l['nature'] === 'run'));
        $sec = $sum(array_filter($live, fn($l) => $l['tower'] === 'security'));
        $labor = $sum(array_filter($live, fn($l) => $l['pool'] === 'internal_labor'));
        $tele = $sum(array_filter($live, fn($l) => $l['pool'] === 'telecom'));
        $total = round($planned + $cont, 2);
        $hc = $cfg['headcount']; $ob = $cfg['operating_budget'];
        $kpi = [
            'planned' => $planned, 'contingency' => $cont, 'total' => $total, 'approved' => $sum(array_filter($live, fn($l) => $l['status'] === 'approved')),
            'actual' => $actTotal, 'expected_to_date' => round($planned * $elapsed / 12, 2), 'elapsed_months' => $elapsed,
            'opex' => $opex, 'capex' => round($planned - $opex, 2),
            'run_pct' => $planned > 0 ? round($run / $planned * 100, 1) : null,
            'security_pct' => $planned > 0 ? round($sec / $planned * 100, 1) : null,
            'labor_pct' => $planned > 0 ? round($labor / $planned * 100, 1) : null,
            'per_employee' => $hc ? round($total / $hc, 2) : null,
            'per_employee_comparable' => $hc ? round(($total - $labor - $tele) / $hc, 2) : null,
            'pct_operating' => $ob ? round($total / $ob * 100, 2) : null,
            'pct_operating_comparable' => $ob ? round(($total - $labor - $tele) / $ob * 100, 2) : null,
            'lines' => count($lines), 'cut' => count($lines) - count($live), 'needs_price' => count(array_filter($live, fn($l) => $l['needs_price'])),
            'incomplete' => count(array_filter($live, fn($l) => itops_budget_line_missing($l, $cfg))),
        ];
        // renewals inside the year
        $ren = array_values(array_filter($live, fn($l) => $l['renewal_date'] && $l['renewal_date'] >= $fyd['start'] && $l['renewal_date'] <= $fyd['end']));
        usort($ren, fn($a, $b) => strcmp($a['renewal_date'], $b['renewal_date']));
        // the year before, for the change
        $sp = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM itops_budget_lines WHERE fy=? AND status<>'cut'"); $sp->execute([$fy - 1]); $prev = (float)$sp->fetchColumn();
        return ['fy' => $fyd, 'cfg' => $cfg, 'kpi' => $kpi, 'prev_planned' => $prev ?: null,
                'by_pool' => $byPool, 'by_tower' => $by('tower', itops_budget_towers()), 'by_nature' => $by('nature', itops_budget_natures()),
                'by_site' => $by('site_id', $siteNames), 'by_month' => array_map(fn($k, $v) => ['month' => $k, 'actual' => round($v, 2)], array_keys($monthly), $monthly),
                'renewals' => array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'date' => $l['renewal_date'], 'amount' => $l['amount']], $ren),
                'benchmarks' => itops_budget_benchmarks()];
    }
}
