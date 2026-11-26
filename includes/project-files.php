<?php
require_once __DIR__ . '/site-languages.php';
require_once __DIR__ . '/private-files.php';

/** Shared rules for the publication package, full-site ZIP and restore. */
final class NibblyProjectFiles {
    public const RULES = 'content/project-files.json';
    public const REPORT = '.nibbly-backup.json';

    private bool $externalAiSecrets;
    private ?string $siteSecretPath = null;
    private array $languages;
    private array $paths = [];
    private array $restorePhp = [];
    public array $privateFiles = [];

    public function __construct(string $root) {
        $this->languages = ($GLOBALS['SITE_LANGUAGES'] ?? []) + nibblySiteLanguageCatalog();
        $root = str_replace(DIRECTORY_SEPARATOR, '/', $root);
        $externalName = null;
        if (defined('NIBBLY_AI_SECRETS_PATH')) {
            $dir = realpath(dirname(NIBBLY_AI_SECRETS_PATH)) ?: dirname(NIBBLY_AI_SECRETS_PATH);
            $dir = str_replace(DIRECTORY_SEPARATOR, '/', $dir);
            if (str_starts_with($dir . '/', $root . '/')) {
                $this->siteSecretPath = substr($dir . '/' . basename(NIBBLY_AI_SECRETS_PATH), strlen($root) + 1);
            } else {
                $externalName = basename(NIBBLY_AI_SECRETS_PATH);
            }
        } elseif (nibblyPrivateDirectory() !== null) {
            $externalName = 'ai-secrets.json';
        }
        $this->externalAiSecrets = $externalName !== null;
        $file = $root . '/' . self::RULES;
        if (is_link($file) || is_link($root . '/content')) {
            throw new RuntimeException('Project rules must be a regular file inside the installation.');
        }
        if (!file_exists($file)) {
            $this->privateFiles = $externalName !== null ? [$externalName] : [];
            return;
        }
        $rules = json_decode((string)file_get_contents($file), false, 64, JSON_THROW_ON_ERROR);
        if (!is_object($rules) || array_diff(array_keys(get_object_vars($rules)), ['paths', 'restorePhp', 'privateFiles'])) {
            throw new RuntimeException('Invalid fields in ' . self::RULES);
        }
        $paths = property_exists($rules, 'paths') ? $rules->paths : new stdClass();
        if (!is_object($paths)) throw new RuntimeException('Project paths must be an object.');
        $entries = get_object_vars($paths);
        // Resolve parents first regardless of the order in the site's JSON.
        uksort($entries, static fn($a, $b) => strlen((string)$a) <=> strlen((string)$b));
        foreach ($entries as $name => $role) {
            $name = (string)$name;
            if (!self::validPath($name) || !in_array($role, ['publish', 'server', 'local'], true)) {
                throw new RuntimeException('Invalid project path or role: ' . $name);
            }
            $current = $this->role($name);
            if (!str_contains($name, '/')) {
                if ($current !== 'unknown' && !in_array($name, ['README.md', 'llms.txt'], true)) {
                    throw new RuntimeException('Reserved project root entry: ' . $name);
                }
            } else {
                $top = explode('/', $name, 2)[0];
                $siteOwned = isset($this->paths[$top]) || in_array($top, ['assets', 'templates'], true);
                // A nested rule may reserve a smaller subtree for the host or for local work.
                if (!$siteOwned || $role === 'publish' || in_array($current, ['private', 'local'], true)
                    || $name === 'assets/.htaccess') throw new RuntimeException('Invalid nested project rule: ' . $name);
            }
            $this->paths[$name] = $role;
        }
        foreach (['restorePhp', 'privateFiles'] as $key) {
            $files = property_exists($rules, $key) ? $rules->$key : [];
            if (!is_array($files)) throw new RuntimeException($key . ' must be a list of paths.');
            foreach ($files as $path) {
                if (!is_string($path) || !self::validPath($path)) throw new RuntimeException('Invalid path in ' . $key);
                if ($key === 'restorePhp' && (!str_ends_with($path, '.php') || !$this->isProjectPath($path)
                    || !in_array($this->role($path), ['publish', 'server'], true))) {
                    throw new RuntimeException('restorePhp requires an exact file in a published or server-owned project path: ' . $path);
                }
            }
            $this->$key = array_values(array_unique($files));
        }
        if ($externalName !== null && !in_array($externalName, $this->privateFiles, true)) $this->privateFiles[] = $externalName;
    }

    public static function validPath(string $path): bool {
        return $path !== '' && !preg_match('~[\x00-\x1f\x7f\\\\:]|(?:^|/)(?:\.{1,2})?(?:/|$)~', $path);
    }

