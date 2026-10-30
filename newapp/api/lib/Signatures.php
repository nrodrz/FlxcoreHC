<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — e-signature helpers (BT91, code audit M-27 and the signature Lows).
 *
 *  - Only known document types: SOPs, job descriptions, Knowledge Base articles and equipment loans.
 *  - The title, the text and the version come from the document itself, never from the request.
 *  - A request remembers the document as it was sent (its version, and a fingerprint of its text); a signature is taken
 *    only while the document is still that text. A changed document needs a new request.
 *  - Links for people outside the app stop working after ITOPS_SIGN_LINK_DAYS days.
 */
require_once __DIR__ . '/HtmlClean.php';

if (!defined('ITOPS_SIGN_LINK_DAYS')) define('ITOPS_SIGN_LINK_DAYS', 30);

if (!function_exists('itops_sig_modules')) {

    /** doc_type => permission module */
    function itops_sig_modules(): array {
        return ['sops' => 'sops', 'job_descriptions' => 'job_descriptions', 'kb' => 'kb', 'kb_article' => 'kb', 'asset_loan' => 'assets'];
    }

    /** The document behind a request: ['title','content','version','fp','status'] or null when it does not exist (or the type is unknown). */
    function itops_sig_doc(PDO $db, string $type, int $id): ?array {
        $tbl = ['sops' => 'itops_sops', 'job_descriptions' => 'itops_job_descriptions', 'kb' => 'itops_kb_articles', 'kb_article' => 'itops_kb_articles', 'asset_loan' => 'itops_asset_loans'][$type] ?? null;
        if (!$tbl || $id <= 0) return null;
        try {
            if ($type === 'asset_loan') {
                $q = $db->prepare("SELECT l.content, l.borrower_name, a.asset_tag, a.make, a.model FROM itops_asset_loans l LEFT JOIN itops_assets a ON a.id=l.asset_id WHERE l.id=?");
                $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                $title = 'Equipment loan: ' . trim(($r['asset_tag'] ?? '') . ' ' . trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? ''))) . ($r['borrower_name'] ? ' — ' . $r['borrower_name'] : '');
                $content = (string)($r['content'] ?? ''); $version = ''; $status = '';
            } else {
                $hasVer = in_array($type, ['sops', 'job_descriptions'], true);
                $q = $db->prepare("SELECT title, content, status" . ($hasVer ? ', current_version' : '') . " FROM {$tbl} WHERE id=?");   // $tbl from the fixed list above
                $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
                if (!$r) return null;
                $title = (string)$r['title']; $content = (string)($r['content'] ?? ''); $status = (string)($r['status'] ?? '');
                $version = $hasVer ? (string)(int)($r['current_version'] ?? 1) : '';
            }
        } catch (Throwable $e) { return null; }
        return ['title' => mb_substr($title, 0, 255), 'content' => $content, 'version' => $version, 'fp' => itops_sig_fp($content), 'status' => $status];
    }

    /** A fingerprint of the document's text (whitespace-insensitive), stored with the request. */
    function itops_sig_fp(string $content): string {
        return md5(preg_replace('/\s+/', ' ', trim($content)));
    }

    /** Has the document changed since the request was sent? (requests made before BT91 have no fingerprint: not checked) */
    function itops_sig_changed(PDO $db, array $req): bool {
        if (empty($req['doc_fp'])) return false;
        $d = itops_sig_doc($db, (string)$req['doc_type'], (int)$req['doc_id']);
        return !$d || !hash_equals((string)$req['doc_fp'], $d['fp']);
    }

    function itops_sig_cols(PDO $db): bool {
        static $ok = null;
        if ($ok === null) { try { $db->query('SELECT doc_fp, doc_version FROM itops_signature_requests LIMIT 0'); $db->query('SELECT token_expires FROM itops_signature_signers LIMIT 0'); $ok = true; } catch (Throwable $e) { $ok = false; } }
        return $ok;
    }
}
