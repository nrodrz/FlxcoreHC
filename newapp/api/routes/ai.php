<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — AI assist (server-side proxy to an LLM provider).
 * The API key is stored encrypted (vault) and never sent to the browser.
 * GET  /api/ai          -> { enabled, provider, model, key_set }
 * POST /api/ai/config   -> save { enabled, provider, model, api_key? }   (super_admin)
 * POST /api/ai/assist   -> { ticket_id } -> { summary, category_id, category_name, priority, draft_reply }
 */
define('JD_BUILDER_SYS', 'You are OpsHub\'s Job Description Builder, an expert, inclusive HR writer for an organization that may operate across several offices and work to external quality standards. Work conversationally with the user to build ONE job description. Ask only one or two focused questions at a time when key details are missing (job title, department, who it reports to, employment type, location or remote, the role purpose, key responsibilities, required vs preferred qualifications, core competencies, and any pay range provided). Proactively suggest modern, bias-free, inclusive language and clear, measurable responsibilities. Be concise and friendly. You MUST reply with ONLY a single JSON object and nothing else (no markdown, no code fences). Use exactly these keys: reply (your conversational message to the user, a string); draft (an object with title set to the job title, html string, and review_days integer); suggestions (an array of up to 3 short clickable next-step ideas as strings); done (boolean, true only when the user says it is complete). The html must be clean semantic HTML using only h2, p, ul, ol, li, b, table, tr, th, td tags. Prefer these sections when relevant: Position Summary, Key Responsibilities, Required Qualifications, Preferred Qualifications, Core Competencies, Reporting and Structure, Work Environment, and a brief Equal Opportunity statement. Always return the COMPLETE updated draft every turn. Use placeholders like [department] or [pay range] when a detail is unknown rather than inventing facts. Avoid discriminatory or non-inclusive language and avoid age, gender, or other protected-class assumptions.');
define('SOP_BUILDER_SYS', 'You are OpsHub\'s SOP Builder, an expert at writing clear, modern Standard Operating Procedures for an organization that may operate across several offices and work to external quality standards. Work conversationally with the user to build ONE SOP. Ask only one or two focused questions at a time when key details are missing (what triggers it, who performs it, the steps, the systems used, what done looks like, and edge cases). Proactively suggest modern best practices: clear ownership and RACI, least-privilege and security, measurable and testable steps, automation opportunities, a rollback or exception path, and a sensible review cadence. Be concise and friendly. You MUST reply with ONLY a single JSON object and nothing else (no markdown, no code fences). Use exactly these keys: reply (your conversational message to the user, a string); draft (an object with title string, html string, and review_days integer representing the CURRENT full SOP so far); suggestions (an array of up to 3 short clickable next-step ideas as strings); done (boolean, true only when the user says the SOP is complete). The html must be clean semantic HTML using only h2, p, ul, ol, li, b, table, tr, th, td tags. Prefer these sections when relevant: Purpose, Scope, Roles and Responsibilities, Prerequisites, Procedure (numbered steps), Exceptions and Rollback, References. Always return the COMPLETE updated draft every turn, not just the changes. Use placeholders like [role] or [system] when a detail is unknown rather than inventing facts. Never reproduce copyrighted standards text, including the standards of any accreditation body. If there is not enough information to draft yet, return an empty html string and focus on questions.');
$db = getDB();

define('KB_ASSIST_SYS', "You are OpsHub's IT Help Assistant for an organization that runs on Microsoft 365. You help an EMPLOYEE find the right answer in the internal Knowledge Base. Behave like a thoughtful help-desk agent:\n- Work out what the person ACTUALLY needs. If the request is vague, or they may be asking for the wrong thing (wrong product/feature name, or an XY problem where they ask about their attempted fix instead of the real goal), ask ONE short clarifying question BEFORE answering.\n- Answer ONLY from the Knowledge Base context provided below. Do NOT invent steps, settings, policies, menu names, or article content. If the answer is not in the context, say you do not have an article on that yet and offer to raise a support ticket.\n- Cite the specific article(s) you used by their #id.\n- Gently correct mistaken assumptions using facts from the articles (e.g. if they ask to reset a password 'in Outlook' but the article says use the self-service portal, steer them there).\n- Be concise, friendly, plain language, one task at a time. Never reproduce copyrighted text.\nReply with ONLY a single JSON object (no markdown, no code fences) with keys: reply (string, your message to the user), article_ids (array of integer #ids you cite from the context; may be empty), suggestions (array of up to 3 short next-step questions the user might tap), need_clarification (boolean, true when your reply is a clarifying question rather than an answer).");

