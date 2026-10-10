<?php
/**
 * Hardened outbound HTTP for server-side fetches of user- or model-supplied URLs.
 *
 * nibblyNetSafeFetch() only talks to public internet addresses:
 *  - http/https on ports 80/443, no credentials in the URL, IDN converted to ASCII;
 *  - every A/AAAA record is checked against an explicit CIDR list (PHP 8.1 has no
 *    FILTER_FLAG_GLOBAL_RANGE); one non-public address rejects the host;
 *  - the checked addresses are pinned with CURLOPT_RESOLVE, so a second DNS answer
 *    (rebinding) can never redirect the connection;
 *  - redirects are followed manually (max. 3) and every hop is validated again;
 *  - the body size is enforced while streaming, the Content-Type against an allowlist;
 *  - no cookies, no environment proxies (a proxy would resolve names itself).
 */

if (!class_exists('NibblyNetException', false)) {
    class NibblyNetException extends RuntimeException {
        public string $reason;
        public int $httpStatus;

        public function __construct(string $reason, string $message, int $httpStatus = 0) {
            parent::__construct($message);
            $this->reason = $reason;
            $this->httpStatus = $httpStatus;
        }
    }
}

const NIBBLY_NET_MAX_REDIRECTS = 3;
const NIBBLY_NET_MAX_URL_LENGTH = 2048;
const NIBBLY_NET_DEFAULT_MAX_BYTES = 2 * 1024 * 1024;
const NIBBLY_NET_DEFAULT_CONTENT_TYPES = ['text/html', 'application/xhtml+xml', 'text/plain'];

/** IPv4 ranges that are not publicly routable, as [network, prefix length]. */
function nibblyNetBlockedIpv4Ranges(): array {
    return [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
        ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];
}

/**
 * IPv6 ranges that are not publicly routable. 2002::/16 (6to4) is handled
 * separately because it is only as public as the IPv4 address embedded in it.
 */
function nibblyNetBlockedIpv6Ranges(): array {
    return [
        ['::', 96],            // unspecified, loopback and IPv4-compatible addresses
        ['::ffff:0:0', 96],    // IPv4-mapped
        ['64:ff9b::', 96],     // NAT64
        ['64:ff9b:1::', 48],   // local-use NAT64
        ['100::', 64],         // discard-only
        ['2001::', 32],        // Teredo
        ['2001:db8::', 32],    // documentation
        ['fc00::', 7],         // unique local
        ['fe80::', 10],        // link-local
        ['fec0::', 10],        // deprecated site-local
        ['ff00::', 8],         // multicast
    ];
}

