<?php

declare(strict_types=1);

// nibblyNetSafeFetch(): address classification, host/URL rules, DNS pinning,
// manual redirects, streaming size limit and Content-Type allowlist.
// Network behaviour runs against a loopback fixture server; resolution is injected.

$root = dirname(__DIR__);
require_once $root . '/includes/ai/agent/net.php';
require_once $root . '/includes/ai/ai-helper.php';
require_once $root . '/includes/ai/copilot-context.php';

function netAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function netRejects(callable $call, string $reason, string $message): void {
    try {
        $call();
    } catch (NibblyNetException $e) {
        netAssert($e->reason === $reason, "{$message} (expected {$reason}, got {$e->reason}: {$e->getMessage()})");
        return;
    }
    fwrite(STDERR, "FAIL: {$message} (no exception)\n");
    exit(1);
}

const PUBLIC_IP = '93.184.216.34';

$publicResolver = static fn(string $host): array => [PUBLIC_IP];

// --- Address classification ---------------------------------------------------
$private = [
    '0.0.0.0', '10.1.2.3', '100.64.0.1', '100.127.255.255', '127.0.0.1', '127.255.255.254',
    '169.254.169.254', '172.16.0.1', '172.31.255.255', '192.0.0.8', '192.0.2.1', '192.168.1.1',
    '198.18.0.1', '198.19.255.255', '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.255',
    '240.0.0.1', '255.255.255.255',
    '::', '::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '::127.0.0.1', '64:ff9b::808:808',
    '2002:7f00:1::', '2002:a9fe:a9fe::', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'febf::1',
    '2001:db8::1', 'ff02::1',
];
foreach ($private as $ip) {
    netAssert(!nibblyNetIsPublicIp($ip), "{$ip} must not be public");
}
$public = [
    '8.8.8.8', '93.184.216.34', '100.63.255.255', '100.128.0.1', '172.15.255.255', '172.32.0.1',
    '192.0.1.1', '192.169.0.1', '198.17.255.255', '198.20.0.1', '223.255.255.255',
    '2606:4700:4700::1111', '2a00:1450:4001::200e', '2002:5db8:d822::',
];
foreach ($public as $ip) {
    netAssert(nibblyNetIsPublicIp($ip), "{$ip} must be public");
}
netAssert(!nibblyNetIsPublicIp('not-an-ip') && !nibblyNetIsPublicIp(''), 'non-addresses are not public');

// Legacy numeric notations
netAssert(nibblyNetParseLegacyIpv4('2130706433') === '127.0.0.1', 'decimal integer');
netAssert(nibblyNetParseLegacyIpv4('0x7f.1') === '127.0.0.1', 'hex with short form');
netAssert(nibblyNetParseLegacyIpv4('0177.0.0.1') === '127.0.0.1', 'octal');
netAssert(nibblyNetParseLegacyIpv4('127.1') === '127.0.0.1', 'short form');
netAssert(nibblyNetParseLegacyIpv4('example.com') === null && nibblyNetParseLegacyIpv4('08.1') === null, 'names and invalid octal');

