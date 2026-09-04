<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/html-sanitizer.php';
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
echo "Security smoke test passed.\n";