function kb_assist_retrieve($db, $query, $limit = 6) {
    $query = trim($query);
    if ($query === '') return [];
    $rows = [];
    try {
        $st = $db->prepare("SELECT id, title, content, MATCH(title,content) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
                            FROM opsh_kb_articles
                            WHERE status='published' AND MATCH(title,content) AGAINST (? IN NATURAL LANGUAGE MODE)
                            ORDER BY score DESC LIMIT ?");
        $st->bindValue(1, $query); $st->bindValue(2, $query); $st->bindValue(3, (int)$limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
    } catch (\Throwable $e) { $rows = []; }
    if (!$rows) {
        try {
            $terms = array_slice(array_filter(preg_split('/\s+/', $query)), 0, 6);
            if ($terms) {
                $where = []; $bind = [];
                foreach ($terms as $tm) { $where[] = "(title LIKE ? OR content LIKE ?)"; $bind[] = '%'.$tm.'%'; $bind[] = '%'.$tm.'%'; }
                $sql = "SELECT id, title, content FROM opsh_kb_articles WHERE status='published' AND (".implode(' OR ', $where).") LIMIT ".(int)$limit;
                $st = $db->prepare($sql); $st->execute($bind); $rows = $st->fetchAll();
            }
        } catch (\Throwable $e) { $rows = []; }
    }
    $out = [];
    foreach ($rows as $r) {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$r['content'])));
        $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'excerpt' => mb_substr($text, 0, 700)];
    }
    return $out;
}

function kb_assist_parse($raw) {
    $s = trim((string)$raw);
    $s = preg_replace('/^```(json)?/i', '', $s);
    $s = preg_replace('/```$/', '', trim($s));
    $j = json_decode(trim($s), true);
    if (is_array($j)) return $j;
    if (preg_match('/\{.*\}/s', $s, $mm)) { $j = json_decode($mm[0], true); if (is_array($j)) return $j; }
    return ['reply' => (string)$raw];
}

function kb_trusted_hosts($sources) {
    $hosts = [];
    foreach ((array)$sources as $s) { $h = parse_url($s['url'] ?? '', PHP_URL_HOST); if ($h) $hosts[] = strtolower($h); }
    return array_values(array_unique($hosts));
}
function kb_host_allowed($host, $allow) {
    $host = strtolower((string)$host);
    foreach ($allow as $a) {
        $a = strtolower($a);
        if ($host === $a) return true;
        if (strlen($host) > strlen($a) + 1 && substr($host, -(strlen($a) + 1)) === '.' . $a) return true;
    }
    return false;
}
function kb_ip_public($host) {
    $ip = @gethostbyname($host);
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return false;
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
function kb_fetch_trusted($url, $allowHosts) {
    // Only https, only admin-allowlisted public domains, no redirects — this is the anti-SSRF boundary.
    if (!preg_match('#^https://#i', (string)$url) || strlen((string)$url) > 500) return null;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || !$allowHosts || !kb_host_allowed($host, $allowHosts)) return null;
    if (!function_exists('curl_init')) return null;
    require_once __DIR__ . '/../lib/NetGuard.php';   // BT88: every address of the name public (IPv6 too), and the call pinned to it
    $chk = opsh_net_check((string)$url); if (!$chk['ok']) return null;
    $ch = curl_init($url);
    opsh_net_pin($ch, $chk);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'OpsHub-KB-Assistant/1.0', CURLOPT_HTTPHEADER => ['Accept: text/html,text/plain'],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code !== 200 || $resp === false) return null;
    if (stripos($ct, 'html') === false && stripos($ct, 'text') === false) return null;
    $html = substr((string)$resp, 0, 800000);
    $html = preg_replace('#<(script|style|noscript|svg|nav|footer|header|form)\b[^>]*>.*?</\1>#is', ' ', $html);
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if ($text === '') return null;
    return mb_substr($text, 0, 4500);
}