function nibblyNetBinaryInRange(string $binary, string $network, int $prefix): bool {
    $networkBinary = inet_pton($network);
    if ($networkBinary === false || strlen($networkBinary) !== strlen($binary)) {
        return false;
    }
    $fullBytes = intdiv($prefix, 8);
    if ($fullBytes > 0 && substr($binary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
        return false;
    }
    $remainder = $prefix % 8;
    if ($remainder === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remainder)) & 0xFF;
    return (ord($binary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
}

/** True for a literal IPv4/IPv6 address that is globally routable. */
function nibblyNetIsPublicIp(string $ip): bool {
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    $binary = inet_pton($ip);
    if ($binary === false) {
        return false;
    }
    if (strlen($binary) === 4) {
        foreach (nibblyNetBlockedIpv4Ranges() as [$network, $prefix]) {
            if (nibblyNetBinaryInRange($binary, $network, $prefix)) {
                return false;
            }
        }
        return true;
    }
    foreach (nibblyNetBlockedIpv6Ranges() as [$network, $prefix]) {
        if (nibblyNetBinaryInRange($binary, $network, $prefix)) {
            return false;
        }
    }
    if (nibblyNetBinaryInRange($binary, '2002::', 16)) {
        // 6to4 embeds the IPv4 address in bytes 2-5.
        return nibblyNetIsPublicIp(inet_ntop(substr($binary, 2, 4)) ?: '0.0.0.0');
    }
    return true;
}

/**
 * Legacy inet_aton notation (2130706433, 0x7f.1, 0177.0.0.1, 127.1). Browsers, curl
 * and libc accept these as IPv4 addresses, so they must never reach a hostname check.
 */
function nibblyNetParseLegacyIpv4(string $host): ?string {
    if ($host === '' || !preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+)){0,3}$/i', $host)) {
        return null;
    }
    $parts = explode('.', $host);
    $numbers = [];
    foreach ($parts as $part) {
        if (stripos($part, '0x') === 0) {
            $digits = substr($part, 2);
            $value = $digits === '' ? 0 : (preg_match('/^[0-9a-f]+$/i', $digits) ? hexdec($digits) : null);
        } elseif (strlen($part) > 1 && $part[0] === '0') {
            $value = preg_match('/^[0-7]+$/', $part) ? octdec($part) : null;
        } else {
            $value = (int)$part;
        }
        if ($value === null || $value < 0 || $value > 0xFFFFFFFF) {
            return null;
        }
        $numbers[] = $value;
    }
    $last = array_pop($numbers);
    $count = count($numbers);
    if ($last >= 256 ** (4 - $count)) {
        return null;
    }
    $address = $last;
    foreach ($numbers as $index => $number) {
        if ($number > 255) {
            return null;
        }
        $address += $number * (256 ** (3 - $index));
    }
    return long2ip((int)$address) ?: null;
}

/** Normalised lowercase ASCII host without brackets or trailing dot, or null if unusable. */
function nibblyNetNormalizeHost(string $host): ?string {
    $host = trim($host);
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? strtolower($host) : null;
    }
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || strlen($host) > 253 || str_contains($host, '%')) {
        return null;
    }
    if (preg_match('/[^\x20-\x7E]/', $host)) {
        if (!function_exists('idn_to_ascii')) {
            return null;
        }
        $ascii = @idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($ascii) || $ascii === '') {
            return null;
        }
        $host = strtolower($ascii);
    }
    return preg_match('/^[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?$/', $host) ? $host : null;
}

/** Default resolver: DNS A and AAAA records, falling back to the system resolver. */
function nibblyNetResolveHost(string $host): array {
    $ips = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) $ips[] = (string)$record['ip'];
                if (!empty($record['ipv6'])) $ips[] = (string)$record['ipv6'];
            }
        }
    }
    if (!$ips) {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            $ips = array_merge($ips, $resolved);
        }
    }
    return array_values(array_unique(array_filter($ips, 'is_string')));
}

/**
 * Validate a URL and resolve its host exactly once.
 *
 * @return array{url:string,scheme:string,host:string,port:int,path:string,ips:string[]}
 */