// --- URL and host rules (no network: nothing here may reach the transport) -----
$noTransport = static function (): void {
    fwrite(STDERR, "FAIL: a rejected URL reached the transport\n");
    exit(1);
};
$opts = ['resolver' => $publicResolver, 'testTransport' => $noTransport];
$cases = [
    ['http://127.0.0.1/', 'blocked_address'],
    ['http://169.254.169.254/latest/meta-data/', 'blocked_address'],
    ['http://[::1]/', 'blocked_address'],
    ['http://[::ffff:127.0.0.1]/', 'blocked_address'],
    ['http://[::ffff:7f00:1]/', 'blocked_address'],
    ['http://[2002:7f00:1::]/', 'blocked_address'],
    ['http://[fe80::1]/', 'blocked_address'],
    ['http://0.0.0.0/', 'blocked_address'],
    ['http://10.0.0.1/', 'blocked_address'],
    ['http://2130706433/', 'invalid_host'],
    ['http://0x7f.0.0.1/', 'invalid_host'],
    ['http://0177.0.0.1/', 'invalid_host'],
    ['http://127.1/', 'invalid_host'],
    ['http://localhost/', 'blocked_host'],
    ['http://LOCALHOST./', 'blocked_host'],
    ['http://service.localhost/', 'blocked_host'],
    ['http://intranet/', 'blocked_host'],
    ['ftp://example.com/file', 'scheme'],
    ['file:///etc/passwd', 'invalid_url'],
    ['gopher://example.com/', 'scheme'],
    ['http://user:secret@example.com/', 'credentials'],
    ['http://example.com@127.0.0.1/', 'credentials'],
    ['http://example.com:8080/', 'port'],
    ['https://example.com:22/', 'port'],
    ['http://example.com\\@127.0.0.1/', 'invalid_url'],
    ["http://example.com/\r\nHost: evil", 'invalid_url'],
    ['http://exa mple.com/', 'invalid_url'],
    ['', 'invalid_url'],
    ['/relative/path', 'invalid_url'],
    ['http://' . str_repeat('a', 300) . '.example/', 'invalid_host'],
];
foreach ($cases as [$url, $reason]) {
    netRejects(fn() => nibblyNetSafeFetch($url, $opts), $reason, "URL {$url}");
}
// Resolution results
foreach ([
    [['10.0.0.5'], 'blocked_address'],
    [['127.0.0.1'], 'blocked_address'],
    [['::1'], 'blocked_address'],
    [['::ffff:127.0.0.1'], 'blocked_address'],
    [[PUBLIC_IP, '10.0.0.5'], 'blocked_address'],
    [['2606:4700:4700::1111', '169.254.169.254'], 'blocked_address'],
    [[], 'unresolved'],
] as [$ips, $reason]) {
    netRejects(fn() => nibblyNetSafeFetch('http://rebind.test/', ['resolver' => static fn() => $ips, 'testTransport' => $noTransport]), $reason, 'resolver answer ' . json_encode($ips));
}
if (function_exists('idn_to_ascii')) {
    $prepared = nibblyNetPrepareUrl('http://BÜCHER.example/Pfad?q=1#frag', ['resolver' => $publicResolver]);
    netAssert($prepared['host'] === 'xn--bcher-kva.example' && $prepared['url'] === 'http://xn--bcher-kva.example/Pfad?q=1', 'IDN host is converted to ASCII, fragment dropped');
}
$prepared = nibblyNetPrepareUrl('HTTPS://Example.COM./a/b', ['resolver' => $publicResolver]);
netAssert($prepared['url'] === 'https://example.com/a/b' && $prepared['port'] === 443 && $prepared['ips'] === [PUBLIC_IP], 'host normalised: case, trailing dot, default port');
// IPv4 first when both families come back
$prepared = nibblyNetPrepareUrl('http://dual.test/', ['resolver' => static fn() => ['2606:4700:4700::1111', PUBLIC_IP]]);
netAssert($prepared['ips'][0] === PUBLIC_IP, 'IPv4 addresses are tried first');
// Test overrides must not exist outside the CLI SAPIs (guard documented in net.php).
netAssert(in_array(PHP_SAPI, ['cli', 'cli-server'], true), 'tests run under CLI');

// --- Redirect resolution ------------------------------------------------------
$base = 'http://example.com/a/b/c.html?x=1';
foreach ([
    ['/root', 'http://example.com/root'],
    ['sibling.html', 'http://example.com/a/b/sibling.html'],
    ['../up.html', 'http://example.com/a/up.html'],
    ['../../../../way/up', 'http://example.com/way/up'],
    ['?q=2', 'http://example.com/a/b/c.html?q=2'],
    ['//other.test/x', 'http://other.test/x'],
    ['https://secure.test/y', 'https://secure.test/y'],
    ['./here/', 'http://example.com/a/b/here/'],
] as [$location, $expected]) {
    netAssert(nibblyNetResolveRedirect($base, $location) === $expected, "redirect {$location} -> " . nibblyNetResolveRedirect($base, $location));
}
netRejects(fn() => nibblyNetResolveRedirect($base, ''), 'redirect', 'empty Location');