function kb_gap_log($db, $query, $answeredFrom) {
    $q = trim(preg_replace('/\s+/', ' ', (string)$query));
    if ($q === '' || mb_strlen($q) < 3) return;
    $norm = mb_strtolower(mb_substr($q, 0, 180));
    try {
        $db->prepare("INSERT INTO opsh_kb_gaps (query_norm, query_text, hits, answered_from, status, last_at, created_at)
                      VALUES (?,?,1,?, 'open', NOW(), NOW())
                      ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = NOW(), answered_from = VALUES(answered_from),
                        status = IF(status='resolved','open',status)")
           ->execute([$norm, mb_substr($q, 0, 500), mb_substr($answeredFrom, 0, 16)]);
    } catch (\Throwable $e) {}
}

/* A user without tickets:"all" may only use AI on tickets they opened or are assigned (same scope as the Tickets API). */
function ai_assert_ticket_scope($db, $currentUser, $tid) {
    if (opsh_can($currentUser, 'tickets', 'all')) return;
    $st = $db->prepare('SELECT 1 FROM opsh_tickets WHERE id=? AND (assignee_id=? OR requester_id=? OR backup_id=?)');
    $st->execute([(int)$tid, (int)$currentUser['id'], (int)$currentUser['id'], (int)$currentUser['id']]);
    if (!$st->fetchColumn()) error_response('You can only use this on tickets you created or that are assigned to you', 403);
}
function ai_get($db, $k, $def = '') { $s = $db->prepare("SELECT sval FROM opsh_settings WHERE skey=?"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? $def : $v; }
function ai_put($db, $k, $v, $uid) { $db->prepare("INSERT INTO opsh_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)")->execute([$k, $v, $uid]); }

function ai_call($provider, $key, $model, $sys, $user) {
    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/chat/completions';
        $payload = ['model' => $model, 'max_tokens' => 700, 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]]];
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
    } else {
        $url = 'https://api.anthropic.com/v1/messages';
        $payload = ['model' => $model, 'max_tokens' => 700, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => $user]]];
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    }
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) return null;
    $d = json_decode($resp, true);
    if (!is_array($d)) return null;
    if ($provider === 'openai') return $d['choices'][0]['message']['content'] ?? null;
    if (isset($d['content']) && is_array($d['content'])) { $out = ''; foreach ($d['content'] as $blk) { if (($blk['type'] ?? '') === 'text') $out .= $blk['text']; } return $out !== '' ? $out : null; }
    return null;
}

function ai_call_chat($provider, $key, $model, $sys, $messages, $maxTokens = 2000) {
    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/chat/completions';
        $msgs = array_merge([['role' => 'system', 'content' => $sys]], $messages);
        $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $msgs];
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
    } else {
        $url = 'https://api.anthropic.com/v1/messages';
        $payload = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $sys, 'messages' => $messages];
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    }
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) return null;
    $d = json_decode($resp, true);
    if (!is_array($d)) return null;
    if ($provider === 'openai') return $d['choices'][0]['message']['content'] ?? null;
    if (isset($d['content']) && is_array($d['content'])) { $out = ''; foreach ($d['content'] as $blk) { if (($blk['type'] ?? '') === 'text') $out .= $blk['text']; } return $out !== '' ? $out : null; }   // review WORK-20: no text is no answer
    return null;
}

if ($method === 'GET' && !$id) {
    opsh_require($currentUser, 'tickets', 'view');
    $prov = ai_get($db, 'ai_provider', 'anthropic');
    $mainKey = ai_get($db, 'ai_api_key_enc', '');
    $sttKey = ai_get($db, 'ai_stt_key_enc', '');
    json_response([
        'enabled'     => ai_get($db, 'ai_enabled', '0') === '1',
        'provider'    => $prov,
        'model'       => ai_get($db, 'ai_model', ''),
        'key_set'     => $mainKey !== '',
        'stt'         => ($sttKey !== '') || ($prov === 'openai' && $mainKey !== ''),
        'stt_key_set' => $sttKey !== '',
        'assist_external' => ai_get($db, 'kb_assist_external', '0') === '1',
        'trusted_sources' => json_decode(ai_get($db, 'kb_trusted_sources', '[]'), true) ?: [],
    ]);
    return;
}

