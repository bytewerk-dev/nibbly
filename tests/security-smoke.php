<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/html-sanitizer.php';
require_once __DIR__ . '/../includes/svg-sanitizer.php';
require_once __DIR__ . '/../includes/session-helper.php';

function securityAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

foreach ([
    '<a href="java&#x73;cript:alert(1)">link</a>',
    '<a href="javascript&#58;alert(1)">link</a>',
    '<a href="java&#9;script:alert(1)">link</a>',
    '<a href=javascript:alert(1)>link</a>',
    '<a href="data:text/html,test">link</a>',
] as $html) {
    $clean = nibblySanitizeRichHtml($html);
    securityAssert(str_contains($clean, 'href="#"'), 'Unsafe link survived: ' . $clean);
}
$clean = nibblySanitizeRichHtml('<p onclick=alert(1) title="safe > title"><strong>Äpfel</strong><script>alert(1)</script><svg onload=alert(1)><a href="javascript:alert(1)">x</a></svg></p>');
securityAssert(!preg_match('/onclick|onload|script|svg|alert\(/i', $clean), 'Active markup survived');
securityAssert(str_contains($clean, '<strong>Äpfel</strong>'), 'Safe formatting lost');
$clean = nibblySanitizeRichHtml('<a href="https://example.com/?x=1&amp;y=2" target="_blank">link</a><p style="text-align:center;color:#123456;position:fixed">Text</p>');
securityAssert(str_contains($clean, 'noopener noreferrer'), 'External link lacks safe relationship');
securityAssert(str_contains($clean, 'text-align: center') && !str_contains($clean, 'position'), 'Text style handling failed');
$_SERVER['HTTP_HOST'] = 'localhost:3000';
securityAssert(nibblySessionRedirectUrl('http://localhost:3000/services/test#part') === '/services/test#part', 'Same-origin redirect lost');
securityAssert(nibblySessionRedirectUrl('/services/test?x=1') === '/services/test?x=1', 'Relative redirect lost');
foreach (['javascript:alert(1)', '//other.invalid/', '/\\other.invalid/', '/admin', '/a/../admin/', 'http://localhost:4000/'] as $url) {
    securityAssert(nibblySessionRedirectUrl($url) === '/', 'Unsafe redirect accepted: ' . $url);
}

// Stored link targets (editableLink, gallery, event cards): dangerous schemes → "#".
foreach (['javascript:alert(1)', 'JaVaScRiPt:x', "java\tscript:alert(1)", 'java&#9;script:x',
    'data:text/html,x', 'vbscript:msgbox(1)', 'file:///etc/passwd'] as $url) {
    securityAssert(nibblySanitizeHref($url) === '#', 'Unsafe href survived: ' . $url);
}
foreach (['https://example.com/?a=1&b=2', '/relative/path', '#anchor', 'about', 'mailto:a@b.c', 'tel:+43123'] as $url) {
    securityAssert(nibblySanitizeHref($url) === $url, 'Safe href altered: ' . $url);
}

// Uploaded SVG sanitiser: active content removed, structure kept, DTD refused.
$svgPath = tempnam(sys_get_temp_dir(), 'nibbly-svg-') . '.svg';
try {
    $writeSvg = function (string $svg) use ($svgPath): string {
        file_put_contents($svgPath, $svg);
        $ok = nibblySanitizeSvgFile($svgPath);
        return $ok ? (string)file_get_contents($svgPath) : "\0REJECTED";
    };
    $cleaned = $writeSvg('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script>'
        . '<a href="javascript:alert(1)"><path d="M1 1"/></a>'
        . '<foreignObject><b xmlns="http://www.w3.org/1999/xhtml">x</b></foreignObject></svg>');
    securityAssert($cleaned !== "\0REJECTED", 'Valid SVG was rejected');
    securityAssert(!preg_match('/<script|onload|javascript:|foreignObject/i', $cleaned), 'Active SVG content survived: ' . $cleaned);
    securityAssert(str_contains($cleaned, '<path'), 'SVG drawing content was lost');
    securityAssert($writeSvg('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
        . '<svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>') === "\0REJECTED", 'SVG with inline DTD was accepted');
    securityAssert($writeSvg('not an svg at all') === "\0REJECTED", 'Non-SVG upload was accepted as SVG');
} finally {
    @unlink($svgPath);
}

echo "Security smoke test passed.\n";