// --- DNS pinning and streaming contract with an injected transport ------------
$resolutions = [];
$rebinding = static function (string $host) use (&$resolutions): array {
    $resolutions[] = $host;
    return count($resolutions) === 1 ? [PUBLIC_IP] : ['127.0.0.1']; // the second answer is an attack
};
$seen = null;
$transport = static function (array $request, callable $onHeader, callable $onData) use (&$seen): void {
    $seen = $request;
    $onHeader("HTTP/1.1 200 OK\r\n");
    $onHeader("Content-Type: text/html; charset=utf-8\r\n");
    $onHeader("\r\n");
    $onData('<p>hello</p>');
};
$response = nibblyNetSafeFetch('http://rebind.example/page', ['resolver' => $rebinding, 'testTransport' => $transport]);
netAssert(count($resolutions) === 1, 'the host is resolved exactly once per hop');
netAssert($seen['ips'] === [PUBLIC_IP] && $seen['dial'] === [PUBLIC_IP] && $seen['host'] === 'rebind.example' && $seen['port'] === 80, 'the validated address is pinned, later DNS answers are ignored');
netAssert($response['body'] === '<p>hello</p>' && $response['contentType'] === 'text/html' && $response['ip'] === PUBLIC_IP, 'response is returned');

// Streaming: the transfer is aborted at the first chunk that crosses the limit.
$delivered = 0;
$streaming = static function (array $request, callable $onHeader, callable $onData) use (&$delivered): void {
    $onHeader("HTTP/1.1 200 OK\r\n");
    $onHeader("Content-Type: text/html\r\n");
    $onHeader("\r\n");
    for ($i = 0; $i < 40; $i++) {
        $delivered++;
        if ($onData(str_repeat('x', 256 * 1024)) === false) {
            return; // like curl: stop when the write callback signals an error
        }
    }
};
netRejects(fn() => nibblyNetSafeFetch('http://big.test/', ['resolver' => $publicResolver, 'testTransport' => $streaming]), 'too_large', 'streamed 10 MB body against the 2 MB default');
netAssert($delivered === 9, "body streaming stopped at the limit ({$delivered} chunks)");
$small = nibblyNetSafeFetch('http://big.test/', ['resolver' => $publicResolver, 'testTransport' => $streaming, 'maxBytes' => 12 * 1024 * 1024]);
netAssert(strlen($small['body']) === 40 * 256 * 1024, 'a higher limit lets the same body through');

// Content-Length above the limit aborts before the body
$bodyReads = 0;
$declared = static function (array $request, callable $onHeader, callable $onData) use (&$bodyReads): void {
    $onHeader("HTTP/1.1 200 OK\r\n");
    $onHeader("Content-Type: text/html\r\n");
    $onHeader("Content-Length: 3145728\r\n");
    if ($onHeader("\r\n") === false) {
        return;
    }
    $bodyReads++;
    $onData('x');
};
netRejects(fn() => nibblyNetSafeFetch('http://big.test/', ['resolver' => $publicResolver, 'testTransport' => $declared]), 'too_large', 'declared 3 MB body');
netAssert($bodyReads === 0, 'declared size is rejected from the headers alone');

// Content-Type allowlist
$typed = static fn(string $type) => static function (array $request, callable $onHeader, callable $onData) use ($type): void {
    $onHeader("HTTP/1.1 200 OK\r\n");
    if ($type !== '') $onHeader("Content-Type: {$type}\r\n");
    if ($onHeader("\r\n") !== false) $onData('payload');
};
foreach (['application/pdf', 'image/svg+xml', 'application/octet-stream', 'text/html-evil', ''] as $type) {
    netRejects(fn() => nibblyNetSafeFetch('http://t.test/', ['resolver' => $publicResolver, 'testTransport' => $typed($type)]), 'content_type', 'content type "' . $type . '"');
}
foreach (['text/html', 'TEXT/HTML; charset=UTF-8', 'application/xhtml+xml', 'text/plain;charset=utf-8'] as $type) {
    netAssert(nibblyNetSafeFetch('http://t.test/', ['resolver' => $publicResolver, 'testTransport' => $typed($type)])['body'] === 'payload', "content type {$type} accepted");
}
netAssert(nibblyNetSafeFetch('http://t.test/', ['resolver' => $publicResolver, 'testTransport' => $typed('image/webp'), 'allowedContentTypes' => ['image/webp']])['body'] === 'payload', 'custom allowlist');