if ($method === 'POST' && $id === 'config') {
    opsh_require($currentUser, 'settings', 'edit');   // Settings → Sign-in & AI (General area)
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    // New API keys are secrets: Super Admin only (same rule as other connection secrets).
    if (((isset($b['api_key']) && trim((string)$b['api_key']) !== '') || (isset($b['stt_api_key']) && trim((string)$b['stt_api_key']) !== '')) && ($currentUser['role_slug'] ?? '') !== 'super_admin')
        error_response('Only a Super Admin can change the AI keys', 403);
    // BT88 (code audit M-34): the stored key only goes to the provider it was given for — switching the provider needs the
    // new provider's key, and the provider and the trusted sources are Super Admin only (logged when refused).
    $isSAi = ($currentUser['role_slug'] ?? '') === 'super_admin';
    $curProv = ai_get($db, 'ai_provider', 'anthropic');
    $prov = in_array(($b['provider'] ?? $curProv), ['anthropic', 'openai'], true) ? ($b['provider'] ?? $curProv) : $curProv;
    $aiRefuse = function (string $why) { audit_log('ai_config_refused', 'setting', 0, 'AI', null, ['reason' => $why]); error_response($why, 403); };
    if ($prov !== $curProv) {
        if (!$isSAi) $aiRefuse('Only a Super Admin can switch the AI provider.');
        if (ai_get($db, 'ai_api_key_enc', '') !== '' && trim((string)($b['api_key'] ?? '')) === '') error_response('Switching to ' . ($prov === 'openai' ? 'OpenAI' : 'Anthropic') . ' needs that provider\'s API key: the saved key belongs to the other provider and is never sent to this one.', 400);
    }
    if (!$isSAi && isset($b['trusted_sources']) && is_array($b['trusted_sources']) && json_encode(array_map(fn($s) => [trim((string)($s['label'] ?? '')), trim((string)($s['url'] ?? ''))], array_values($b['trusted_sources']))) !== json_encode(array_map(fn($s) => [trim((string)($s['label'] ?? '')), trim((string)($s['url'] ?? ''))], json_decode(ai_get($db, 'kb_trusted_sources', '[]'), true) ?: []))) $aiRefuse('Only a Super Admin can change the trusted sources the assistant reads.');
    if (!$isSAi && array_key_exists('assist_external', $b) && (!empty($b['assist_external']) ? '1' : '0') !== ai_get($db, 'kb_assist_external', '0')) $aiRefuse('Only a Super Admin can let the assistant read outside sources.');
    // Review WORK-13: switching on sending data to the AI provider is a Super Admin decision; the change is audited with values
    $aiBefore = ['enabled' => ai_get($db, 'ai_enabled', '0'), 'provider' => $curProv, 'model' => ai_get($db, 'ai_model', ''), 'assist_external' => ai_get($db, 'kb_assist_external', '0'), 'trusted_sources' => ai_get($db, 'kb_trusted_sources', '[]')];
    if (!$isSAi && !empty($b['enabled']) && $aiBefore['enabled'] !== '1') $aiRefuse('Only a Super Admin can switch on the AI features (they send data to the AI provider).');
    ai_put($db, 'ai_enabled', !empty($b['enabled']) ? '1' : '0', $currentUser['id']);
    ai_put($db, 'ai_provider', $prov, $currentUser['id']);
    ai_put($db, 'ai_model', trim((string)($b['model'] ?? '')), $currentUser['id']);
    // Only change the Ask IT option when the caller sent it (the AI card doesn't, KB Content Gaps does).
    if (array_key_exists('assist_external', $b)) ai_put($db, 'kb_assist_external', !empty($b['assist_external']) ? '1' : '0', $currentUser['id']);
    if (isset($b['trusted_sources']) && is_array($b['trusted_sources'])) {
        $clean = [];
        foreach ($b['trusted_sources'] as $src) {
            $label = trim((string)($src['label'] ?? '')); $url = trim((string)($src['url'] ?? ''));
            if ($label === '' || !preg_match('#^https://#i', $url)) continue;   // BT88: https only (it is the only kind fetched)
            $clean[] = ['label' => mb_substr($label, 0, 80), 'url' => mb_substr($url, 0, 300)];
        }
        ai_put($db, 'kb_trusted_sources', json_encode(array_slice($clean, 0, 12)), $currentUser['id']);
    }
    if (!empty($b['api_key']) && trim((string)$b['api_key']) !== '') {
        ai_put($db, 'ai_api_key_enc', vault_encrypt(trim((string)$b['api_key'])), $currentUser['id']);
    }
    if (!empty($b['stt_api_key']) && trim((string)$b['stt_api_key']) !== '') {
        ai_put($db, 'ai_stt_key_enc', vault_encrypt(trim((string)$b['stt_api_key'])), $currentUser['id']);
    }
    $aiAfter = ['enabled' => ai_get($db, 'ai_enabled', '0'), 'provider' => $prov, 'model' => ai_get($db, 'ai_model', ''), 'assist_external' => ai_get($db, 'kb_assist_external', '0'), 'trusted_sources' => ai_get($db, 'kb_trusted_sources', '[]')];
    $aB = []; $aA = []; foreach ($aiAfter as $k => $v) if ((string)$aiBefore[$k] !== (string)$v) { $aB[$k] = mb_substr((string)$aiBefore[$k], 0, 500); $aA[$k] = mb_substr((string)$v, 0, 500); }
    if (!empty($b['api_key'])) { $aB['API key'] = '(previous)'; $aA['API key'] = '(replaced)'; }
    if (!empty($b['stt_api_key'])) { $aB['speech key'] = '(previous)'; $aA['speech key'] = '(replaced)'; }
    if (function_exists('audit_log')) audit_log('ai_config', 'setting', 0, $prov, $aB ?: null, $aA ?: null);
    json_response(['ok' => true]);
    return;
}

