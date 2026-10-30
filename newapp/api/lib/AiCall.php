<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — BT121: one way to ask the AI integration (Settings → Sign-in & AI) from a library: the setting, the key
 * (kept wrapped, read here only), the provider call and a JSON reader. The key is never returned or logged.
 * A test installation may define opsh_ai_http_stub(bool $openai, array $payload): string (the provider's raw answer).
 */
if (!function_exists('opsh_ai_ask')) {
    function opsh_ai_set(PDO $db, string $k): string { try { $s = $db->prepare('SELECT sval FROM opsh_settings WHERE skey=?'); $s->execute([$k]); return (string)$s->fetchColumn(); } catch (Throwable $e) { return ''; } }
    /** null when the AI integration can be used, otherwise why not (in plain words). */
    function opsh_ai_off(PDO $db): ?string {
        if (opsh_ai_set($db, 'ai_enabled') !== '1') return 'The AI integration is off (Settings → Sign-in & AI).';
        if (opsh_ai_set($db, 'ai_api_key_enc') === '') return 'No AI key is set (Settings → Sign-in & AI).';
        return null;
    }
    /** The model's text answer. Throws InvalidArgumentException (not set up) or RuntimeException (the provider failed). */
    function opsh_ai_ask(PDO $db, string $sys, string $user, int $max = 1500): string {
        if ($why = opsh_ai_off($db)) throw new InvalidArgumentException($why);
        $key = function_exists('vault_decrypt') ? vault_decrypt(opsh_ai_set($db, 'ai_api_key_enc')) : '';
        if (!$key) throw new RuntimeException('The stored AI key could not be read.');
        $prov = opsh_ai_set($db, 'ai_provider') ?: 'anthropic'; $isO = $prov === 'openai';
        $model = opsh_ai_set($db, 'ai_model') ?: ($isO ? 'gpt-4o-mini' : 'claude-haiku-4-5-20251001');
        $payload = $isO ? ['model' => $model, 'max_tokens' => $max, 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]]]
                        : ['model' => $model, 'max_tokens' => $max, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => $user]]];
        if (function_exists('opsh_ai_http_stub')) $raw = opsh_ai_http_stub($isO, $payload);
        else {
            if (!function_exists('curl_init')) throw new RuntimeException('The server cannot call the AI service (no curl).');
            $hdr = $isO ? ['Content-Type: application/json', 'Authorization: Bearer ' . $key] : ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
            $ch = curl_init($isO ? 'https://api.openai.com/v1/chat/completions' : 'https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 90]);
            $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($raw === false || $code >= 400) throw new RuntimeException('The AI service did not answer (HTTP ' . $code . '). Check the key and the model in Settings → Sign-in & AI.');
        }
        $d = json_decode((string)$raw, true);
        $t = $isO ? (string)($d['choices'][0]['message']['content'] ?? '') : implode('', array_map(fn($b) => ($b['type'] ?? '') === 'text' ? (string)$b['text'] : '', (array)($d['content'] ?? [])));
        if (trim($t) === '') throw new RuntimeException('The AI service returned no text.');
        return $t;
    }
    /** The JSON object in a model's answer (fences and prose around it are ignored), or null. */
    function opsh_ai_json(string $t): ?array {
        $t = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($t)));
        $j = json_decode($t, true);
        if (!is_array($j)) { $a = strpos($t, '{'); $b = strrpos($t, '}'); if ($a !== false && $b > $a) $j = json_decode(substr($t, $a, $b - $a + 1), true); }
        return is_array($j) ? $j : null;
    }
}
