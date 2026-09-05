<?php
/** Allowlisted HTML for editable rich-text fields. */

function nibblySanitizeRichHtml(string $html): string {
    if ($html === '') return '';
    if (!class_exists('DOMDocument')) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $body = $document->getElementsByTagName('body')->item(0);
    if (!$body) return '';
    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'span', 'div'];
    $removeTags = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base'];
    $clean = function (DOMNode $parent) use (&$clean, $allowedTags, $removeTags): void {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) {
                $parent->removeChild($node);
                continue;
            }
            if (!$node instanceof DOMElement) continue;
            $tag = strtolower($node->tagName);
            if (in_array($tag, $removeTags, true)) {
                $parent->removeChild($node);
                continue;
            }
            $clean($node);
            if (!in_array($tag, $allowedTags, true)) {
                while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                $parent->removeChild($node);
                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                $allowed = in_array($name, ['class', 'title', 'lang', 'dir'], true)
                    || ($tag === 'a' && in_array($name, ['href', 'target', 'rel'], true))
                    || ($tag === 'ol' && in_array($name, ['start', 'reversed'], true));
                if ($name === 'style') {
                    $styles = [];
                    foreach (explode(';', $value) as $declaration) {
                        $pair = explode(':', $declaration, 2);
                        $property = strtolower(trim($pair[0]));
                        $cssValue = trim($pair[1] ?? '');
                        if (in_array($property, ['color', 'background-color', 'text-align', 'font-weight', 'font-style', 'text-decoration', 'font-size', 'letter-spacing', 'line-height'], true)
                            && preg_match('/^[a-zA-Z0-9#.,%()\s-]+$/D', $cssValue)
                            && !preg_match('/url|expression|var\s*\(/i', $cssValue)) {
                            $styles[] = $property . ': ' . $cssValue;
                        }
                    }
                    if ($styles) $node->setAttribute('style', implode('; ', $styles));
                    else $node->removeAttribute('style');
                    continue;
                }
                if (!$allowed) {
                    $node->removeAttribute($name);
                    continue;
                }
                if ($name === 'href') {
                    $url = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if (str_contains($url, '\\') || str_starts_with($url, '//')
                        || (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $scheme)
                            && !in_array(strtolower($scheme[1]), ['http', 'https', 'mailto', 'tel'], true))) {
                        $node->setAttribute('href', '#');
                    }
                }
                if ($name === 'target' && !in_array($value, ['_blank', '_self'], true)) $node->removeAttribute('target');
            }
            if ($tag === 'a' && $node->getAttribute('target') === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
        }
    };
    $clean($body);
    $result = '';
    foreach ($body->childNodes as $node) $result .= $document->saveHTML($node);
    return $result;
}