function nibblyNetPrepareUrl(string $url, array $options = []): array {
    $url = trim($url);
    if ($url === '' || strlen($url) > NIBBLY_NET_MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
        throw new NibblyNetException('invalid_url', 'Invalid URL.');
    }
    $url = explode('#', $url, 2)[0];
    // parse_url() mangles uppercase non-ASCII bytes in the host; lowercase the authority first.
    if (function_exists('mb_strtolower') && preg_match('#^([a-z][a-z0-9+.-]*://)([^/?\#]*)(.*)$#is', $url, $match)) {
        $url = $match[1] . mb_strtolower($match[2], 'UTF-8') . $match[3];
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        throw new NibblyNetException('invalid_url', 'Invalid URL.');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new NibblyNetException('scheme', 'Only http and https URLs are allowed.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new NibblyNetException('credentials', 'URLs with credentials are not allowed.');
    }
    $allowedPorts = [80, 443];
    $testing = in_array(PHP_SAPI, ['cli', 'cli-server'], true);
    if ($testing && is_array($options['testAllowedPorts'] ?? null)) {
        $allowedPorts = array_map('intval', $options['testAllowedPorts']);
    }
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, $allowedPorts, true)) {
        throw new NibblyNetException('port', 'Only ports 80 and 443 are allowed.');
    }

    $host = nibblyNetNormalizeHost((string)$parts['host']);
    if ($host === null) {
        throw new NibblyNetException('invalid_host', 'Invalid host name.');
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $labels = explode('.', $host);
        $lastLabel = end($labels);
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', (string)$lastLabel)) {
            // Numeric but not a canonical dotted quad: 2130706433, 0x7f.1, 0177.0.0.1 ...
            throw new NibblyNetException('invalid_host', 'Numeric host notation is not allowed.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || !str_contains($host, '.')) {
            throw new NibblyNetException('blocked_host', 'Host is not publicly reachable.');
        }
        $resolver = $options['resolver'] ?? 'nibblyNetResolveHost';
        $ips = is_callable($resolver) ? $resolver($host) : [];
        $ips = array_values(array_unique(array_filter(is_array($ips) ? $ips : [], 'is_string')));
        if (!$ips) {
            throw new NibblyNetException('unresolved', 'Host could not be resolved.');
        }
    }
    foreach ($ips as $ip) {
        if (!nibblyNetIsPublicIp($ip)) {
            throw new NibblyNetException('blocked_address', 'Host resolves to a non-public address.');
        }
    }
    // IPv4 first: a host without IPv6 connectivity still connects.
    usort($ips, static fn($a, $b) => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));

    $path = ($parts['path'] ?? '') !== '' ? (string)$parts['path'] : '/';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $defaultPort = $scheme === 'https' ? 443 : 80;
    if (isset($parts['port']) && $port !== $defaultPort) {
        $authority .= ':' . $port;
    }
    return [
        'url' => $scheme . '://' . $authority . $path . $query,
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'path' => $path . $query,
        'ips' => $ips,
    ];
}

/** Throws unless the URL passes every check of nibblyNetSafeFetch(); returns the resolved addresses. */
function nibblyNetAssertPublicUrl(string $url, array $options = []): array {
    return nibblyNetPrepareUrl($url, $options)['ips'];
}

/** Resolve a Location header against the URL it came from. */
function nibblyNetResolveRedirect(string $baseUrl, string $location): string {
    $location = trim($location);
    if ($location === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $location)) {
        throw new NibblyNetException('redirect', 'Invalid redirect target.');
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
        return $location;
    }
    $base = parse_url($baseUrl);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        throw new NibblyNetException('redirect', 'Invalid redirect base.');
    }
    $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    if (str_starts_with($location, '//')) {
        return $base['scheme'] . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        $combined = $location;
    } elseif (str_starts_with($location, '?')) {
        $combined = ($base['path'] ?? '/') . $location;
    } else {
        $directory = preg_replace('#/[^/]*$#', '/', (string)($base['path'] ?? '/')) ?: '/';
        $combined = $directory . $location;
    }
    $query = '';
    if (($position = strpos($combined, '?')) !== false) {
        $query = substr($combined, $position);
        $combined = substr($combined, 0, $position);
    }
    $segments = [];
    foreach (explode('/', $combined) as $segment) {
        if ($segment === '..') {
            array_pop($segments);
        } elseif ($segment !== '.' && $segment !== '') {
            $segments[] = $segment;
        }
    }
    return $origin . '/' . implode('/', $segments) . (str_ends_with($combined, '/') && $segments ? '/' : '') . $query;
}

function nibblyNetNormalizeContentType(string $contentType): string {
    return strtolower(trim(explode(';', $contentType, 2)[0]));
}