if ($method === 'POST' && $id === 'assist') {
    opsh_require($currentUser, 'tickets', 'view');
    { $__b = json_decode(file_get_contents('php://input'), true) ?: []; if (!empty($__b['ticket_id'])) ai_assert_ticket_scope($db, $currentUser, (int)$__b['ticket_id']); }   // scope first
    if (ai_get($db, 'ai_enabled', '0') !== '1') error_response('AI assist is not enabled', 400);
    $keyEnc = ai_get($db, 'ai_api_key_enc', '');
    if ($keyEnc === '') error_response('No AI API key is configured', 400);
    $apiKey = vault_decrypt($keyEnc);
    if (!$apiKey) error_response('The stored AI key could not be read', 500);

    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $tid = (int)($b['ticket_id'] ?? 0);
    if (!$tid) error_response('ticket_id required');
    ai_assert_ticket_scope($db, $currentUser, $tid);
    $t = $db->prepare("SELECT t.*, tc.name AS cat_name FROM opsh_tickets t LEFT JOIN opsh_ticket_categories tc ON t.category_id=tc.id WHERE t.id=?");
    $t->execute([$tid]);
    $tk = $t->fetch();
    if (!$tk) error_response('Ticket not found', 404);

    $cats = $db->query("SELECT id,name FROM opsh_ticket_categories WHERE (status IS NULL OR status='active') AND parent_id IS NOT NULL ORDER BY name")->fetchAll();
    if (!$cats) $cats = $db->query("SELECT id,name FROM opsh_ticket_categories WHERE (status IS NULL OR status='active') ORDER BY name")->fetchAll();
    $catNames = array_map(fn($c) => $c['name'], $cats);
    $priorities = ['p1_critical', 'p2_high', 'p3_medium', 'p4_low'];

    $provider = ai_get($db, 'ai_provider', 'anthropic');
    $model = ai_get($db, 'ai_model', '') ?: ($provider === 'openai' ? 'gpt-4o-mini' : 'claude-haiku-4-5-20251001');

    $sys = "You are an IT and operations service-desk assistant. Analyse the support ticket and reply with ONLY a JSON object (no markdown fences, no extra prose) using exactly these keys: "
         . "\"summary\" (a 1-2 sentence plain-language summary), "
         . "\"category\" (choose the single best match from this exact list, or an empty string if none fit: " . implode(', ', $catNames) . "), "
         . "\"priority\" (exactly one of: p1_critical, p2_high, p3_medium, p4_low), "
         . "\"draft_reply\" (a short, professional first reply to the requester; acknowledge the issue and outline next steps; do not invent facts or promise specific timelines).";
    $userMsg = "Subject: " . $tk['subject'] . "\n\nDescription:\n" . $tk['description'] . "\n\nCurrent category: " . ($tk['cat_name'] ?? 'none') . ". Current priority: " . $tk['priority'] . ".";

    $raw = ai_call($provider, $apiKey, $model, $sys, $userMsg);
    if ($raw === null) error_response('The AI service did not respond. Check the API key, the model name, and that the server can reach the provider.', 502);

    $txt = trim($raw);
    $txt = preg_replace('/^```[a-zA-Z]*\s*/', '', $txt);
    $txt = preg_replace('/```$/', '', trim($txt));
    $j = json_decode($txt, true);
    if (!is_array($j) && preg_match('/\{.*\}/s', $txt, $m)) $j = json_decode($m[0], true);
    if (!is_array($j)) { json_response(['summary' => $txt, 'category_id' => null, 'category_name' => null, 'priority' => null, 'draft_reply' => '']); return; }

    $catId = null; $catName = null;
    if (!empty($j['category'])) { foreach ($cats as $c) { if (strcasecmp($c['name'], $j['category']) === 0) { $catId = (int)$c['id']; $catName = $c['name']; break; } } }
    $pri = in_array(($j['priority'] ?? ''), $priorities, true) ? $j['priority'] : null;
    if (function_exists('audit_log')) audit_log('ai_assist', 'ticket', $tid, $provider);
    json_response([
        'summary'       => (string)($j['summary'] ?? ''),
        'category_id'   => $catId,
        'category_name' => $catName,
        'priority'      => $pri,
        'draft_reply'   => (string)($j['draft_reply'] ?? ''),
    ]);
    return;
}

