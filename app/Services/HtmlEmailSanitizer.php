<?php
declare(strict_types=1);
namespace App\\Services;

final class HtmlEmailSanitizer
{
    private const ALLOWED = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'p', 'br', 'div', 'span',
        'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'hr', 'table', 'thead', 'tbody', 'tfoot', 'tr',
        'td', 'th',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'form', 'input', 'button', 'textarea', 'select', 'option', 'svg',
        'math', 'template', 'link', 'meta', 'base', 'video', 'audio',
    ];

    public function sanitize(string $html): string
    {
        if ($html === '') return '';
        if (strlen($html) > 1048576) {
            $html = substr($html, 0, 1048576);
        }
        if (!class_exists(\\DOMDocument::class)) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \\DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!doctype html><html><head><meta charset="UTF-8"></head><body><div id="c0m-email-root">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $root = $document->getElementById('c0m-email-root');
        if (!$root) return '';
        $this->cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        return $result;
    }

    private function cleanChildren(\\DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof \\DOMComment || !($node instanceof \\DOMElement)) {
                if (!($node instanceof \\DOMText)) $parent->removeChild($node);
                $node = $next;
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                $node = $next;
                continue;
            }
            if (!in_array($tag, self::ALLOWED, true)) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                $node = $next;
                continue;
            }

            $href = null;
            if ($tag === 'a') {
                $candidate = trim($node->getAttribute('href'));
                if (preg_match('#^(https?://|mailto:)#i', $candidate)
                    && !preg_match('/[\\r\\n\\x00-\\x20]/', $candidate)) {
                    $href = $candidate;
                }
            }
            while ($node->attributes->length > 0) {
                $node->removeAttributeNode($node->attributes->item(0));
            }
            if ($href !== null) {
                $node->setAttribute('href', $href);
                $node->setAttribute('rel', 'noopener noreferrer');
            }
            $this->cleanChildren($node);
            $node = $next;
        }
    }
}
