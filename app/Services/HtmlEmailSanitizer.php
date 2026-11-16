<?php
declare(strict_types=1);
namespace App\Services;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/** Conservative allow-list sanitizer for user-authored outbound HTML email. */
final class HtmlEmailSanitizer
{
    private const ALLOWED_TAGS = [
        'p','br','strong','b','em','i','u','s','ul','ol','li','blockquote',
        'pre','code','a','h1','h2','h3','div','span','hr','sub','sup',
    ];

    private const DROP_CONTENT_TAGS = [
        'script','style','iframe','object','embed','svg','math','form','input',
        'button','video','audio','source','link','meta','base','img','template',
        'canvas','noscript',
    ];

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') return null;
        if (!class_exists(DOMDocument::class)) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $source = '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="c0m-email-root">'
                . $html . '</div></body></html>';
            $loaded = $document->loadHTML($source, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
            if (!$loaded) {
                return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            }

            $root = $document->getElementById('c0m-email-root');
            if (!$root) {
                $divs = $document->getElementsByTagName('div');
                $root = $divs->item(0);
            }
            if (!$root) {
                return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            }

            self::sanitizeChildren($root);
            $clean = '';
            foreach (iterator_to_array($root->childNodes) as $child) {
                $clean .= $document->saveHTML($child);
            }
            return trim($clean) !== '' ? trim($clean) : null;
        } catch (\Throwable) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $parent->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                if (!$child instanceof DOMText) $parent->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_CONTENT_TAGS, true)) {
                $parent->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                foreach (iterator_to_array($child->childNodes) as $grandchild) {
                    $parent->insertBefore($grandchild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            $attributes = [];
            foreach ($child->attributes as $attribute) $attributes[] = $attribute->name;
            foreach ($attributes as $attribute) {
                if ($tag === 'a' && $attribute === 'href') {
                    if (!self::isSafeHref($child->getAttribute('href'))) $child->removeAttribute($attribute);
                    continue;
                }
                $child->removeAttribute($attribute);
            }
            self::sanitizeChildren($child);
        }
    }

    private static function isSafeHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || preg_match('/[\x00-\x20\x7F]/', $href)) return false;
        $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