if ($method === 'POST' && $id === 'sop') {
    $__b0 = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    opsh_require($currentUser, (($__b0['kind'] ?? 'sop') === 'jd') ? 'job_descriptions' : 'sops', 'create');   // review WORK-9: a job description needs Job descriptions · Create
    if (ai_get($db, 'ai_enabled', '0') !== '1') error_response('The AI builder is not enabled. An administrator can set it up under Settings then AI.', 400);
    $keyEnc = ai_get($db, 'ai_api_key_enc', '');
    if ($keyEnc === '') error_response('No AI API key is configured', 400);
    $apiKey = vault_decrypt($keyEnc);
    if (!$apiKey) error_response('The stored AI key could not be read', 500);

    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $raw_msgs = is_array($b['messages'] ?? null) ? $b['messages'] : [];
    $clean = [];
    foreach ($raw_msgs as $m) {
        $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
        $content = trim((string)($m['content'] ?? ''));
        if ($content === '') continue;
        if (function_exists('mb_substr')) $content = mb_substr($content, 0, 8000);
        $clean[] = ['role' => $role, 'content' => $content];
    }
    $kind = (($b['kind'] ?? 'sop') === 'jd') ? 'jd' : 'sop';
    if (!$clean) $clean[] = ['role' => 'user', 'content' => ($kind === 'jd' ? 'Help me create a new job description. Greet me and ask for the role title.' : 'Help me create a new SOP. Greet me and ask what process I want to document.')];
    if ($clean[0]['role'] !== 'user') array_unshift($clean, ['role' => 'user', 'content' => 'Help me build this document.']);

    $provider = ai_get($db, 'ai_provider', 'anthropic');
    $model = ai_get($db, 'ai_model', '') ?: ($provider === 'openai' ? 'gpt-4o-mini' : 'claude-haiku-4-5-20251001');

    $OPSH_FORMATS = [
        'stepwise'     => ' STRUCTURE: STEP-BY-STEP - present the Procedure as a simple numbered list with one clear action per step; keep it concise and linear.',
        'hierarchical' => ' STRUCTURE: HIERARCHICAL - organize the Procedure into numbered phases, each with numbered sub-steps (1, 1.1, 1.2); use this for complex multi-stage processes.',
        'checklist'    => ' STRUCTURE: CHECKLIST - present the Procedure as a verification checklist of short imperative items that can each be checked off, grouped into logical sections.',
        'flowchart'    => ' STRUCTURE: FLOWCHART-READY - write the Procedure as discrete sequential steps and phrase decision points as questions ending with a question mark, so it maps cleanly to a flow diagram.',
        'policy'       => ' STRUCTURE: POLICY / NARRATIVE - use mostly prose under clear section headings and minimise numbered steps.',
    ];
    $sys = opsh_brand(($kind === 'jd') ? JD_BUILDER_SYS : SOP_BUILDER_SYS);   // BT20: the assistant introduces itself with the client's app name
    $fmt = trim((string)($b['format'] ?? ''));
    if ($fmt !== '' && isset($OPSH_FORMATS[$fmt])) $sys .= $OPSH_FORMATS[$fmt];
    elseif (!empty($b['format_note'])) $sys .= ' STRUCTURE (custom): ' . trim((string)$b['format_note']);

    $raw = ai_call_chat($provider, $apiKey, $model, $sys, $clean, 2600);
    if ($raw === null) error_response('The AI service did not respond. Check the API key, the model name, and that the server can reach the provider.', 502);

    $txt = trim($raw);
    $txt = preg_replace('/^```[a-zA-Z]*\s*/', '', $txt);
    $txt = preg_replace('/```$/', '', trim($txt));
    $j = json_decode($txt, true);
    if (!is_array($j) && preg_match('/\{.*\}/s', $txt, $mm)) $j = json_decode($mm[0], true);
    if (!is_array($j)) { json_response(['reply' => $txt, 'draft' => null, 'suggestions' => [], 'done' => false]); return; }

    $draft = null;
    if (isset($j['draft']) && is_array($j['draft'])) {
        $draft = [
            'title'       => (string)($j['draft']['title'] ?? ''),
            'html'        => (string)($j['draft']['html'] ?? ''),
            'review_days' => (int)($j['draft']['review_days'] ?? 180),
        ];
    }
    $sugg = (isset($j['suggestions']) && is_array($j['suggestions'])) ? array_slice(array_values(array_map('strval', $j['suggestions'])), 0, 3) : [];
    if (function_exists('audit_log')) audit_log('ai_sop', 'sop', 0, $provider);
    json_response(['reply' => (string)($j['reply'] ?? ''), 'draft' => $draft, 'suggestions' => $sugg, 'done' => !empty($j['done'])]);
    return;
}