/**
 * Fetch a public URL with GET.
 *
 * Options: maxBytes (2 MB), allowedContentTypes (html/xhtml/plain), maxRedirects (3, at
 * most 3), connectTimeout (5 s), timeout (15 s), userAgent, accept, resolver (callable
 * host => string[], for tests and alternative resolvers). Under the CLI SAPIs the tests
 * may also pass testAllowedPorts, testConnectMap (validated IP => IP actually dialled)
 * and testTransport (callable replacing curl).
 *
 * @return array{status:int,url:string,contentType:string,headers:array<string,string>,body:string,redirects:string[],ip:string}
 * @throws NibblyNetException
 */
function nibblyNetSafeFetch(string $url, array $options = []): array {
    $testing = in_array(PHP_SAPI, ['cli', 'cli-server'], true);
    $transport = $testing && is_callable($options['testTransport'] ?? null) ? $options['testTransport'] : null;
    if ($transport === null && !function_exists('curl_init')) {
        throw new NibblyNetException('transport', 'Outbound fetches require the curl extension.');
    }
    $maxBytes = max(1, (int)($options['maxBytes'] ?? NIBBLY_NET_DEFAULT_MAX_BYTES));
    $maxRedirects = max(0, min(NIBBLY_NET_MAX_REDIRECTS, (int)($options['maxRedirects'] ?? NIBBLY_NET_MAX_REDIRECTS)));
    $allowedTypes = array_map('nibblyNetNormalizeContentType', (array)($options['allowedContentTypes'] ?? NIBBLY_NET_DEFAULT_CONTENT_TYPES));
    $connectMap = $testing && is_array($options['testConnectMap'] ?? null) ? $options['testConnectMap'] : [];

    $current = $url;
    $redirects = [];
    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        $target = nibblyNetPrepareUrl($current, $options);
        $request = [
            'url' => $target['url'],
            'host' => $target['host'],
            'port' => $target['port'],
            'ips' => $target['ips'],
            'dial' => array_map(static fn($ip) => $connectMap[$ip] ?? $ip, $target['ips']),
            'maxBytes' => $maxBytes,
            'allowedContentTypes' => $allowedTypes,
            'connectTimeout' => (int)($options['connectTimeout'] ?? 5),
            'timeout' => (int)($options['timeout'] ?? 15),
            'userAgent' => (string)($options['userAgent'] ?? 'nibbly-fetch/1.0'),
            'accept' => (string)($options['accept'] ?? 'text/html,application/xhtml+xml,text/plain;q=0.8'),
        ];
        $response = $transport !== null ? nibblyNetRunTransport($transport, $request) : nibblyNetCurlRequest($request);

        $status = (int)$response['status'];
        if ($status >= 300 && $status < 400 && ($response['headers']['location'] ?? '') !== '') {
            if ($hop >= $maxRedirects) {
                throw new NibblyNetException('redirect_limit', 'Too many redirects.');
            }
            $current = nibblyNetResolveRedirect($target['url'], (string)$response['headers']['location']);
            $redirects[] = $current;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            throw new NibblyNetException('http_status', 'The server answered with HTTP ' . $status . '.', $status);
        }
        return [
            'status' => $status,
            'url' => $target['url'],
            'contentType' => $response['contentType'],
            'headers' => $response['headers'],
            'body' => $response['body'],
            'redirects' => $redirects,
            'ip' => $target['ips'][0],
        ];
    }
    throw new NibblyNetException('redirect_limit', 'Too many redirects.');
}

/**
 * Run an injected transport with the same streaming contract as curl: the callbacks
 * abort the transfer by returning false, which keeps the limit logic under test.
 */
function nibblyNetRunTransport(callable $transport, array $request): array {
    $state = nibblyNetStreamState($request);
    $onHeader = static function (string $line) use (&$state): bool {
        return nibblyNetHandleHeaderLine($state, $line);
    };
    $onData = static function (string $chunk) use (&$state): bool {
        return nibblyNetHandleBodyChunk($state, $chunk);
    };
    $transport($request, $onHeader, $onData);
    return nibblyNetFinishResponse($state);
}