// Non-2xx and missing response
$statusOnly = static fn(int $code) => static function (array $request, callable $onHeader, callable $onData) use ($code): void {
    $onHeader("HTTP/1.1 {$code} X\r\n");
    $onHeader("Content-Type: text/html\r\n");
    $onHeader("\r\n");
    $onData(str_repeat('e', 3 * 1024 * 1024));
};
try {
    nibblyNetSafeFetch('http://t.test/', ['resolver' => $publicResolver, 'testTransport' => $statusOnly(500)]);
    netAssert(false, 'HTTP 500 must fail');
} catch (NibblyNetException $e) {
    netAssert($e->reason === 'http_status' && $e->httpStatus === 500, 'error pages report their status, not a size violation');
}
netRejects(fn() => nibblyNetSafeFetch('http://t.test/', ['resolver' => $publicResolver, 'testTransport' => static function (): void {}]), 'transport', 'no response');

// Redirects through the transport: every hop is re-validated and re-resolved
$hops = [];
$redirecting = static function (array $request, callable $onHeader, callable $onData) use (&$hops): void {
    $hops[] = $request['url'];
    if ($request['url'] === 'http://start.test/') {
        $onHeader("HTTP/1.1 302 Found\r\n");
        $onHeader("Location: http://second.test/next\r\n");
        $onHeader("\r\n");
        return;
    }
    $onHeader("HTTP/1.1 200 OK\r\n");
    $onHeader("Content-Type: text/plain\r\n");
    $onHeader("\r\n");
    $onData('done');
};
$resolved = [];
$perHost = static function (string $host) use (&$resolved): array {
    $resolved[] = $host;
    return $host === 'second.test' ? ['198.51.100.1'] : [PUBLIC_IP]; // 198.51.100.0/24 is documentation space
};
netRejects(fn() => nibblyNetSafeFetch('http://start.test/', ['resolver' => $perHost, 'testTransport' => $redirecting]), 'blocked_address', 'redirect to a host resolving to a non-public address');
netAssert($hops === ['http://start.test/'] && $resolved === ['start.test', 'second.test'], 'the second hop was resolved and refused before any request');

// --- Real curl against the loopback fixture ------------------------------------
if (!function_exists('curl_init')) {
    echo "SKIP curl transport: curl extension missing\n";
    echo "PASS agent net smoke (without curl)\n";
    exit(0);
}

function netStartServer(string $address, string $router, ?string &$error = null) {
    $workers = ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv();
    $process = proc_open([PHP_BINARY, '-S', $address, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname($router), $workers);
    if (!is_resource($process)) {
        $error = 'cannot start php -S';
        return null;
    }
    [$host, $port] = [substr($address, 0, strrpos($address, ':')), (int)substr($address, strrpos($address, ':') + 1)];
    $stream = null;
    for ($i = 0; $i < 100 && !$stream; $i++) {
        $stream = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.2);
        if (!$stream) usleep(50000);
    }
    if (!$stream) {
        proc_terminate($process);
        $error = 'server did not start';
        return null;
    }
    fclose($stream);
    return $process;
}

function netFreePort(string $host = '127.0.0.1'): int {
    $server = stream_socket_server('tcp://' . $host . ':0', $errno, $errstr);
    $name = stream_socket_get_name($server, false);
    fclose($server);
    return (int)substr($name, strrpos($name, ':') + 1);
}

$port = netFreePort();
// Serve a throw-away copy of the fixture: never run a server out of the source tree.
$serverDir = sys_get_temp_dir() . '/nibbly-net-smoke-' . bin2hex(random_bytes(4));
mkdir($serverDir, 0700, true);
$router = $serverDir . '/net-server.php';
copy(__DIR__ . '/fixtures/agent/net-server.php', $router);
register_shutdown_function(static function () use ($router, $serverDir): void {
    @unlink($router);
    @rmdir($serverDir);
});
$server = netStartServer('127.0.0.1:' . $port, $router, $startError);
netAssert($server !== null, 'fixture server: ' . ($startError ?? ''));

