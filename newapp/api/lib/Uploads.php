<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — shared upload allow-list for ticket attachments and public form files.
 * A file is accepted only when its EXTENSION is on the list AND the server-detected content type is plausible for
 * that extension (so a script renamed to .pdf is refused). The stored content type comes from this table, never
 * from the browser. SVG / HTML / scripts / executables are never accepted.
 */
if (!function_exists('itops_upload_types')) {
    /** ext => [content type to store, [plausible detected types (prefix match allowed with *)]] */
    function itops_upload_types(): array {
        $ole = ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/cdfv2', 'application/octet-stream'];
        $ooxml = ['application/vnd.openxmlformats-officedocument.*', 'application/zip', 'application/octet-stream'];
        $txt = ['text/plain', 'application/octet-stream', 'inode/x-empty'];
        return [
            // pictures
            'png' => ['image/png', ['image/png', 'image/jpeg', 'image/webp']], 'jpg' => ['image/jpeg', ['image/jpeg', 'image/png', 'image/webp']], 'jpeg' => ['image/jpeg', ['image/jpeg', 'image/png', 'image/webp']],
            'gif' => ['image/gif', ['image/gif']], 'webp' => ['image/webp', ['image/webp']], 'bmp' => ['image/bmp', ['image/bmp', 'image/x-ms-bmp']],
            'heic' => ['image/heic', ['image/heic', 'image/heif', 'application/octet-stream']],
            // audio / video
            'mp4' => ['video/mp4', ['video/*', 'audio/mp4', 'application/octet-stream']], 'mov' => ['video/quicktime', ['video/*']],
            'webm' => ['video/webm', ['video/*', 'audio/webm']], 'mp3' => ['audio/mpeg', ['audio/*', 'application/octet-stream']],
            'm4a' => ['audio/mp4', ['audio/*', 'video/mp4', 'application/octet-stream']], 'wav' => ['audio/wav', ['audio/*']], 'ogg' => ['audio/ogg', ['audio/*', 'video/ogg', 'application/ogg']],
            // documents
            'pdf' => ['application/pdf', ['application/pdf']],
            'doc' => ['application/msword', $ole], 'xls' => ['application/vnd.ms-excel', $ole], 'ppt' => ['application/vnd.ms-powerpoint', $ole],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', $ooxml],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $ooxml],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', $ooxml],
            'csv' => ['text/csv', array_merge($txt, ['text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'])],
            'txt' => ['text/plain', $txt], 'log' => ['text/plain', $txt],
            'xml' => ['application/xml', array_merge($txt, ['text/xml', 'application/xml'])],
            'json' => ['application/json', array_merge($txt, ['application/json'])],
        ];
    }
    function itops_upload_accept_attr(): string { return '.' . implode(',.', array_keys(itops_upload_types())); }
    /** Returns ['ext'=>…, 'mime'=>…] or ['error'=>message]. */
    function itops_upload_check(string $tmpPath, string $origName): array {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $types = itops_upload_types();
        $human = 'pictures, audio/video, PDF, Word, Excel, PowerPoint, CSV, TXT, XML and JSON';
        if ($ext === '' || !isset($types[$ext])) return ['error' => 'This file type isn\'t allowed. Allowed: ' . $human . '.'];
        $det = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath) : '';
        if ($det !== '') {
            $ok = false;
            foreach ($types[$ext][1] as $p) {
                if ($p === $det || (substr($p, -1) === '*' && strpos($det, substr($p, 0, -1)) === 0)) { $ok = true; break; }
            }
            // text formats: refuse anything that looks like markup/script even if detected as text
            if ($ok && in_array($ext, ['txt', 'log', 'csv', 'json'], true)) {
                $head = (string)@file_get_contents($tmpPath, false, null, 0, 2048);
                if (preg_match('/<\?php|<script\b|<html\b/i', $head)) $ok = false;
            }
            if (!$ok) return ['error' => 'The file content doesn\'t match its .' . $ext . ' extension.'];
        }
        return ['ext' => $ext, 'mime' => $types[$ext][0]];
    }
}
