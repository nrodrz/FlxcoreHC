<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — AI assist (server-side proxy to an LLM provider).
 * The API key is stored encrypted (vault) and never sent to the browser.
 * GET  /api/ai          -> { enabled, provider, model, key_set }
 * POST /api/ai/config   -> save { enabled, provider, model, api_key? }   (super_admin)
 * 0001-A004: the ticket assistant, SOP / job description builder, transcription, Ask IT and KB gaps are gone with their modules.
 */
$db = getDB();






function ai_get($db, $k, $def = '') { $s = $db->prepare("SELECT sval FROM itops_settings WHERE skey=?"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? $def : $v; }
function ai_put($db, $k, $v, $uid) { $db->prepare("INSERT INTO itops_settings (skey,sval,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval),updated_by=VALUES(updated_by)")->execute([$k, $v, $uid]); }



if ($method === 'GET' && !$id) {
    itops_require($currentUser, 'settings', 'view');   // 0001-A004: was Tickets · View (the AI settings card could not load)
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
    itops_require($currentUser, 'settings', 'edit');   // Settings → Sign-in & AI (General area)
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

error_response('Not found', 404);