try {
    $resolverMap = static fn(string $host): array => match ($host) {
        'internal.test' => ['10.0.0.5'],
        default => [PUBLIC_IP],
    };
    $live = [
        'resolver' => $resolverMap,
        'testAllowedPorts' => [$port, 80, 443],
        'testConnectMap' => [PUBLIC_IP => '127.0.0.1'], // dial the fixture, validate against the public address
        'timeout' => 10,
    ];
    $fetch = static fn(string $path, array $extra = []) => nibblyNetSafeFetch('http://public.test:' . $port . $path, $extra + $live);

    $ok = $fetch('/ok');
    netAssert($ok['status'] === 200 && $ok['body'] === '<p>ok</p>' && $ok['contentType'] === 'text/html', 'plain GET through the pinned connection');
    netAssert($fetch('/host')['body'] === 'public.test:' . $port, 'the Host header keeps the original name while the pinned address is dialled');
    $cookieProbe = $fetch('/cookie');
    netAssert($fetch('/cookie')['body'] === 'none' && $cookieProbe['body'] === 'none', 'cookies are never replayed');

    netRejects(fn() => $fetch('/pdf'), 'content_type', 'application/pdf over HTTP');
    netRejects(fn() => $fetch('/no-type'), 'content_type', 'missing Content-Type over HTTP');
    netRejects(fn() => $fetch('/big-length'), 'too_large', '3 MB body with Content-Length');
    netRejects(fn() => $fetch('/big-stream'), 'too_large', '3 MB body without Content-Length');
    $image = $fetch('/image', ['allowedContentTypes' => ['image/png']]);
    netAssert(str_starts_with($image['body'], "\x89PNG"), 'allowed image type passes');
    try {
        $fetch('/gone');
        netAssert(false, '404 must fail');
    } catch (NibblyNetException $e) {
        netAssert($e->reason === 'http_status' && $e->httpStatus === 404, '404 with a 3 MB error page reports the status');
    }

    // Redirects
    $viaRedirect = $fetch('/redirect-ok');
    netAssert($viaRedirect['body'] === '<p>ok</p>' && count($viaRedirect['redirects']) === 1 && str_ends_with($viaRedirect['url'], '/ok'), 'relative redirect is followed manually');
    netAssert($fetch('/redirect-absolute')['body'] === '<p>ok</p>', 'absolute redirect to the same public host');
    netAssert($fetch('/chain/2')['body'] === '<p>ok</p>' && count($fetch('/chain/2')['redirects']) === 3, 'three redirects are allowed');
    netRejects(fn() => $fetch('/chain/3'), 'redirect_limit', 'four redirects');
    netRejects(fn() => $fetch('/redirect-loop'), 'redirect_limit', 'redirect loop');
    netRejects(fn() => $fetch('/redirect-loopback'), 'blocked_address', 'redirect to 127.0.0.1');
    netRejects(fn() => $fetch('/redirect-metadata'), 'blocked_address', 'redirect to the cloud metadata address');
    netRejects(fn() => $fetch('/redirect-ipv6'), 'blocked_address', 'redirect to an IPv4-mapped IPv6 loopback');
    netRejects(fn() => $fetch('/redirect-internal-name'), 'blocked_address', 'redirect to a name resolving to 10.0.0.5');
    netRejects(fn() => $fetch('/redirect-scheme'), 'scheme', 'redirect to ftp://');
    netRejects(fn() => $fetch('/chain/3', ['maxRedirects' => 9]), 'redirect_limit', 'a caller cannot raise the redirect cap above 3');

    // DNS rebinding with real sockets: validation sees the public address once; the
    // connection must land on the pinned (mapped) address even if DNS later says 127.0.0.1.
    $calls = 0;
    $rebind = static function (string $host) use (&$calls): array {
        return ++$calls === 1 ? [PUBLIC_IP] : ['127.0.0.1'];
    };
    $pinned = nibblyNetSafeFetch('http://rebind.test:' . $port . '/ok', ['resolver' => $rebind] + $live);
    netAssert($pinned['body'] === '<p>ok</p>' && $pinned['ip'] === PUBLIC_IP && $calls === 1, 'rebinding: pinned address wins, single resolution');

    // IPv4 and IPv6 literals as hosts are checked, not looked up
    netRejects(fn() => nibblyNetSafeFetch('http://127.0.0.1:' . $port . '/ok', $live), 'blocked_address', 'loopback literal even on the fixture port');

    // curl must refuse to talk to anything but the pinned address: a dead mapping fails, it does not fall back to DNS
    netRejects(fn() => nibblyNetSafeFetch('http://public.test:' . $port . '/ok', ['testConnectMap' => [PUBLIC_IP => '127.0.0.2']] + $live), 'transport', 'unreachable pinned address (no fallback to a second lookup)');
} finally {
    proc_terminate($server);
    proc_close($server);
}

