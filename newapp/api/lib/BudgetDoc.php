<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — the IT budget as a formal document (BT60), in PDF and Word, from the same content.
 *
 * Sections (each can be left out): executive summary, budget by category with the year before, Run/Grow/Transform
 * and operating/capital, offices, Microsoft 365 licenses, equipment replacement plan, contracts and renewals,
 * vendors, benchmarks with their sources, spend to date, assumptions and notes, approval (signatures) and the line
 * detail as an appendix. Every figure comes from the budget lines (amounts computed by the server) — nothing is typed
 * into the document that is not in the budget, except the text a person writes (introduction, names).
 *
 * The options a person chooses for a year (status, version, who it is for, introduction, approvers, sections) are kept
 * in opsh_settings.budget_doc ({fy: options}) so the next document starts from them; a change is audited.
 */
require_once __DIR__ . '/Budget.php';
require_once __DIR__ . '/DocExport.php';

if (!function_exists('opsh_budget_document')) {

    function opsh_budget_doc_sections(): array {
        return ['summary' => 'Executive summary', 'categories' => 'Budget by category', 'mix' => 'Run / Grow / Transform and operating / capital', 'offices' => 'Offices',
                'm365' => 'Microsoft 365 licenses', 'equipment' => 'Equipment replacement plan', 'contracts' => 'Contracts and renewals', 'vendors' => 'Vendors',
                'benchmarks' => 'Benchmarks', 'spend' => 'Spend to date', 'assumptions' => 'Assumptions and notes', 'approval' => 'Approval', 'detail' => 'Appendix: line detail'];
    }
    function opsh_budget_doc_statuses(): array {
        return ['draft' => 'Draft for review', 'proposed' => 'Proposed for approval', 'approved' => 'Approved', 'final' => 'Final'];
    }
    function opsh_budget_doc_defaults(array $user): array {
        return ['status' => 'draft', 'version' => '1.0', 'title' => '', 'prepared_for' => 'Executive Leadership', 'prepared_by' => trim((string)($user['display_name'] ?? '')),
                'prepared_by_title' => trim((string)($user['job_title'] ?? '')) ?: 'IT', 'intro' => '', 'detail_group' => 'category',
                'sections' => array_keys(opsh_budget_doc_sections()),
                'approvers' => [['Prepared by', trim((string)($user['display_name'] ?? '')), ''], ['Reviewed by', '', 'Finance'], ['Approved by', '', 'Executive Director / CEO']]];
    }
    function opsh_budget_doc_saved(PDO $db, int $fy): ?array {
        try { $s = $db->prepare("SELECT sval FROM opsh_settings WHERE skey='budget_doc'"); $s->execute(); $j = json_decode((string)$s->fetchColumn(), true); } catch (Throwable $e) { $j = null; }
        return is_array($j[(string)$fy] ?? null) ? $j[(string)$fy] : null;
    }
    /** Check the options a person sends. Throws InvalidArgumentException with a sentence. */
    function opsh_budget_doc_clean(array $in, array $base): array {
        $o = $base; $bad = fn($m) => throw new InvalidArgumentException($m);
        foreach (['title' => 160, 'version' => 30, 'prepared_for' => 160, 'prepared_by' => 120, 'prepared_by_title' => 120, 'intro' => 4000] as $k => $max) if (array_key_exists($k, $in)) {
            if (!is_string($in[$k])) $bad('The document options are not in the expected form.');
            $v = $k === 'intro' ? trim(str_replace("\r", '', $in[$k])) : trim(preg_replace('/\s+/u', ' ', $in[$k]) ?? '');
            if (mb_strlen($v) > $max) $bad(['title' => 'The title', 'version' => 'The version', 'prepared_for' => '"Prepared for"', 'prepared_by' => '"Prepared by"', 'prepared_by_title' => 'The title of the person who prepared it', 'intro' => 'The introduction'][$k] . " is longer than $max characters.");
            $o[$k] = $v;
        }
        if (array_key_exists('status', $in)) { if (!isset(opsh_budget_doc_statuses()[$in['status']])) $bad('Choose the status of the document.'); $o['status'] = $in['status']; }
        if (array_key_exists('detail_group', $in)) { if (!in_array($in['detail_group'], ['category', 'office'], true)) $bad('The line detail is grouped by category or by office.'); $o['detail_group'] = $in['detail_group']; }
        if (array_key_exists('sections', $in)) {
            if (!is_array($in['sections'])) $bad('Choose the sections.');
            $o['sections'] = array_values(array_intersect(array_keys(opsh_budget_doc_sections()), array_map('strval', $in['sections'])));
            if (!$o['sections']) $bad('Choose at least one section.');
        }
        if (array_key_exists('approvers', $in)) {
            if (!is_array($in['approvers']) || count($in['approvers']) > 6) $bad('Up to six people sign the document.');
            $a = [];
            foreach ($in['approvers'] as $r) {
                if (!is_array($r)) $bad('The approvers are not in the expected form.');
                $row = []; foreach ([0, 1, 2] as $i) { $v = $r[$i] ?? ''; if (!is_string($v)) $bad('The approvers are not in the expected form.'); $row[] = mb_substr(trim(preg_replace('/\s+/u', ' ', $v) ?? ''), 0, 100); }
                if ($row[0] === '' && $row[1] === '' && $row[2] === '') continue;
                if ($row[0] === '') $row[0] = 'Approved by';
                $a[] = $row;
            }
            $o['approvers'] = $a;
        }
        return $o;
    }

    /** The document for fiscal year $fy. */
    function opsh_budget_document(PDO $db, int $fy, array $opt, array $user): OpshDoc {
        $sum = opsh_budget_summary($db, $fy); $cfg = $sum['cfg']; $k = $sum['kpi']; $fyd = $sum['fy']; $cur = $cfg['currency'];
        $M = fn(?float $v, bool $c = false) => opsh_doc_money($v, $cur, $c);
        $pools = opsh_budget_pools(); $natures = opsh_budget_natures(); $bill = opsh_budget_billings();
        $brand = opsh_doc_brand($db); $on = array_flip($opt['sections']);
        $status = opsh_budget_doc_statuses()[$opt['status']] ?? '';
        $title = $opt['title'] !== '' ? $opt['title'] : 'IT Budget ' . $fyd['label'];
        $fmtD = fn(?string $d) => $d ? date('m/d/Y', strtotime(substr($d, 0, 10))) : '';

        // lines with names
        $st = $db->prepare('SELECT l.*, s.name AS site_name, v.name AS vendor_name FROM opsh_budget_lines l LEFT JOIN opsh_budget_sites s ON s.id = l.site_id LEFT JOIN opsh_vendors v ON v.id = l.vendor_id WHERE l.fy = ? ORDER BY l.pool, l.amount DESC, l.name');
        $st->execute([$fy]); $all = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $l = opsh_budget_line_row($r); $l['site_name'] = (string)($r['site_name'] ?? ''); $l['vendor_name'] = (string)($r['vendor_name'] ?? ''); $all[] = $l; }
        $live = array_values(array_filter($all, fn($l) => $l['status'] !== 'cut'));
        $planned = (float)$k['planned']; $pct = fn(float $v) => $planned > 0 ? opsh_doc_pct($v / $planned * 100) : '—';
        $prevPool = []; $pp = $db->prepare("SELECT pool, SUM(amount) FROM opsh_budget_lines WHERE fy=? AND status<>'cut' GROUP BY pool"); $pp->execute([$fy - 1]);
        foreach ($pp->fetchAll(PDO::FETCH_NUM) as [$p, $v]) $prevPool[$p] = (float)$v;
        $prev = $sum['prev_planned']; $prevLabel = opsh_budget_fy($cfg, $fy - 1)['label'];
        $change = ($prev && $prev > 0) ? ($planned - $prev) / $prev * 100 : null;

        $d = new OpshDoc(['title' => $title, 'subtitle' => 'Information technology operating and capital budget · ' . $fyd['label'] . ' (' . $fmtD($fyd['start']) . ' – ' . $fmtD($fyd['end']) . ')',
            'org' => $brand['org'], 'logo' => $brand['logo'], 'footer' => $brand['footer'], 'accent' => $brand['accent'], 'status' => $status, 'watermark' => $opt['status'] === 'draft' ? 'Draft' : '',
            'header' => $title . ($opt['version'] !== '' ? ' · v' . $opt['version'] : ''), 'author' => $opt['prepared_by'], 'producer' => function_exists('opsh_app_name') ? opsh_app_name() : 'OpsHub',
            'cover' => [['Prepared for', $opt['prepared_for']], ['Prepared by', trim($opt['prepared_by'] . ($opt['prepared_by_title'] !== '' ? ', ' . $opt['prepared_by_title'] : ''), ', ')],
                        ['Fiscal year', $fyd['label'] . ' · ' . $fmtD($fyd['start']) . ' – ' . $fmtD($fyd['end'])], ['Version', trim(($opt['version'] !== '' ? $opt['version'] . ' · ' : '') . date('m/d/Y'))]],
            'date' => date('m/d/Y')]);

        // ── Executive summary
        if (isset($on['summary'])) {
            $d->h1('Executive summary');
            $top = $sum['by_pool'][0] ?? null;
            $intro = $opt['intro'] !== '' ? $opt['intro'] : 'This document presents the proposed information technology budget for ' . $fyd['label'] . ', from ' . $fmtD($fyd['start']) . ' to ' . $fmtD($fyd['end']) . '. '
                . 'It requests ' . $M((float)$k['total']) . ': ' . $M($planned) . ' of planned costs in ' . count($live) . ' budget lines and a contingency of ' . $M((float)$k['contingency']) . ' (' . opsh_doc_pct((float)$cfg['contingency_pct'], 0) . ').'
                . ($change !== null ? ' Planned costs ' . ($change >= 0 ? 'increase' : 'decrease') . ' by ' . opsh_doc_pct(abs($change)) . ' from ' . $prevLabel . ' (' . $M($prev) . ').' : '')
                . ' Each line names its vendor and, for recurring costs, the contract or agreement it is paid under, so every amount can be traced to who is paid and why.';
            foreach (preg_split('/\n\s*\n/', $intro) as $para) $d->p($para);
            $d->kpis([['Total request', $M((float)$k['total']), 'Planned + ' . opsh_doc_pct((float)$cfg['contingency_pct'], 0) . ' contingency'],
                      ['Planned costs', $M($planned), count($live) . ' lines'],
                      ['Change from ' . $prevLabel, $change === null ? '—' : ($change >= 0 ? '+' : '') . opsh_doc_pct($change), $prev ? $M($planned - $prev) . ($planned >= $prev ? ' more' : ' less') : 'No earlier year in the app'],
                      ['Operating / capital', $planned > 0 ? round((float)$k['opex'] / $planned * 100) . '% / ' . round((float)$k['capex'] / $planned * 100) . '%' : '—', $M((float)$k['opex']) . ' / ' . $M((float)$k['capex'])]]);
            $pts = [];
            if ($top && $top['amount'] > 0) $pts[] = $top['label'] . ' is the largest category: ' . $M($top['amount']) . ' (' . $pct($top['amount']) . ' of planned costs).';
            $m365 = array_sum(array_map(fn($l) => $l['amount'], array_filter($live, fn($l) => $l['source'] === 'm365')));
            if ($m365 > 0) $pts[] = 'Microsoft 365 licenses: ' . $M($m365) . ' (' . $pct($m365) . ').';
            $eq = array_filter($live, fn($l) => $l['source'] === 'equipment');
            if ($eq) $pts[] = 'Equipment replacement: ' . (int)array_sum(array_column($eq, 'qty')) . ' items that reach their refresh cycle, ' . $M(array_sum(array_column($eq, 'amount'))) . '.';
            if ($sum['renewals']) { $nr = count($sum['renewals']); $pts[] = $nr . ' renewal' . ($nr === 1 ? ' falls' : 's fall') . ' inside the year (see Contracts and renewals).'; }
            if ($k['run_pct'] !== null) $pts[] = opsh_doc_pct((float)$k['run_pct'], 0) . ' of planned costs keep current services running; the rest grows or transforms them.';
            if ($k['needs_price']) $pts[] = $k['needs_price'] . ' line' . ($k['needs_price'] === 1 ? ' still needs a price and is' : 's still need a price and are') . ' not counted yet.';
            $d->bullets($pts);
        }
        // ── Categories
        if (isset($on['categories'])) {
            $d->h1('Budget by category');
            $d->p('Costs are grouped in the cost pools of the Technology Business Management (TBM) taxonomy, so they compare with other organizations and with earlier years.');
            $rows = []; $bars = [];
            foreach ($sum['by_pool'] as $p) {
                if ($p['amount'] <= 0 && empty($prevPool[$p['key']])) continue;
                $pv = $prevPool[$p['key']] ?? null; $ch = ($pv && $pv > 0) ? ($p['amount'] - $pv) / $pv * 100 : null;
                $rows[] = [$p['label'], $pv !== null ? $M($pv) : '—', $M($p['amount']), $ch === null ? '—' : ($ch >= 0 ? '+' : '') . opsh_doc_pct($ch), $pct($p['amount'])];
                if ($p['amount'] > 0) $bars[] = [$p['label'], $p['amount'], $M($p['amount'])];
            }
            $rows[] = ['cells' => ['Planned costs', $prev ? $M($prev) : '—', $M($planned), $change === null ? '—' : ($change >= 0 ? '+' : '') . opsh_doc_pct($change), $planned > 0 ? '100%' : '—'], 'style' => 'total'];
            $rows[] = ['cells' => ['Contingency (' . opsh_doc_pct((float)$cfg['contingency_pct'], 0) . ')', '', $M((float)$k['contingency']), '', ''], 'style' => 'muted'];
            $rows[] = ['cells' => ['Total request', '', $M((float)$k['total']), '', ''], 'style' => 'total'];
            $d->table([['label' => 'Category', 'w' => 3.2], ['label' => $prevLabel, 'w' => 1.5, 'align' => 'R'], ['label' => $fyd['label'], 'w' => 1.5, 'align' => 'R'], ['label' => 'Change', 'w' => 1.1, 'align' => 'R'], ['label' => 'Share', 'w' => 1, 'align' => 'R']], $rows);
            if ($bars) { $d->h2('Where the money goes'); $d->bars($bars); }
        }
        // ── Mix
        if (isset($on['mix'])) {
            $d->h1('Run / Grow / Transform and operating / capital');
            $d->p('Run keeps today\'s services working; Grow adds capacity (more people, more offices); Transform changes how the organization works. Operating costs (OpEx) are spent in the year; capital costs (CapEx) buy equipment used for several years.');
            $rows = []; foreach ($sum['by_nature'] as $n) $rows[] = [$n['label'], $M($n['amount']), $pct($n['amount'])];
            $d->table([['label' => 'Purpose', 'w' => 3], ['label' => 'Planned', 'w' => 1.5, 'align' => 'R'], ['label' => 'Share', 'w' => 1, 'align' => 'R']], $rows);
            $d->table([['label' => 'Kind of cost', 'w' => 3], ['label' => 'Planned', 'w' => 1.5, 'align' => 'R'], ['label' => 'Share', 'w' => 1, 'align' => 'R']],
                [['Operating (OpEx)', $M((float)$k['opex']), $pct((float)$k['opex'])], ['Capital (CapEx)', $M((float)$k['capex']), $pct((float)$k['capex'])]]);
            $tw = []; foreach ($sum['by_tower'] as $t) if ($t['amount'] > 0) $tw[] = [$t['label'], $t['amount'], $M($t['amount'])];
            if ($tw) { $d->h2('What the costs support'); $d->bars($tw); }
        }
        // ── Offices
        if (isset($on['offices'])) {
            $d->h1('Offices');
            $sites = opsh_budget_sites($db); $counts = []; try { $counts = opsh_budget_site_counts($db, $sites); } catch (Throwable $e) {}
            $bySite = []; foreach ($live as $l) { $kk = (int)($l['site_id'] ?? 0); $bySite[$kk]['amount'] = ($bySite[$kk]['amount'] ?? 0) + $l['amount']; $bySite[$kk]['tele'] = ($bySite[$kk]['tele'] ?? 0) + ($l['pool'] === 'telecom' ? $l['amount'] : 0); $bySite[$kk]['hw'] = ($bySite[$kk]['hw'] ?? 0) + ($l['pool'] === 'hardware' ? $l['amount'] : 0); }
            $rows = []; $none = [];
            foreach ($sites as $s) {
                $b = $bySite[$s['id']] ?? null; if (!$s['active'] && !$b) continue;
                if (!$b || $b['amount'] <= 0) { $none[] = $s['name']; continue; }
                $ppl = (int)($counts['people'][$s['id']] ?? 0);
                $rows[] = [$s['name'] . ($s['address'] ? "\n" . $s['address'] : ''), $ppl ?: '—', $M($b['tele'] ?? 0), $M($b['hw'] ?? 0), $M($b['amount'] ?? 0), ($ppl && !empty($b['amount'])) ? $M($b['amount'] / $ppl) : '—'];
            }
            if (!empty($bySite[0])) $rows[] = ['Whole organization (not tied to one office)', '', $M($bySite[0]['tele']), $M($bySite[0]['hw']), $M($bySite[0]['amount']), ''];
            $d->p('Each office\'s IT communications (internet, phones, mobile plans, fax) and equipment, with the people of the office (Microsoft 365 accounts with a license, matched by office or city).');
            $d->table([['label' => 'Office', 'w' => 3.4], ['label' => 'People', 'w' => 0.8, 'align' => 'R'], ['label' => 'Communications', 'w' => 1.4, 'align' => 'R'], ['label' => 'Equipment', 'w' => 1.3, 'align' => 'R'], ['label' => 'All lines', 'w' => 1.3, 'align' => 'R'], ['label' => 'Per person', 'w' => 1.1, 'align' => 'R']], $rows, ['empty' => 'No budget lines are tied to an office yet.']);
            if ($none) $d->p('No lines yet for: ' . implode(', ', $none) . '.', ['color' => '6B7A88', 'size' => 8.8]);
        }
        // ── Microsoft 365
        if (isset($on['m365'])) {
            $ml = array_values(array_filter($live, fn($l) => $l['source'] === 'm365'));
            $d->h1('Microsoft 365 licenses');
            if ($ml) {
                $rows = []; $tot = 0; foreach ($ml as $l) { $tot += $l['amount']; $rows[] = [preg_replace('/ \(\d+ seats\)$/', '', $l['name']), rtrim(rtrim(number_format($l['qty'], 2), '0'), '.'), $M($l['unit_cost'], true) . ($l['billing'] === 'monthly' ? ' / month' : ''), $l['renewal_date'] ? $fmtD($l['renewal_date']) : '—', $l['needs_price'] ? 'needs a price' : $M($l['amount'])]; }
                $rows[] = ['cells' => ['Microsoft 365', '', '', '', $M($tot)], 'style' => 'total'];
                $d->table([['label' => 'License', 'w' => 3.4], ['label' => 'Seats', 'w' => 0.8, 'align' => 'R'], ['label' => 'Price per user', 'w' => 1.6, 'align' => 'R'], ['label' => 'Renews', 'w' => 1.2], ['label' => 'Year', 'w' => 1.3, 'align' => 'R']], $rows);
                $d->note('Prices: the price saved from the invoice, else Microsoft\'s published ' . $cfg['pricing'] . ' price in effect from July 1, 2026. Seats are the seats bought; unused seats can be removed at renewal to lower the cost.');
            } else $d->p('No Microsoft 365 license lines in this budget yet.', ['color' => '6B7A88']);
        }
        // ── Equipment
        if (isset($on['equipment'])) {
            $d->h1('Equipment replacement plan');
            $plan = opsh_budget_equipment_plan($db, $fy); $t = $plan['totals'];
            $d->p('From the equipment recorded in the app: every item in use whose purchase date plus its refresh cycle falls on or before ' . $fmtD($fyd['end']) . ' is due for replacement in ' . $fyd['label'] . '.');
            $d->kpis([['Items recorded', (string)$t['items'], 'In use, on loan or in repair'], ['Due in ' . $fyd['label'], (string)$t['due'], $t['overdue'] ? $t['overdue'] . ' already past their cycle' : 'None past due'],
                      ['Estimated cost', $M((float)$t['cost']), $t['needs_price'] ? 'Some kinds have no price yet' : 'At the prices shown'], ['Age unknown', (string)$t['unknown'], 'No purchase date recorded']]);
            $rows = [];
            foreach ($plan['cats'] as $c) if ($c['items'] > 0) $rows[] = [$c['label'], $c['cycle'] > 0 ? $c['cycle'] . ' years' : 'not on a cycle', (string)$c['items'], (string)$c['due'], $c['price'] > 0 ? $M($c['price']) : '—', $c['due'] ? ($c['price'] > 0 ? $M($c['cost']) : 'needs a price') : '—'];
            $d->table([['label' => 'Equipment', 'w' => 2.4], ['label' => 'Refresh cycle', 'w' => 1.4], ['label' => 'Items', 'w' => 0.8, 'align' => 'R'], ['label' => 'Due', 'w' => 0.8, 'align' => 'R'], ['label' => 'Price each', 'w' => 1.2, 'align' => 'R'], ['label' => 'Cost', 'w' => 1.3, 'align' => 'R']], $rows, ['empty' => 'No equipment recorded.']);
            $inB = array_sum(array_map(fn($l) => $l['amount'], array_filter($live, fn($l) => $l['source'] === 'equipment')));
            $d->p('In the budget lines: ' . $M($inB) . ' for equipment replacement.' . ($t['unknown'] ? ' ' . $t['unknown'] . ' items have no purchase date, so their replacement cannot be planned until it is recorded.' : ''), ['color' => '4A5A68', 'size' => 9]);
        }
        // ── Contracts and renewals
        if (isset($on['contracts'])) {
            $d->h1('Contracts and renewals');
            $withC = array_values(array_filter($live, fn($l) => !empty($l['contract_id'])));
            $rows = [];
            foreach ($withC as $l) {
                $c = opsh_budget_contract($db, (int)$l['contract_id']); if (!$c) continue; $fl = opsh_budget_contract_flags($c, $l, $fyd);
                $note = ['ends' => 'ends in the year, does not renew by itself', 'ended' => 'ended before the year', 'closed' => 'terminated or archived', 'ok' => ''][$fl['state']] ?? '';
                $rows[] = [$c['ref'] . ' ' . $c['title'], $l['vendor_name'] ?: $c['vendor_name'], $c['end'] ? $fmtD($c['end']) . ($c['auto_renew'] ? ' (auto)' : '') : 'no end date', $M($l['amount']), $note];
            }
            usort($rows, fn($a, $b) => strcmp((string)$a[0], (string)$b[0]));
            $d->p(count($withC) . ' of ' . count($live) . ' lines are paid under a contract or agreement recorded in Contracts.');
            $d->table([['label' => 'Contract or agreement', 'w' => 3], ['label' => 'Vendor', 'w' => 1.8], ['label' => 'Term ends', 'w' => 1.4], ['label' => 'In this budget', 'w' => 1.3, 'align' => 'R'], ['label' => 'Attention', 'w' => 1.7]], $rows, ['empty' => 'No lines name a contract yet.']);
            if ($sum['renewals']) {
                $d->h2('Renewals inside the year');
                $d->table([['label' => 'Date', 'w' => 1.2], ['label' => 'Budget line', 'w' => 4], ['label' => 'Amount', 'w' => 1.3, 'align' => 'R']], array_map(fn($r) => [$fmtD($r['date']), $r['name'], $M($r['amount'])], $sum['renewals']));
            }
        }
        // ── Vendors
        if (isset($on['vendors'])) {
            $d->h1('Vendors');
            $bv = []; foreach ($live as $l) { $n = $l['vendor_name'] !== '' ? $l['vendor_name'] : ($l['pool'] === 'internal_labor' ? 'IT staff (internal)' : 'No vendor yet'); $bv[$n]['amount'] = ($bv[$n]['amount'] ?? 0) + $l['amount']; $bv[$n]['lines'] = ($bv[$n]['lines'] ?? 0) + 1; }
            uasort($bv, fn($a, $b) => $b['amount'] <=> $a['amount']);
            $rows = []; foreach ($bv as $n => $v) $rows[] = [$n, (string)$v['lines'], $M($v['amount']), $pct($v['amount'])];
            $d->table([['label' => 'Vendor', 'w' => 4], ['label' => 'Lines', 'w' => 0.8, 'align' => 'R'], ['label' => 'Planned', 'w' => 1.5, 'align' => 'R'], ['label' => 'Share', 'w' => 1, 'align' => 'R']], $rows, ['empty' => 'No lines yet.']);
        }
        // ── Benchmarks
        if (isset($on['benchmarks'])) {
            $d->h1('Benchmarks');
            $d->p('Reference figures to put this budget in context. A benchmark is a question to ask, not a target: organizations differ in how much they buy as services, how many offices they run and what they count as IT.');
            $ours = ['np_pct' => $k['pct_operating_comparable'] !== null ? opsh_doc_pct((float)$k['pct_operating_comparable'], 2) . ' (without IT staff and telecom)' : 'enter the operating budget in Settings',
                     'np_emp' => $k['per_employee_comparable'] !== null ? $M((float)$k['per_employee_comparable']) . ' (without IT staff and telecom)' : 'enter the number of employees in Settings',
                     'growth' => $change !== null ? ($change >= 0 ? '+' : '') . opsh_doc_pct($change) : '—', 'security' => $k['security_pct'] !== null ? opsh_doc_pct((float)$k['security_pct']) : '—',
                     'personnel' => $k['labor_pct'] !== null ? opsh_doc_pct((float)$k['labor_pct']) : '—', 'run' => $k['run_pct'] !== null ? opsh_doc_pct((float)$k['run_pct']) : '—',
                     'contingency' => opsh_doc_pct((float)$cfg['contingency_pct'], 0), 'm365' => ucfirst((string)$cfg['pricing']) . ' prices'];
            $rows = []; $src = [];
            foreach ($sum['benchmarks'] as $b) { $rows[] = [$b['label'], $b['value'], $ours[$b['key']] ?? '—']; if (!empty($b['source'])) $src[$b['source'][0]] = $b['source'][1]; }
            $d->table([['label' => 'Measure', 'w' => 2.6], ['label' => 'Benchmark', 'w' => 3.2], ['label' => 'This budget', 'w' => 2]], $rows);
            if ($src) { $d->h2('Sources'); $d->bullets(array_map(fn($n, $u) => $n . ' — ' . $u, array_keys($src), $src)); }
        }
        // ── Spend to date
        if (isset($on['spend']) && (float)$k['actual'] > 0) {
            $d->h1('Spend to date');
            $d->p('Spent so far: ' . $M((float)$k['actual']) . ' after ' . $k['elapsed_months'] . ' month' . ($k['elapsed_months'] === 1 ? '' : 's') . ' of the year, against ' . $M((float)$k['expected_to_date']) . ' expected at an even pace.');
            $rows = []; foreach ($sum['by_pool'] as $p) if ($p['amount'] > 0 || $p['actual'] > 0) $rows[] = [$p['label'], $M($p['amount']), $M($p['actual']), $p['amount'] > 0 ? opsh_doc_pct($p['actual'] / $p['amount'] * 100, 0) : '—'];
            $d->table([['label' => 'Category', 'w' => 3], ['label' => 'Planned', 'w' => 1.4, 'align' => 'R'], ['label' => 'Spent', 'w' => 1.4, 'align' => 'R'], ['label' => 'Used', 'w' => 1, 'align' => 'R']], $rows);
        }
        // ── Assumptions
        if (isset($on['assumptions'])) {
            $d->h1('Assumptions and notes');
            $rc = []; foreach ($cfg['refresh'] as $c => $y) if ($y > 0) $rc[] = mb_strtolower(opsh_budget_cat_label($c, true)) . ' ' . $y;
            $pts = ['Fiscal year ' . $fyd['label'] . ': ' . $fmtD($fyd['start']) . ' to ' . $fmtD($fyd['end']) . '. Amounts in ' . $cur . '.',
                    'Each amount is price × quantity × the times it is billed in the year, computed by the application from the budget lines.',
                    'Contingency: ' . opsh_doc_pct((float)$cfg['contingency_pct'], 0) . ' of planned costs, for price increases and failures nobody planned.',
                    'Microsoft 365: ' . $cfg['pricing'] . ' pricing; the price on the invoice replaces Microsoft\'s published price once it is saved.',
                    'Equipment refresh cycles (years): ' . implode(', ', $rc) . '.',
                    'Yearly increase used when a year is copied forward: ' . opsh_doc_pct((float)$cfg['increase_pct']) . '.'];
            if ($k['needs_price']) $pts[] = $k['needs_price'] . ' line(s) have no price yet and are not counted in the totals.';
            if ($k['incomplete']) $pts[] = $k['incomplete'] . ' line(s) still need a vendor or a contract.';
            if ($k['cut']) $pts[] = $k['cut'] . ' line(s) were cut and are not counted.';
            $d->bullets($pts);
        }
        // ── Approval
        if (isset($on['approval']) && $opt['approvers']) {
            $d->h1('Approval');
            $d->p('By signing, the people below accept the ' . $title . ' as presented in this document, for a total request of ' . $M((float)$k['total']) . '.');
            $d->signatures($opt['approvers']);
        }
        // ── Detail
        if (isset($on['detail'])) {
            $d->h1('Appendix: line detail');
            $byOffice = $opt['detail_group'] === 'office';
            $groups = [];
            foreach ($all as $l) { $g = $byOffice ? ($l['site_name'] !== '' ? $l['site_name'] : 'Whole organization') : ($pools[$l['pool']][0] ?? $l['pool']); $groups[$g][] = $l; }
            if ($byOffice) ksort($groups); else { $order = array_map(fn($p) => $p[0], $pools); uksort($groups, fn($a, $b) => (array_search($a, array_values($order)) <=> array_search($b, array_values($order)))); }
            $rows = [];
            foreach ($groups as $g => $ls) {
                $gt = array_sum(array_map(fn($l) => $l['status'] === 'cut' ? 0 : $l['amount'], $ls));
                $rows[] = ['cells' => [$g, $M($gt)], 'style' => 'group'];
                foreach ($ls as $l) {
                    $q = rtrim(rtrim(number_format($l['qty'], 2), '0'), '.');
                    $rows[] = ['cells' => [$l['name'], $byOffice ? ($pools[$l['pool']][0] ?? '') : ($l['site_name'] ?: '—'), $l['vendor_name'] ?: '—', ($bill[$l['billing']] ?? $l['billing']) . ($l['billing'] === 'monthly' && $l['months'] < 12 ? ' × ' . $l['months'] : ''),
                        $q, $l['needs_price'] ? '—' : $M($l['unit_cost'], true), $l['status'] === 'cut' ? 'cut' : ($l['needs_price'] ? 'needs a price' : $M($l['amount']))], 'style' => $l['status'] === 'cut' ? 'muted' : null];
                }
            }
            $rows[] = ['cells' => ['Planned costs', '', '', '', '', '', $M($planned)], 'style' => 'total'];
            $d->table([['label' => 'Line', 'w' => 3.4], ['label' => $byOffice ? 'Category' : 'Office', 'w' => 1.6], ['label' => 'Vendor', 'w' => 1.5], ['label' => 'Billed', 'w' => 1.1], ['label' => 'Qty', 'w' => 0.6, 'align' => 'R'], ['label' => 'Price', 'w' => 1.1, 'align' => 'R'], ['label' => 'Year', 'w' => 1.2, 'align' => 'R']], $rows, ['size' => 7.8, 'empty' => 'No lines in this year yet.']);
        }
        return $d;
    }
}