    public function isProjectPath(string $path): bool {
        return isset($this->paths[explode('/', $path, 2)[0]]);
    }

    public function allowsPhp(string $path): bool {
        return in_array($path, $this->restorePhp, true);
    }

    /** A project declares additional root entries. Reserved paths keep their core rules. */
    public function role(string $path): string {
        $top = explode('/', $path, 2)[0];
        $base = basename($path);
        if (($this->externalAiSecrets && $path === 'content/ai-secrets.json')
            || preg_match('~(?:^|/)(?:local-only|not-for-git|\.git|\.svn|\.github|\.idea|\.vscode|\.claude|\.codex(?:-qa)?|node_modules|__pycache__)(?:/|$)~i', $path)
            || preg_match('~^(?:\.DS_Store|\._.*|\.gitkeep|Thumbs\.db|\.nibbly-json-.*|nibbly-access-check-.*|.*\.(?:swp|tmp|lock)|(?:.*-)?deploy(?:\.example)?\.sh)$~i', $base)
            || preg_match('~(?:^|/)\.restore-[^/]+(?:/|$)~', $path)
            || in_array($base, ['.gitattributes', '.gitignore', 'AGENTS.md', 'CLAUDE.md', 'AI-AGENT-GUIDE.md', 'SKILLS.md'], true)
            || in_array($top, ['tests', 'examples', 'screenshots', 'reference', 'nibbly-alt', 'nibbly-backup'], true)
            || in_array($path, [self::REPORT, 'CONTRIBUTING.md', 'architecture.md', 'design-qa.md', 'CHANGELOG.md',
                'ROUTING.md', 'UPGRADING.md', 'RELEASES.md', 'NIBBLY-UPGRADE-NOTES.md', 'cli/README.md',
                'package.json', 'package-lock.json', 'router.php'], true)
            || ($top === 'backups' && (str_ends_with($base, '.zip') || $base === 'backup.log'))) return 'local';

        if (preg_match('~^\.env(?:\..*)?$~i', $base)) return 'private';
        if ($path === $this->siteSecretPath) return 'server';
        if (!self::validPath($path)) return 'unsupported';
        for ($candidate = $path; $candidate !== '.' && $candidate !== ''; $candidate = dirname($candidate)) {
            if (isset($this->paths[$candidate])) return $this->paths[$candidate];
            if (!str_contains($candidate, '/')) break;
        }
        if (in_array($path, [self::RULES, 'content/.htaccess', 'content/schema.json', 'assets/.htaccess'], true)) return 'publish';
        if (in_array($path, ['admin/config.php', 'api/smtp-config.php', 'README.md', 'llms.txt'], true) || $top === 'backups') return 'server';
        if (in_array($path, ['includes/nav-config.php', 'content/settings/iconset.json'], true)) return 'seed';
        if ($top === 'content') {
            if (preg_match('~^content/(?:pages|news|forms)(?:/|$)|^content/(?:menus|events|people|faq|sidebar|comparison|media-meta)\.json$~', $path)) return 'seed';
            return 'server';
        }
        if (preg_match('~^assets/(?:images(?:-sizes|-originals)?|videos|audio|documents)-trash(?:/|$)~', $path)) return 'server';
        if (preg_match('~^assets/(?:images(?:-sizes|-originals)?|videos|audio|documents)(?:/|$)~', $path)) return 'seed';
        if (in_array($top, ['admin', 'api', 'assets', 'cli', 'css', 'includes', 'js', 'templates'], true)
            || isset($this->languages[$top])
            || in_array($path, ['.htaccess', 'index.php', 'route.php', 'error.php', '404.php', '410.php',
                'sitemap.php', 'robots.txt', 'favicon.ico', 'VERSION', 'LICENSE', 'THIRD-PARTY-NOTICES.md'], true)) return 'publish';
        return 'unknown';
    }

    /** Yield directories before their children. Never descend into excluded trees or links. */
    public function walk(string $root, string $dir = '', bool $preview = false): Generator {
        $items = new FilesystemIterator($dir === '' ? $root : "$root/$dir");
        foreach ($items as $file) {
            $name = $file->getFilename();
            $path = $dir === '' ? $name : "$dir/$name";
            $role = $this->role($path);
            if (!$file->isLink() && self::validPath($path) && ($file->isDir() || $file->isFile())) {
                yield $path => [$role, $file->isDir()];
                if ($file->isDir() && !in_array($role, ['local', 'private'], true) && !($preview && $role === 'unknown')) {
                    yield from $this->walk($root, $path, $preview);
                }
            } else {
                yield $path => [$role === 'local' ? 'local' : 'unsupported', false];
            }
        }
    }
}
