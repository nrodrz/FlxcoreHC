<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * ITOps — HTML cleaned when it is saved (BT91, code audit M-29).
 *
 * Knowledge Base articles, SOPs and job descriptions keep rich text. The app cleans it again when it shows it
 * (DOMPurify), but other readers of the same text (the public help portal, signing pages, documents made from it,
 * the API) must get safe text too, so it is cleaned before it is stored:
 *  - an allow-list of tags; script, style, frames, objects, forms, SVG and MathML are removed with what they hold;
 *    any other unknown tag is removed and its text kept;
 *  - an allow-list of attributes; no event handlers (on…);
 *  - links and images only to http(s), mailto, tel, the page itself (#…) or a relative address; images may also be
 *    PNG, JPEG, GIF or WEBP data;
 *  - inline styles kept without url(), expression(), behavior, -moz-binding or javascript.
 * Text that has no markup at all is returned as it was.
 */

if (!function_exists('itops_html_clean')) {

    function itops_html_clean($html, bool $filter = true): string {   // $filter false: only parsed and written back (to compare)
        $html = (string)($html ?? '');
        if ($html === '' || strpos($html, '<') === false) return $html;
        if (!class_exists('DOMDocument')) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');   // no DOM extension: keep the text only

        static $TAGS = ['a','abbr','b','blockquote','br','caption','cite','code','col','colgroup','dd','del','details','div','dl','dt','em','figcaption','figure',
                        'font','h1','h2','h3','h4','h5','h6','hr','i','img','ins','kbd','li','mark','ol','p','pre','q','s','samp','small','span','strike','strong',
                        'sub','summary','sup','table','tbody','td','tfoot','th','thead','tr','u','ul','section','article','header','footer','aside','nav','main',
                        'time','var','wbr','center'];
        static $DROP = ['script','style','iframe','frame','frameset','object','embed','applet','form','input','button','textarea','select','option','optgroup',
                        'link','meta','base','svg','math','template','noscript','audio','video','source','track','canvas','portal','dialog','title','head','xml'];
        static $ATTR = ['href','target','rel','src','alt','title','width','height','colspan','rowspan','scope','headers','style','class','id','align','valign',
                        'border','cellpadding','cellspacing','color','face','size','start','type','reversed','datetime','cite','open','lang','dir','name','span'];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><body><div id="itops-clean-root">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = null;
        foreach ($doc->getElementsByTagName('div') as $d) { if ($d->getAttribute('id') === 'itops-clean-root') { $root = $d; break; } }
        if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');

        $urlOk = function (string $u, bool $img): bool {
            $u = trim(preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($u === '') return true;
            if ($img && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$#i', $u)) return true;
            if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $u, $m)) return in_array(strtolower($m[1]), $img ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'], true);
            return true;   // relative, #anchor, /path, //host is treated as relative to the scheme (http/https)
        };
        $styleOk = function (string $css): string {
            $css = html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $css = preg_replace('#/\*.*?\*/#s', '', $css);
            $out = []; $dropped = false;
            foreach (explode(';', $css) as $decl) {
                if (trim($decl) === '') continue;
                if (strpos($decl, ':') === false) { $dropped = true; continue; }
                [$p, $v] = array_map('trim', explode(':', $decl, 2));
                if (!preg_match('/^-?[a-z][a-z\-]*$/i', $p) || $v === '') { $dropped = true; continue; }
                if (preg_match('/url\s*\(|expression|javascript|vbscript|behavior|binding|@import|\\\\|[<>]/i', $v) || preg_match('/^-moz-binding|^behavior$/i', $p)) { $dropped = true; continue; }
                $out[] = strtolower($p) . ': ' . $v;
            }
            return $dropped ? implode('; ', $out) : trim($css);   // nothing unsafe: the style is kept as written
        };

        $walk = function (DOMNode $node) use (&$walk, $TAGS, $DROP, $ATTR, $urlOk, $styleOk) {
            for ($c = $node->firstChild; $c !== null; $c = $next) {
                $next = $c->nextSibling;
                if ($c->nodeType === XML_COMMENT_NODE || $c->nodeType === XML_PI_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) { $node->removeChild($c); continue; }
                if ($c->nodeType !== XML_ELEMENT_NODE) continue;
                $tag = strtolower($c->nodeName);
                if (in_array($tag, $DROP, true)) { $node->removeChild($c); continue; }
                if (!in_array($tag, $TAGS, true)) {          // unknown tag: keep what it holds, drop the tag
                    $walk($c);
                    while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                    $node->removeChild($c);
                    continue;
                }
                $remove = [];
                foreach ($c->attributes as $at) {
                    $an = strtolower($at->nodeName); $av = (string)$at->nodeValue;
                    if (!in_array($an, $ATTR, true) && strpos($an, 'data-') !== 0 && strpos($an, 'aria-') !== 0) { $remove[] = $at->nodeName; continue; }
                    if (($an === 'href' || $an === 'cite') && !$urlOk($av, false)) { $remove[] = $at->nodeName; continue; }
                    if ($an === 'src' && ($tag !== 'img' || !$urlOk($av, true))) { $remove[] = $at->nodeName; continue; }
                    if ($an === 'style') { $st = $styleOk($av); if ($st === '') $remove[] = $at->nodeName; else $c->setAttribute('style', $st); continue; }
                    if (strpos($an, 'data-') === 0 && preg_match('/javascript:|<|>/i', $av)) { $remove[] = $at->nodeName; continue; }
                    // Review CORE-26: id and name are prefixed, so content cannot shadow the page's own objects (DOM clobbering);
                    // links to a place in the same text follow the prefix.
                    if (($an === 'id' || $an === 'name') && $av !== '' && strpos($av, 'u-') !== 0) { $c->setAttribute($at->nodeName, 'u-' . $av); continue; }
                    if ($an === 'href' && strlen($av) > 1 && $av[0] === '#' && strpos($av, '#u-') !== 0) { $c->setAttribute('href', '#u-' . substr($av, 1)); continue; }
                }
                foreach ($remove as $an) $c->removeAttribute($an);
                if ($tag === 'a' && strtolower($c->getAttribute('target')) === '_blank') $c->setAttribute('rel', 'noopener noreferrer');
                $walk($c);
            }
        };
        if ($filter) $walk($root);

        $out = '';
        foreach ($root->childNodes as $ch) $out .= $doc->saveHTML($ch);
        return $out;
    }

    /** Clean the 'content' of a request body when it is there (KB, SOPs, job descriptions). */
    function itops_html_clean_body(array &$body, string $field = 'content'): void {
        if (array_key_exists($field, $body) && $body[$field] !== null && !is_array($body[$field])) $body[$field] = itops_html_clean((string)$body[$field]);
    }
}
