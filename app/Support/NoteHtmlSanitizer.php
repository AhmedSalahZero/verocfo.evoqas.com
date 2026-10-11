<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  NoteHtmlSanitizer
//
//  AUDIT H1 FIX (Sept 2026). Cleans the HTML of Analyst Notes
//  (Sales Dashboard + Expense Dashboard) so a note can only ever
//  contain simple formatting — never a script.
//
//  Why: the note box saved whatever HTML it received and every
//  colleague's screen displayed it as live HTML (v-html). A crafted
//  note (e.g. <img src=x onerror=...>) would run inside the browser
//  of whoever opened the dashboard — including the Company Admin or
//  the Super Admin while inside the company — and act as them.
//
//  Rule — a strict ALLOW-list (anything not listed is not allowed):
//    Kept tags:     b strong i em u ul ol li br p div
//    Attributes:    NONE are kept (no onclick, no style, no href...)
//    Dangerous tags (script, style, iframe, svg, form, ...) are
//                   removed together with everything inside them.
//    Any other tag (span, font, a, img, ...) is removed but its text
//                   is kept, so a pasted note loses formatting,
//                   never words.
//    HTML comments are removed.
//
//  Used in TWO places, on purpose:
//    - on SAVE    (create / update)  → nothing unsafe is ever stored
//    - on DISPLAY (present())        → notes saved BEFORE this fix
//                                      are cleaned too
//
//  Uses PHP's built-in DOM parser — no new package.
//  Arabic and English text are preserved exactly (UTF-8).
//
//  @layer    Support
//  @depends  ext-dom (bundled with PHP / Laravel Herd)
// ══════════════════════════════════════════════════════════════════

use DOMDocument;
use DOMElement;
use DOMNode;

class NoteHtmlSanitizer
{
    /** Tags that are kept (with every attribute stripped). */
    private const ALLOWED = ['b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'br', 'p', 'div'];

    /** Tags removed together with all their content. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'svg', 'math', 'template', 'noscript', 'textarea', 'select', 'option', 'form',
        'input', 'button', 'link', 'meta', 'base', 'head', 'title', 'video', 'audio',
        'canvas', 'xml',
    ];

    public static function clean(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__vero_root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__vero_root');

        if (! $root) {
            // Could not parse at all — fall back to plain escaped text.
            return nl2br(e(strip_tags($html)));
        }

        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        // Copy the list first — we modify the tree while walking it.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE
                || $child->nodeType === XML_PI_NODE
                || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
                continue;
            }

            if (! $child instanceof DOMElement) {
                continue; // plain text — always safe (escaped on output)
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            self::walk($child);

            if (in_array($tag, self::ALLOWED, true)) {
                while ($child->attributes->length > 0) {
                    $child->removeAttributeNode($child->attributes->item(0));
                }
                continue;
            }

            // Not allowed → unwrap: keep the (already cleaned) children.
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
        }
    }
}
