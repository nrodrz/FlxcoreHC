<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — /api/docs   (BT60: document branding for PDF and Word documents)
 *
 * GET /docs/brand                       the organization name, footer, colour and which logo documents use   (signed in)
 * GET /docs/sample?format=pdf|docx      a one-page sample with the current branding                           (Branding · View)
 *
 * The branding itself is saved through /settings (doc_org_name, doc_footer, doc_accent) and the logo through
 * POST /settings/doclogo — both write the Audit Log.
 */
require_once __DIR__ . '/../lib/DocExport.php';
$db = getDB();
$what = (string)($segments[1] ?? '');

if ($what === 'brand' && $method === 'GET') {
    $b = itops_doc_brand($db);
    json_response(['org' => $b['org'], 'footer' => $b['footer'], 'accent' => '#' . $b['accent'], 'logo_from' => $b['logo_from']]);
}
if ($what === 'sample' && $method === 'GET') {
    itops_require($currentUser, 'branding', 'view');
    $b = itops_doc_brand($db); $fmt = ($_GET['format'] ?? 'pdf') === 'docx' ? 'docx' : 'pdf';
    $d = new ItopsDoc(['title' => 'Sample document', 'subtitle' => 'How PDF and Word documents from ' . (function_exists('itops_app_name') ? itops_app_name() : 'ITOps') . ' look', 'org' => $b['org'], 'logo' => $b['logo'],
                      'footer' => $b['footer'], 'accent' => $b['accent'], 'toc' => false, 'status' => 'Sample', 'cover' => [['Prepared by', (string)($currentUser['display_name'] ?? '')], ['Date', date('m/d/Y')]]]);
    $d->h1('Section heading')->p('Body text of the document. Tables, figures and charts use the colour chosen in Settings → Branding → Documents.')
      ->kpis([['Figure', '$12,345', 'A note under it'], ['Share', '42.0%', 'Of the total']])
      ->table([['label' => 'Item', 'w' => 4], ['label' => 'Amount', 'w' => 1.5, 'align' => 'R']], [['First item', '$1,000'], ['Second item', '$2,500'], ['cells' => ['Total', '$3,500'], 'style' => 'total']]);
    $name = 'sample-document.' . $fmt;
    header('Content-Type: ' . ($fmt === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    header('Content-Disposition: ' . (!empty($_GET['preview']) ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo $fmt === 'pdf' ? itops_doc_pdf($d) : itops_doc_docx($d);
    exit;
}
error_response('Not found', 404);
