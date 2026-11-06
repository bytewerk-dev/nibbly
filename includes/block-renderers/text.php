<?php
/**
 * Block renderer: text
 * Data: title, titleTag, content, style (includes/text-styles.php), and an optional image:
 * image (path), imageAlt, imagePosition (right|left|end), imageSize (medium|small|hang),
 * imageStyle (shadow|plain). The figure is rendered after the title and before the content,
 * so a floated image does not push a clearing heading down. Without site markup it is
 * .block-text__image with one modifier class per option (base rules in css/style.css); a site
 * can supply its own figure through siteTextBlockFigure(array $section, string $position, string $img).
 *
 * @param array $section Section data
 * @param bool $editable Whether in edit mode
 * @return string HTML output
 */

$html = '';
// style key → CSS class (includes/text-styles.php, content/text-styles.json); "" = plain block
$styleClass = textStyleClass($section['style'] ?? '');

if ($styleClass !== '') {
    $html .= '<div class="' . htmlspecialchars($styleClass) . '">' . "\n";
}

$title = $section['title'] ?? '';
$tag = $section['titleTag'] ?? 'h2';
$allowedTitleTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
if (!in_array($tag, $allowedTitleTags)) {
    $tag = 'h2';
}

// Optional image; in the editor the image manager writes sections.N.image / imageAlt
$imageSrc = trim((string)($section['image'] ?? ''));
$imagePosition = in_array($section['imagePosition'] ?? '', ['right', 'left', 'end'], true) ? $section['imagePosition'] : 'right';
$imageSize = in_array($section['imageSize'] ?? '', ['medium', 'small', 'hang'], true) ? $section['imageSize'] : 'medium';
$imageStyle = in_array($section['imageStyle'] ?? '', ['shadow', 'plain'], true) ? $section['imageStyle'] : 'shadow';
$figure = '';
if ($imageSrc !== '' && preg_match('#^(/|https?://)#i', $imageSrc)) {
    $imageAlt = (string)($section['imageAlt'] ?? '');
    // srcset/sizes from media-sizes.php; the figure is 25 to 50 % wide on wide screens (css/style.css)
    $sizes = '(min-width: 40rem) ' . ['medium' => '40vw', 'small' => '25vw', 'hang' => '50vw'][$imageSize] . ', 100vw';
    $img = $editable
        ? editableImageSplit($page, "sections.$index.image", "sections.$index.imageAlt", $imageSrc, $imageAlt, '', ['sizes' => $sizes])
        : nibblyAiLabelAttach(nibblyImageTag($imageSrc, $imageAlt, ['sizes' => $sizes]), $imageSrc, true);
    if (function_exists('siteTextBlockFigure')) {
        $figure = siteTextBlockFigure($section, $imagePosition, $img);
    } else {
        $figureClasses = ['block-text__image', 'block-text__image--' . $imagePosition, 'block-text__image--' . $imageSize, 'block-text__image--' . $imageStyle];
        $figure = '<figure class="' . implode(' ', $figureClasses) . '">' . $img . "</figure>\n";
    }
}

if ($editable) {
    $html .= "<{$tag}>" . editableText($page, "sections.$index.title", $title) . "</{$tag}>\n";
    $html .= $figure;
    $html .= editableHtml($page, "sections.$index.content", $section['content'] ?? '');
} else {
    if ($title !== '') {
        $html .= "<{$tag}>" . htmlspecialchars($title) . "</{$tag}>\n";
    }
    $html .= $figure;
    $html .= sanitizeHtml($section['content'] ?? '');
}

if ($styleClass !== '') {
    $html .= '</div>' . "\n";
}

return $html;
