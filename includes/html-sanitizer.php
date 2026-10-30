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
    // Rich text as editors and importers produce it: inline formatting, lists, headings, quotes,
    // figures with images and captions, tables, local audio/video. Embeds (iframe) stay out; a
    // site turns a link into a click-to-load player instead (see nibblySanitizeMediaSrc()).
    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'span', 'div',
        'figure', 'figcaption', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'sup', 'sub', 'hr', 'pre', 'code', 'small', 'cite', 'q', 'mark', 's', 'del', 'ins', 'abbr',
        'dl', 'dt', 'dd', 'details', 'summary', 'video', 'audio', 'source'];
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
                    || ($name === 'id' && preg_match('/^[A-Za-z][\w:.-]{0,80}$/', $value))
                    || ($tag === 'a' && in_array($name, ['href', 'target', 'rel'], true))
                    || ($tag === 'ol' && in_array($name, ['start', 'reversed'], true))
                    || ($tag === 'img' && in_array($name, ['src', 'alt', 'width', 'height', 'loading', 'decoding'], true))
                    || (in_array($tag, ['td', 'th'], true) && in_array($name, ['colspan', 'rowspan', 'scope'], true))
                    || (in_array($tag, ['video', 'audio'], true) && in_array($name, ['src', 'controls', 'preload', 'poster', 'playsinline', 'muted', 'loop'], true))
                    || ($tag === 'source' && in_array($name, ['src', 'type'], true))
                    || ($tag === 'details' && $name === 'open')
                    || ($tag === 'abbr' && $name === 'title')
                    || ($tag === 'time' && $name === 'datetime');
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
                // Media sources: same-origin paths or http(s) only; never data:, javascript: or protocol-relative
                if (in_array($name, ['src', 'poster'], true) && nibblySanitizeMediaSrc($value) === '') $node->removeAttribute($name);
                if (in_array($name, ['width', 'height', 'colspan', 'rowspan'], true) && !preg_match('/^\d{1,5}$/', $value)) $node->removeAttribute($name);
                if ($name === 'loading' && !in_array($value, ['lazy', 'eager'], true)) $node->removeAttribute($name);
                if ($name === 'decoding' && !in_array($value, ['async', 'sync', 'auto'], true)) $node->removeAttribute($name);
                if ($name === 'preload' && !in_array($value, ['none', 'metadata', 'auto'], true)) $node->removeAttribute($name);
                if ($name === 'type' && !preg_match('#^(audio|video)/[a-z0-9.+-]{1,30}$#i', $value)) $node->removeAttribute($name);
            }
            // An <img> or <source> without a usable src is dropped entirely
            if (in_array($tag, ['img', 'source'], true) && !$node->hasAttribute('src')) {
                $parent->removeChild($node);
                continue;
            }
            if ($tag === 'a' && $node->getAttribute('target') === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
        }
    };
    $clean($body);
    $result = '';
    foreach ($body->childNodes as $node) $result .= $document->saveHTML($node);
    return $result;
}

/**
 * A media source (img, video, audio, source, poster) that may be rendered: a site-relative path
 * ("/wp-content/uploads/a.webp", "assets/images/a.webp", "../assets/…") or an http(s) URL. Returns
 * '' for data:, javascript:, protocol-relative ("//host") and other unsafe values.
 */
function nibblySanitizeMediaSrc(string $url): string {
    $probe = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($probe === '' || str_contains($probe, '\\') || str_starts_with($probe, '//')) return '';
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $match) && !in_array(strtolower($match[1]), ['http', 'https'], true)) return '';
    return $url;
}

/**
 * Neutralise a hyperlink target from stored content. Returns the original URL
 * when it uses a safe scheme (or is relative/anchor/query), and "#" when it
 * uses a dangerous scheme such as javascript:, data: or vbscript:. Whitespace
 * and HTML entities are stripped before the scheme is examined so obfuscated
 * variants (e.g. "java&#9;script:") cannot slip through.
 */
function nibblySanitizeHref(string $url): string {
    $probe = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($probe !== '' && preg_match('/^([a-z][a-z0-9+.\-]*):/i', (string)$probe, $match)
        && !in_array(strtolower($match[1]), ['http', 'https', 'mailto', 'tel'], true)) {
        return '#';
    }
    return $url;
}
