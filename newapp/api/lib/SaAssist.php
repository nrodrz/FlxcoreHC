<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — BT121: security baselines, build 4d. The AI planning assistant on Posture → Plan.
 *
 *  - Suggest decisions: for the undecided items of a draft plan, the AI proposes do it / make it a task / accept the
 *    risk / not for us / later, with one sentence why, from the tenant profile and the findings. A suggestion is only
 *    shown: a person opens the item and decides (the suggestion fills the editor). Answers about rules that are not
 *    undecided items of this plan are dropped and counted.
 *  - Explain an item in plain words; draft a staff notice or a knowledge-base article for it. The person edits the
 *    text; it can be saved as a KB article draft or an announcement draft (nothing is published).
 *  - What the AI is given: the baseline, the tenant profile, and per item the rule, its area, severity, stage, effort,
 *    effect on people, license, what it depends on, what was found as counts, and the decision. Never people's names,
 *    addresses or device names (the found text of a rule can list them, so it is not sent).
 *  - Every request goes in the Audit Log with what was asked and how many answers were kept (never the AI's text).
 * Table: itops_sa_ai_suggestions.
 */
if (!function_exists('itops_sx_ready')) {
    require_once __DIR__ . '/AiCall.php';

    function itops_sx_ready(PDO $db): bool { try { $db->query('SELECT 1 FROM itops_sa_ai_suggestions LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
    function itops_sx_decisions(): array { return ['do' => 'Do it', 'task' => 'Make it a task', 'accept' => 'Accept the risk', 'na' => 'Not for us', 'later' => 'Later']; }

    /** What the AI may know about the tenant and one item (no names). */
    function itops_sx_profile(PDO $db): array {
        $p = function_exists('itops_sa_setting_json') ? itops_sa_setting_json($db, 'sa_profile', []) : [];
        $W = ['office' => 'mostly in the office', 'hybrid' => 'hybrid', 'remote' => 'mostly remote']; $X = ['none' => 'none', 'customers' => 'customers ask for it', 'insurance' => 'cyber insurance asks for it', 'regulated' => 'regulated'];
        $out = [];
        if (isset($p['staff'])) $out[] = 'Staff: about ' . (int)$p['staff'];
        if (isset($p['work'])) $out[] = 'Work: ' . ($W[$p['work']] ?? $p['work']);
        if (isset($p['byod'])) $out[] = 'Personal phones used for work: ' . ($p['byod'] ? 'yes' : 'no');
        if (isset($p['executives'])) $out[] = 'Executives with extra exposure: ' . ($p['executives'] ? 'yes' : 'no');
        if (isset($p['pressure'])) $out[] = 'Outside requirement: ' . ($X[$p['pressure']] ?? $p['pressure']);
        if (!empty($p['countries'])) $out[] = 'Countries people sign in from: ' . $p['countries'];
        if (!empty($p['notes'])) $out[] = 'Notes from the administrator: ' . $p['notes'];
        return $out;
    }
    function itops_sx_item_line(array $i): string {
        return $i['rule_id'] . ' | ' . $i['title'] . ' | area ' . $i['area'] . ' | severity ' . $i['severity'] . ' | stage ' . $i['stage'] . ' ' . $i['stage_label']
             . ' | ' . ($i['found_status'] === 'fail' ? 'failed' : 'warning') . ($i['affected_count'] ? ', ' . $i['affected_count'] . ' affected' : '')
             . ' | effort ' . $i['effort'] . ' | effect on people ' . $i['impact'] . ' | license ' . $i['lic_label']
             . ($i['deps'] ? ' | do first: ' . implode(', ', array_column($i['deps'], 0)) : '') . ' | decision now: ' . $i['decision'];
    }
    function itops_sx_head(PDO $db, array $view): string {
        $B = function_exists('itops_sa_baselines') ? itops_sa_baselines() : [];
        $prof = itops_sx_profile($db);
        return 'Baseline: ' . ($B[$view['plan']['baseline']][0] ?? $view['plan']['baseline']) . "\nTenant profile:\n" . ($prof ? '- ' . implode("\n- ", $prof) : '- (not filled in)');
    }

    /** Ask for suggestions on the undecided items. Returns [kept, dropped, asked]. */
    function itops_sx_suggest(PDO $db, array $view, int $by): array {
        if ($view['plan']['status'] !== 'draft') throw new InvalidArgumentException('Suggestions are for a draft plan. Start a new version to change decisions.');
        $und = array_values(array_filter($view['items'], fn($i) => $i['decision'] === 'undecided' && !$i['fixed']));
        if (!$und) throw new InvalidArgumentException('Every item is decided (or fixed since): nothing to suggest.');
        $und = array_slice($und, 0, 60);
        $ids = array_column($und, 'rule_id'); $D = itops_sx_decisions();
        $sys = 'You help an IT administrator decide, item by item, a security plan for a small organization\'s Microsoft 365 tenant. '
             . 'For each item, choose one decision: "do" (do it now), "task" (do it, but it needs work: make it a task), "accept" (accept the risk for now: only when the effort or the effect on people is high and the risk for this organization is low), '
             . '"na" (does not apply to this organization, from its profile), "later" (worth doing after the earlier stages). Prefer "do" for high severity with low effort. Respect the stages and "do first". '
             . 'Use only the facts given; never invent facts about the organization. Reply with ONLY a JSON object: {"suggestions":[{"rule_id":"...","decision":"do|task|accept|na|later","why":"one sentence, at most 30 words"}]}. '
             . 'Use only the rule ids listed.';
        $user = itops_sx_head($db, $view) . "\n\nItems (rule id | title | facts):\n" . implode("\n", array_map('itops_sx_item_line', $und));
        $j = itops_ai_json(itops_ai_ask($db, $sys, $user, 3000));
        if (!$j || !is_array($j['suggestions'] ?? null)) throw new RuntimeException('The AI answer could not be read. Try again.');
        $kept = []; $dropped = 0;
        foreach ($j['suggestions'] as $s) {
            $rid = is_array($s) ? (string)($s['rule_id'] ?? '') : ''; $dec = is_array($s) ? (string)($s['decision'] ?? '') : '';
            if (!in_array($rid, $ids, true) || !isset($D[$dec]) || isset($kept[$rid])) { $dropped++; continue; }
            $why = trim(preg_replace('/\s+/', ' ', strip_tags((string)($s['why'] ?? ''))));
            $kept[$rid] = ['decision' => $dec, 'why' => mb_substr($why, 0, 300)];
        }
        $pid = (int)$view['plan']['id'];
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM itops_sa_ai_suggestions WHERE plan_id=?')->execute([$pid]);
            $ins = $db->prepare('INSERT INTO itops_sa_ai_suggestions (plan_id, rule_id, decision, why, created_by, created_at) VALUES (?,?,?,?,?,NOW())');
            foreach ($kept as $rid => $k) $ins->execute([$pid, $rid, $k['decision'], $k['why'], $by]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        return [count($kept), $dropped, count($und)];
    }
    function itops_sx_suggestions(PDO $db, int $planId): array {
        $out = [];
        foreach ($db->query('SELECT s.rule_id, s.decision, s.why, s.created_at, u.display_name FROM itops_sa_ai_suggestions s LEFT JOIN itops_users u ON u.id=s.created_by WHERE s.plan_id=' . (int)$planId)->fetchAll(PDO::FETCH_ASSOC) as $r)
            $out[$r['rule_id']] = ['decision' => $r['decision'], 'label' => itops_sx_decisions()[$r['decision']] ?? $r['decision'], 'why' => $r['why'], 'at' => $r['created_at'], 'by' => $r['display_name']];
        return $out;
    }

    /** Explain, staff notice or KB article for one item. Returns ['title' => …, 'body' => …] (plain text). */
    function itops_sx_write(PDO $db, array $view, array $item, string $kind): array {
        $what = ['explain' => 'Explain this item in plain words for the organization\'s manager, who is not technical: what the risk is, what the change does, what people will notice, and what it costs in effort. 120 to 200 words. Title: a short question or statement.',
                 'notice' => 'Write a notice to all staff announcing this change before it is made: what changes, why, when (write [date] where the date goes), what each person must do (step by step if they must set something up), and who to ask (write [IT contact]). Friendly, plain, at most 220 words. Title: a short subject line.',
                 'kb' => 'Write a knowledge-base article for staff about this change: what it is, why, and step-by-step instructions for what they must do, plus "If something goes wrong". Plain words, short numbered steps, at most 400 words. Title: a how-to title.'][$kind] ?? null;
        if (!$what) throw new InvalidArgumentException('Unknown request.');
        $sys = 'You write for a small organization\'s IT team about a Microsoft 365 security change. Use only the facts given; never invent names, dates, numbers or products the organization has. '
             . 'Do not mention rule ids. Reply with ONLY a JSON object: {"title":"...","body":"..."} where body is plain text with blank lines between paragraphs and "1." style numbered steps.';
        $fix = $item['fix'] ? "\nHow to fix it (for the IT team):\n" . implode("\n", array_map(fn($s, $n) => ($n + 1) . '. ' . $s, $item['fix'], array_keys($item['fix']))) : '';
        $user = $what . "\n\n" . itops_sx_head($db, $view) . "\n\nItem: " . $item['title'] . "\nWhy it matters: " . $item['why_it_matters'] . "\nFacts: " . itops_sx_item_line($item) . $fix;
        $j = itops_ai_json(itops_ai_ask($db, $sys, $user, 1600));
        $title = trim(strip_tags((string)($j['title'] ?? ''))); $body = trim(strip_tags((string)($j['body'] ?? '')));
        if ($body === '') throw new RuntimeException('The AI answer could not be read. Try again.');
        return ['title' => mb_substr($title !== '' ? $title : $item['title'], 0, 190), 'body' => mb_substr($body, 0, 6000)];
    }
}