if ($method === 'POST' && $id === 'transcribe') {
    opsh_require($currentUser, 'tickets', 'view');
    { $__b = json_decode(file_get_contents('php://input'), true) ?: []; if (!empty($__b['ticket_id'])) ai_assert_ticket_scope($db, $currentUser, (int)$__b['ticket_id']); }   // scope first
    $prov = ai_get($db, 'ai_provider', 'anthropic');
    $sttEnc = ai_get($db, 'ai_stt_key_enc', '');
    $mainEnc = ai_get($db, 'ai_api_key_enc', '');
    $key = $sttEnc !== '' ? vault_decrypt($sttEnc) : (($prov === 'openai' && $mainEnc !== '') ? vault_decrypt($mainEnc) : '');
    if (!$key) error_response('Transcription needs an OpenAI (Whisper) key. Add one under Connection -> AI assist.', 400);
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $tid = (int)($b['ticket_id'] ?? 0); $aid = (int)($b['attachment_id'] ?? 0);
    if (!$tid || !$aid) error_response('ticket_id and attachment_id required');
    ai_assert_ticket_scope($db, $currentUser, $tid);
    $a = $db->prepare("SELECT file_path,file_name,mime_type FROM opsh_ticket_attachments WHERE id=? AND ticket_id=?");
    $a->execute([$aid, $tid]); $att = $a->fetch();
    if (!$att) error_response('Attachment not found', 404);
    $mime = (string)$att['mime_type'];
    if (strpos($mime, 'audio/') !== 0 && strpos($mime, 'video/') !== 0) error_response('Only audio or video files can be transcribed', 400);
    $full = realpath(UPLOAD_DIR . $att['file_path']);
    $base = realpath(UPLOAD_DIR);
    if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) error_response('File unavailable', 404);
    if (filesize($full) > 25 * 1024 * 1024) error_response('File is larger than the 25 MB transcription limit.', 400);
    if (!function_exists('curl_init')) error_response('Server cannot make outbound calls', 500);
    $cf = new CURLFile($full, $mime ?: 'application/octet-stream', $att['file_name']);
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key], CURLOPT_POSTFIELDS => ['model' => 'whisper-1', 'file' => $cf], CURLOPT_TIMEOUT => 120]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $code >= 400) error_response('Transcription failed. Check the OpenAI key and that the server can reach api.openai.com.', 502);
    $d = json_decode($resp, true);
    $text = is_array($d) ? ($d['text'] ?? '') : '';
    if (function_exists('audit_log')) audit_log('ai_transcribe', 'ticket', $tid, $att['file_name']);
    json_response(['text' => (string)$text]);
    return;
}

if ($method === 'POST' && $id === 'kb_assist') {
    opsh_require($currentUser, 'kb_assist', 'view');   // kb_assist inherits Knowledge Base view when not set explicitly
    if (ai_get($db, 'ai_enabled', '0') !== '1') error_response('The AI assistant is not enabled. An administrator can turn it on under AI settings.', 400);
    $provider = ai_get($db, 'ai_provider', 'anthropic');
    $model    = ai_get($db, 'ai_model', '') ?: ($provider === 'openai' ? 'gpt-4o-mini' : 'claude-haiku-4-5-20251001');   // same default as the other AI features (review WORK-2)
    $keyEnc   = ai_get($db, 'ai_api_key_enc', '');
    if ($keyEnc === '') error_response('No AI API key is configured', 400);
    $apiKey = vault_decrypt($keyEnc);
    if (!$apiKey) error_response('The stored AI key could not be read', 500);

    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $clean = [];
    foreach (($b['messages'] ?? []) as $m) {
        $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string)($m['content'] ?? ''));
        if ($content === '') continue;
        $clean[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
    }
    if (!$clean) error_response('Empty conversation', 400);
    if (count($clean) > 20) $clean = array_slice($clean, -20);

    $lastUser = '';
    for ($i = count($clean) - 1; $i >= 0; $i--) { if ($clean[$i]['role'] === 'user') { $lastUser = $clean[$i]['content']; break; } }

    $articles = kb_assist_retrieve($db, $lastUser);
    $ctx = '';
    foreach ($articles as $a) { $ctx .= "\n[Article #".$a['id']."] ".$a['title']."\n".$a['excerpt']."\n"; }
    if ($ctx === '') $ctx = "(no matching articles found)";

    $external = ai_get($db, 'kb_assist_external', '0') === '1';
    $sources  = json_decode(ai_get($db, 'kb_trusted_sources', '[]'), true) ?: [];
    $srcText = '';
    foreach ($sources as $s) { $srcText .= "\n- " . ($s['label'] ?? '') . ": " . ($s['url'] ?? ''); }

    $policy = "\n\n=== ANSWERING POLICY ===\nFirst try to answer from the Knowledge Base context above and cite the article #ids (set answered_from='kb').";
    if ($external && $srcText !== '') {
        $policy .= " If the internal KB does not cover the question, you have two options. OPTION 1 (preferred for specific how-to steps): return a field \"fetch_url\" containing ONE full https:// URL on one of the trusted domains listed below that most likely holds the answer, and do NOT write a final answer yet — the system will fetch that page and ask you again with its live content. OPTION 2: give general, widely-established guidance for standard Microsoft 365 / IT tasks, clearly labelled as general (not from our internal KB), point the user to a trusted source below to confirm exact current steps, and set answered_from='general'. NEVER guess about anything ORGANIZATION-SPECIFIC (internal policies, who to contact, custom processes) — set answered_from='none' and offer to raise a ticket. Trusted domains/sources:" . $srcText;
    } else {
        $policy .= " If the internal KB does not cover it, do NOT answer from outside knowledge — set answered_from='none' and offer to raise a support ticket.";
    }
    $policy .= "\nAlways include the key answered_from with value 'kb', 'general', or 'none'.";

    $sys = opsh_brand(KB_ASSIST_SYS) . $policy . "\n\n=== KNOWLEDGE BASE CONTEXT ===\n" . $ctx;

    $raw = ai_call_chat($provider, $apiKey, $model, $sys, $clean, 900);
    if ($raw === null) error_response('The AI service did not respond', 502);
    $j = kb_assist_parse($raw);

    // Live trusted-source retrieval: if the model asked to fetch a trusted page, fetch it (guarded) and re-ask grounded in it.
    $fetchedSource = null;
    $fetchUrl = $external ? trim((string)($j['fetch_url'] ?? '')) : '';
    if ($fetchUrl !== '' && empty($j['article_ids'])) {
        $page = kb_fetch_trusted($fetchUrl, kb_trusted_hosts($sources));
        if ($page) {
            $sys2 = opsh_brand(KB_ASSIST_SYS) . "\n\n=== LIVE CONTENT FROM TRUSTED SOURCE (" . $fetchUrl . ") ===\n" . $page
                  . "\n\nUsing this content, answer the user's question and cite this source URL in your reply. Set answered_from='trusted'. If this page does not actually answer it, set answered_from='none' and offer to raise a ticket. Reply with the same JSON shape (reply, article_ids, suggestions, need_clarification, answered_from).";
            $raw2 = ai_call_chat($provider, $apiKey, $model, $sys2, $clean, 900);
            if ($raw2 !== null) {
                $j = kb_assist_parse($raw2);
                if (empty($j['answered_from'])) $j['answered_from'] = 'trusted';
                $fetchedSource = ['label' => (parse_url($fetchUrl, PHP_URL_HOST) ?: 'source'), 'url' => $fetchUrl];
            }
        }
    }

    $byId = []; foreach ($articles as $a) $byId[$a['id']] = $a['title'];
    $cited = [];
    foreach (($j['article_ids'] ?? []) as $aid) { $aid = (int)$aid; if (isset($byId[$aid])) $cited[] = ['id' => $aid, 'title' => $byId[$aid]]; }

    $answeredFrom = $j['answered_from'] ?? ($cited ? 'kb' : 'none');
    $needClar = !empty($j['need_clarification']);
    // A content gap = a real question the internal KB did not answer (not a clarifying turn).
    if (!$needClar && $answeredFrom !== 'kb') kb_gap_log($db, $lastUser, $answeredFrom);

    // Surface trusted-source links when the answer came from general knowledge.
    $showSources = $fetchedSource ? [$fetchedSource] : (($answeredFrom === 'general' && $external) ? $sources : []);

    if (function_exists('audit_log')) audit_log('ai_kb_assist', 'kb', null, mb_substr($lastUser, 0, 120));
    json_response([
        'reply'              => (string)($j['reply'] ?? $raw),
        'articles'           => $cited,
        'suggestions'        => array_slice($j['suggestions'] ?? [], 0, 4),
        'need_clarification' => $needClar,
        'answered_from'      => $answeredFrom,
        'sources'            => $showSources,
    ]);
    return;
}

