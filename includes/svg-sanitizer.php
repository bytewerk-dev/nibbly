<?php
/**
 * Neutralise active content in uploaded SVG files.
 *
 * SVG is XML and may carry scripts, event handlers, external references and
 * SMIL animations that set dangerous attributes. Browsers execute these when
 * the file is opened directly (`/assets/images/logo.svg`), so an uploaded SVG
 * is a stored-XSS vector. This sanitiser rewrites the file with the active
 * parts removed; it refuses files it cannot parse or that carry an inline DTD.
 */

/** Allow only navigational schemes in SVG hyperlinks; block javascript:, data:, etc. */
function nibblySvgIsSafeHref(string $value): bool {
    $probe = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($probe === '') return true;
    if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', (string)$probe, $match)) {
        return in_array(strtolower($match[1]), ['http', 'https', 'mailto', 'tel'], true);
    }
    return true; // relative path, fragment or query — safe
}

/**
 * Rewrite an SVG file in place with active content removed. Returns false when
 * the file cannot be parsed as SVG or carries a construct we refuse to store
 * (inline DTD/entities — XXE and entity-expansion risk); the caller then
 * discards the upload.
 */
function nibblySanitizeSvgFile(string $path): bool {
    if (!class_exists('DOMDocument')) return false; // cannot sanitise → refuse the upload
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return false;
    // No inline DTD or entities: defeats XXE and billion-laughs before parsing.
    if (preg_match('/<!(DOCTYPE|ENTITY)/i', $raw)) return false;

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$document->documentElement
        || strtolower($document->documentElement->localName) !== 'svg') {
        return false;
    }

    // Elements that run code or embed foreign/remote content have no place in a static image.
    $removeTags = ['script', 'foreignobject', 'handler', 'iframe', 'embed', 'object', 'audio', 'video'];
    // Animation elements are kept unless they target an event handler or a hyperlink.
    $animationTags = ['set', 'animate', 'animatetransform', 'animatemotion'];

    // Strip event handlers, dangerous link targets and url() payloads from one element.
    $cleanAttributes = function (DOMElement $element): void {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->localName);
            $value = (string)$attribute->value;
            if (str_starts_with($name, 'on')) { $element->removeAttributeNode($attribute); continue; }
            if ($name === 'href' && !nibblySvgIsSafeHref($value)) { $element->removeAttributeNode($attribute); continue; }
            // Paint/style properties can reference javascript: or data: via url(...).
            if (in_array($name, ['style', 'filter', 'fill', 'stroke', 'mask', 'clip-path'], true)
                && preg_match('/url\s*\(\s*["\']?\s*(javascript|data)\s*:/i', $value)) {
                $element->removeAttributeNode($attribute);
            }
        }
    };

    $walk = function (DOMNode $node) use (&$walk, $removeTags, $animationTags, $cleanAttributes): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMProcessingInstruction) { $node->removeChild($child); continue; }
            if (!$child instanceof DOMElement) continue;

            $tag = strtolower($child->localName);
            if (in_array($tag, $removeTags, true)) { $node->removeChild($child); continue; }

            // SMIL that animates onload/href can inject script; drop the whole element.
            if (in_array($tag, $animationTags, true)) {
                $attributeName = strtolower(trim($child->getAttribute('attributeName')));
                if ($attributeName !== '' && (str_starts_with($attributeName, 'on')
                    || $attributeName === 'href' || $attributeName === 'xlink:href')) {
                    $node->removeChild($child);
                    continue;
                }
            }

            $cleanAttributes($child);
            $walk($child);
        }
    };
    // The root <svg> can carry onload/href itself, so clean it before recursing.
    $cleanAttributes($document->documentElement);
    $walk($document->documentElement);

    $output = $document->saveXML();
    if ($output === false) return false;
    return @file_put_contents($path, $output) !== false;
}