// IPv6 pinning (CURLOPT_RESOLVE with a bracketed address) when the host has ::1
$probe = @stream_socket_server('tcp://[::1]:0', $errno, $errstr);
if ($probe) {
    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    $port6 = (int)substr($name, strrpos($name, ':') + 1);
    $server6 = netStartServer('[::1]:' . $port6, $router, $startError6);
    if ($server6 !== null) {
        try {
            $global6 = '2606:4700:4700::1111';
            $response = nibblyNetSafeFetch('http://six.test:' . $port6 . '/ok', [
                'resolver' => static fn() => [$global6],
                'testAllowedPorts' => [$port6],
                'testConnectMap' => [$global6 => '::1'],
                'timeout' => 10,
            ]);
            netAssert($response['body'] === '<p>ok</p>' && $response['ip'] === $global6, 'IPv6 address pinned and dialled');
        } finally {
            proc_terminate($server6);
            proc_close($server6);
        }
    } else {
        echo "SKIP IPv6 pinning: {$startError6}\n";
    }
} else {
    echo "SKIP IPv6 pinning: no IPv6 loopback\n";
}

// --- Callers: Copilot reference images and the AI host check -------------------
foreach (['http://localhost/image.jpg', 'http://127.0.0.1/i.jpg', 'http://192.168.1.10/i.jpg', 'http://[::1]/i.jpg', 'http://2130706433/i.jpg', 'http://169.254.169.254/i.jpg'] as $url) {
    try {
        nibblyCopilotDownloadExternalReferenceImage($url);
        netAssert(false, "reference image download must refuse {$url}");
    } catch (RuntimeException $e) {
        netAssert(!($e instanceof NibblyNetException) && $e->getPrevious() instanceof NibblyNetException, "refusal of {$url} is reported as RuntimeException with the network cause");
    }
}

foreach (['localhost', 'LOCALHOST', 'app.localhost', '127.0.0.1', '127.1', '[::1]', '::1', '::ffff:127.0.0.1', '2130706433', '0x7f.0.0.1', '10.0.0.8', '192.168.0.5', '169.254.169.254', 'fe80::1', 'fd00::1', '172.20.1.1'] as $host) {
    netAssert(nibblyAiIsPrivateHost($host), "{$host} is a private host");
}
foreach (['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111', '', 'definitely-not-registered.invalid'] as $host) {
    netAssert(!nibblyAiIsPrivateHost($host), "'{$host}' is not reported private");
}
netAssert(nibblyAiIsLocalBaseUrl('http://[::ffff:127.0.0.1]:11434/v1') && nibblyAiIsLocalBaseUrl('http://2130706433:1234/v1'), 'local base URLs in IPv6-mapped and decimal notation are detected');
try {
    nibblyAiValidateBaseUrl('http://[::ffff:10.0.0.1]/v1', false);
    netAssert(false, 'private provider URL must need the explicit local setting');
} catch (RuntimeException $e) {
}
nibblyAiValidateBaseUrl('http://[::ffff:10.0.0.1]/v1', true);

echo "PASS agent net: CIDR list (IPv4/IPv6), URL rules, DNS pinning, manual redirects, streaming limit, content types, callers\n";