if ($method === 'GET' && $id === 'kb_gaps') {
    opsh_require($currentUser, 'kb_gaps', 'view');
    try {
        $rows = $db->query("SELECT id, query_text, hits, answered_from, status, last_at
                            FROM opsh_kb_gaps WHERE status='open' ORDER BY hits DESC, last_at DESC LIMIT 200")->fetchAll();
    } catch (\Throwable $e) {
        if (in_array($e->getCode(), ['42S02', 1146], true)) json_response(['data' => [], 'needs_migration' => true]);
        throw $e;
    }
    json_response(['data' => $rows]);
    return;
}
if ($method === 'POST' && $id === 'kb_gaps') {
    opsh_require($currentUser, 'kb_gaps', 'edit');
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $gid = (int)($b['id'] ?? 0);
    if (!$gid) error_response('id required');
    $gq = $db->prepare("SELECT * FROM opsh_kb_gaps WHERE id=?"); $gq->execute([$gid]); $gap = $gq->fetch(PDO::FETCH_ASSOC);
    if (!$gap) error_response('Not found', 404);
    $gName = mb_substr((string)($gap['query_text'] ?? ($gap['query_norm'] ?? ('#' . $gid))), 0, 200);
    if (($b['action'] ?? 'resolve') === 'delete') { $db->prepare("DELETE FROM opsh_kb_gaps WHERE id=?")->execute([$gid]); audit_log('kb_gap_deleted', 'kb', $gid, $gName); }   // review WORK-13
    else { $db->prepare("UPDATE opsh_kb_gaps SET status='resolved' WHERE id=?")->execute([$gid]); audit_log('kb_gap_resolved', 'kb', $gid, $gName, ['status' => (string)($gap['status'] ?? '')], ['status' => 'resolved']); }
    json_response(['ok' => true]);
    return;
}

error_response('Not found', 404);