function nibblyNetStreamState(array $request): array {
    return [
        'maxBytes' => $request['maxBytes'],
        'allowedContentTypes' => $request['allowedContentTypes'],
        'status' => 0, 'headers' => [], 'contentType' => '', 'body' => '', 'size' => 0, 'violation' => null,
    ];
}

/** Handle one raw header line; false aborts the transfer. */
function nibblyNetHandleHeaderLine(array &$state, string $line): bool {
    $line = rtrim($line, "\r\n");
    if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $match)) {
        // A new status line starts a new header block (100-continue, redirects).
        $state['status'] = (int)$match[1];
        $state['headers'] = [];
        $state['contentType'] = '';
        return true;
    }
    if ($line === '') {
        $status = $state['status'];
        $isRedirect = $status >= 300 && $status < 400 && ($state['headers']['location'] ?? '') !== '';
        if ($status >= 200 && $status < 300 && !$isRedirect) {
            $type = nibblyNetNormalizeContentType((string)($state['headers']['content-type'] ?? ''));
            $length = $state['headers']['content-length'] ?? '';
            if (!in_array($type, $state['allowedContentTypes'], true)) {
                $state['violation'] = ['content_type', 'Unsupported content type.'];
                return false;
            }
            if (ctype_digit($length) && (int)$length > $state['maxBytes']) {
                $state['violation'] = ['too_large', 'The response is too large.'];
                return false;
            }
        }
        return true;
    }
    if (str_contains($line, ':')) {
        [$name, $value] = explode(':', $line, 2);
        $state['headers'][strtolower(trim($name))] = trim($value);
        if (strtolower(trim($name)) === 'content-type') {
            $state['contentType'] = nibblyNetNormalizeContentType($value);
        }
    }
    return true;
}

/** Handle one body chunk; false aborts the transfer once the limit is exceeded. */
function nibblyNetHandleBodyChunk(array &$state, string $chunk): bool {
    $state['size'] += strlen($chunk);
    $status = $state['status'];
    $keep = $status >= 200 && $status < 300;
    if ($state['size'] > $state['maxBytes']) {
        if ($keep) {
            $state['violation'] = ['too_large', 'The response is too large.'];
        }
        return false; // Error and redirect bodies are never kept, but never streamed endlessly either.
    }
    if ($keep) {
        $state['body'] .= $chunk;
    }
    return true;
}

function nibblyNetFinishResponse(array $state, string $transportError = ''): array {
    if ($state['violation'] !== null) {
        throw new NibblyNetException($state['violation'][0], $state['violation'][1]);
    }
    if ($state['status'] === 0) {
        throw new NibblyNetException('transport', $transportError !== '' ? 'Request failed: ' . $transportError : 'Request failed.');
    }
    return [
        'status' => $state['status'],
        'headers' => $state['headers'],
        'contentType' => $state['contentType'],
        'body' => $state['body'],
    ];
}

function nibblyNetCurlRequest(array $request): array {
    $state = nibblyNetStreamState($request);
    $resolveAddresses = array_map(static fn($ip) => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $request['dial']);
    $ch = curl_init($request['url']);
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => $request['connectTimeout'],
        CURLOPT_TIMEOUT => $request['timeout'],
        CURLOPT_USERAGENT => $request['userAgent'],
        CURLOPT_HTTPHEADER => ['Accept: ' . $request['accept']],
        CURLOPT_ENCODING => 'gzip,deflate',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$request['host'] . ':' . $request['port'] . ':' . implode(',', $resolveAddresses)],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$state): int {
            return nibblyNetHandleHeaderLine($state, $line) ? strlen($line) : 0;
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$state): int {
            return nibblyNetHandleBodyChunk($state, $chunk) ? strlen($chunk) : 0;
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
    } elseif (defined('CURLOPT_PROTOCOLS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);
    curl_exec($ch);
    $error = curl_error($ch);
    unset($ch);
    return nibblyNetFinishResponse($state, $error);
}
