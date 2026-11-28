<?php
/** Per-page JSON snapshots. The page key includes the language and full nested path. */
require_once __DIR__ . '/json-store.php';
require_once __DIR__ . '/page-path.php';

class NibblyPageHistoryException extends RuntimeException {}

final class NibblyPageHistory {
    private const FILENAME = '/^([a-z0-9_-]+)_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})(?:_[a-f0-9]{6})?\.json$/D';
    private string $dir;
    private string $settingsPath;

    public function __construct(string $dir, ?string $settingsPath = null) {
        $this->dir = rtrim($dir, '/');
        $this->settingsPath = $settingsPath ?? dirname($this->dir) . '/content/settings.json';
    }

    public static function validPage(string $page): bool {
        return nibblyPageIsValidContentKey($page) || in_array($page, ['footer', 'sidebar'], true);
    }

    public static function owner(string $filename): ?string {
        return preg_match(self::FILENAME, $filename, $parts) && self::validPage($parts[1]) ? $parts[1] : null;
    }

    private function readJson(string $path): array {
        if (!file_exists($path)) return [];
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) throw new NibblyPageHistoryException('Could not read ' . basename($path));
        return $data;
    }

    public static function countLimit(): int {
        return max(0, defined('MAX_BACKUPS') ? (int)MAX_BACKUPS : 30);
    }

    public function mode(): string {
        $mode = $this->readJson($this->settingsPath)['pageHistoryMode'] ?? 'count';
        if (!in_array($mode, ['count', 'tiered'], true)) throw new NibblyPageHistoryException('Unknown page history mode');
        return $mode;
    }

    public function setMode(string $mode): void {
        if (!in_array($mode, ['count', 'tiered'], true)) throw new NibblyPageHistoryException('Unknown page history mode');
        if (!nibblyJsonUpdate($this->settingsPath, static function (&$settings) use ($mode) {
            $settings['pageHistoryMode'] = $mode;
        })) throw new NibblyPageHistoryException('Could not save page history settings');
    }

    private function pinsPath(string $page): string {
        if (!self::validPage($page)) throw new NibblyPageHistoryException('Invalid page name');
        return $this->dir . '/page-history/' . $page . '.json';
    }

    private function pins(string $page): array {
        $pins = $this->readJson($this->pinsPath($page));
        foreach ($pins as $filename => $label) {
            if (!is_string($filename) || self::owner($filename) !== $page || !is_string($label)) {
                throw new NibblyPageHistoryException('Invalid protection records for ' . $page);
            }
        }
        return $pins;
    }

    private function withLock(string $page, callable $work) {
        $lock = nibblyJsonLock($this->pinsPath($page));
        if (!$lock) throw new NibblyPageHistoryException('Could not lock page history');
        try {
            return $work();
        } finally {
            fclose($lock);
        }
    }

    public function pages(): array {
        $pages = [];
        foreach (glob($this->dir . '/*.json', GLOB_NOSORT) ?: [] as $path) {
            $page = self::owner(basename($path));
            if ($page !== null && is_file($path) && !is_link($path)) $pages[$page] = true;
        }
        ksort($pages);
        return array_keys($pages);
    }

    /** Selection for both the read-only preview and pruning, newest first. */
    public function plan(string $page, string $mode): array {
        if (!in_array($mode, ['count', 'tiered'], true)) throw new NibblyPageHistoryException('Unknown page history mode');
        $pins = $this->pins($page);
        $paths = glob($this->dir . '/' . $page . '_[0-9]*.json', GLOB_NOSORT) ?: [];
        rsort($paths, SORT_STRING); // The timestamp and same-second sequence are in the filename.
        $items = [];
        $recentLimit = $mode === 'count' ? self::countLimit() : 5;
        $automatic = 0;
        $buckets = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (!preg_match(self::FILENAME, $name, $parts) || $parts[1] !== $page || !is_file($path) || is_link($path)) continue;
            $time = "$parts[3]:$parts[4]:$parts[5]";
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "$parts[2] $time");
            if (!$date || !empty(DateTimeImmutable::getLastErrors()['warning_count'])) continue;
            $pinned = array_key_exists($name, $pins);
            $keep = $pinned;
            if (!$pinned) {
                $keep = ++$automatic <= $recentLimit;
                if ($mode === 'tiered') {
                    foreach (['Y-m-d' => 7, 'o-W' => 4, 'Y-m' => 12, 'Y' => 2] as $format => $limit) {
                        $key = $date->format($format);
                        if (count($buckets[$format] ?? []) < $limit && !isset($buckets[$format][$key])) {
                            $buckets[$format][$key] = true;
                            $keep = true;
                        }
                    }
                }
            }
            $items[] = ['filename' => $name, 'date' => $parts[2], 'time' => $time,
                'timestamp' => $date->getTimestamp(), 'pinned' => $pinned, 'label' => $pins[$name] ?? '', 'keep' => $keep];
        }
        return $items;
    }

    private function pruneLocked(string $page): void {
        // A damaged settings or protection file must preserve the history and allow editing.
        try {
            $plan = $this->plan($page, $this->mode());
        } catch (NibblyPageHistoryException $e) {
            error_log('Page history cleanup skipped for ' . $page . ': ' . $e->getMessage());
            return;
        }
        foreach ($plan as $item) {
            if (!$item['keep']) unlink($this->dir . '/' . $item['filename']);
        }
    }

    public function prune(string $page): void {
        $this->withLock($page, fn() => $this->pruneLocked($page));
    }

    public function snapshot(string $file, bool $prune = true): string {
        $page = basename($file, '.json');
        return $this->withLock($page, function () use ($page, $file, $prune) {
            // A fixed-width sequence also orders rapid saves with identical timestamps.
            $now = new DateTimeImmutable();
            $second = $now->getTimestamp();
            $microsecond = (int)$now->format('u');
            do {
                $prefix = $page . '_' . date('Y-m-d_His', $second++) . '_';
                $sameSecond = glob($this->dir . '/' . $prefix . str_repeat('[a-f0-9]', 6) . '.json') ?: [];
                $last = $sameSecond ? basename(end($sameSecond), '.json') : '';
                $sequence = $last === '' ? $microsecond : max($microsecond, hexdec(substr($last, -6)) + 1);
            } while ($sequence > 0xffffff);
            $name = $prefix . sprintf('%06x.json', $sequence);
            $temp = $this->dir . '/.page-backup-' . bin2hex(random_bytes(12)) . '.tmp';
            try {
                if (!@copy($file, $temp) || !rename($temp, $this->dir . '/' . $name)) {
                    throw new NibblyPageHistoryException('Could not create page backup');
                }
            } finally {
                if (is_file($temp)) unlink($temp);
            }
            if ($prune) $this->pruneLocked($page);
            return $name;
        });
    }

    public function changeProtection(string $page, string $filename, bool $pin, string $label): void {
        $this->withLock($page, function () use ($page, $filename, $pin, $label) {
            $this->checkSnapshot($page, $filename);
            $path = $this->pinsPath($page);
            $pins = $this->pins($page);
            if ($pin) {
                if (!preg_match('/^.{0,120}$/uD', $label)) throw new NibblyPageHistoryException('Invalid backup label');
                $pins[$filename] = $label;
            } else {
                unset($pins[$filename]);
            }
            if (!nibblyJsonAtomicWrite($path, $pins)) throw new NibblyPageHistoryException('Could not save backup protection');
        });
    }

    private function checkSnapshot(string $page, string $filename): void {
        $path = $this->dir . '/' . $filename;
        if (self::owner($filename) !== $page || !is_file($path) || is_link($path)) {
            throw new NibblyPageHistoryException('Backup not found for this page');
        }
    }

    public function delete(string $page, string $filename): void {
        $this->withLock($page, function () use ($page, $filename) {
            $this->checkSnapshot($page, $filename);
            if (array_key_exists($filename, $this->pins($page))) {
                throw new NibblyPageHistoryException('Remove backup protection before deleting this version');
            }
            if (!unlink($this->dir . '/' . $filename)) throw new NibblyPageHistoryException('Could not delete backup');
        });
    }
}
