<?php
/**
 * Applying, undoing and publishing change sets (agent phase P3).
 *
 * Writes go through one transaction helper: locks (global apply lock, then every file in
 * sorted order), a snapshot of each file, a journal, the writes, and a rollback from the
 * snapshots when anything fails. A journal that survives a crash is rolled back by
 * nibblyAgentRecoverJournals(), which runs at the start of every apply/undo/publish.
 *
 * The usual backups (backups/) are written as well, with the names the editors use.
 */

require_once __DIR__ . '/changeset.php';

const NIBBLY_AGENT_SNAPSHOT_DAYS = 30;
const NIBBLY_AGENT_STAGING_HOURS = 24;

// ---------------------------------------------------------------------------
// Secret and undo token
// ---------------------------------------------------------------------------

function nibblyAgentSecret(): string {
    $file = nibblyAgentDir() . '/.secret';
    if (is_file($file)) {
        $secret = trim((string)file_get_contents($file));
        if (strlen($secret) >= 32) return $secret;
    }
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $secret = bin2hex(random_bytes(32));
    $handle = @fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Could not store the agent secret.');
    $existing = trim((string)stream_get_contents($handle));
    if (strlen($existing) >= 32) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return $existing;
    }
    ftruncate($handle, 0);
    fwrite($handle, $secret);
    fflush($handle);
    @chmod($file, 0600);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $secret;
}

/** Undo token: change set id and a signature that survives a logout. */
function nibblyAgentUndoToken(string $csId): string {
    return $csId . '.' . hash_hmac('sha256', 'undo:' . $csId, nibblyAgentSecret());
}

function nibblyAgentVerifyUndoToken(string $csId, string $token): bool {
    return hash_equals(nibblyAgentUndoToken($csId), $token);
}

// ---------------------------------------------------------------------------
// Locks
// ---------------------------------------------------------------------------

/** @return resource|false */
function nibblyAgentGlobalLock(int $waitSeconds = 10) {
    $file = nibblyAgentDir() . '/apply.lock';
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $handle = @fopen($file, 'c');
    if ($handle === false) return false;
    $deadline = microtime(true) + $waitSeconds;
    do {
        if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        usleep(50000);
    } while (microtime(true) < $deadline);
    fclose($handle);
    return false;
}

/** Locks for files in sorted order, so two writers can never wait for each other. */
function nibblyAgentLockFiles(array $files): array {
    $files = array_values(array_unique($files));
    sort($files);
    $handles = [];
    foreach ($files as $file) {
        $handle = nibblyJsonLock($file);
        if ($handle === false) {
            nibblyAgentUnlock($handles);
            throw new RuntimeException('Could not lock ' . basename($file) . '.');
        }
        $handles[] = $handle;
    }
    return $handles;
}

function nibblyAgentUnlock(array $handles): void {
    foreach ($handles as $handle) {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

// ---------------------------------------------------------------------------
// Backups
// ---------------------------------------------------------------------------

function nibblyAgentBackupDir(): string {
    return defined('BACKUP_PATH') ? rtrim((string)BACKUP_PATH, '/') : nibblyAgentSiteRoot() . '/backups';
}

/** Copy an existing content file to backups/ with the name the editors use for it. */
function nibblyAgentBackupFile(string $file): void {
    if (!is_file($file)) return;
    $dir = nibblyAgentBackupDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    $base = basename($file, '.json');
    $kind = nibblyAgentTargetKindOfFile($file);
    $prefix = $kind === 'news' ? 'news_' . $base : ($kind === 'events' ? 'events' : $base);
    $stamp = date('Y-m-d_His') . '_' . bin2hex(random_bytes(3));
    @copy($file, $dir . '/' . $prefix . '_' . $stamp . '.json');
    $limit = defined('MAX_BACKUPS') ? (int)MAX_BACKUPS : 30;
    $backups = glob($dir . '/' . $prefix . '_*.json') ?: [];
    usort($backups, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    while (count($backups) > $limit) @unlink(array_pop($backups));
}

// ---------------------------------------------------------------------------
// Transaction
// ---------------------------------------------------------------------------

function nibblyAgentSnapshotDir(string $csId, string $phase): string {
    return nibblyAgentDir() . '/snapshots/' . $csId . '/' . $phase;
}

function nibblyAgentJournalPath(string $csId, string $phase): string {
    return nibblyAgentDir() . '/journal/' . $csId . '-' . $phase . '.json';
}

function nibblyAgentRelative(string $file): string {
    $root = rtrim(realpath(nibblyAgentSiteRoot()) ?: nibblyAgentSiteRoot(), '/') . '/';
    $real = (realpath(dirname($file)) ?: dirname($file)) . '/' . basename($file);
    return str_starts_with($real, $root) ? substr($real, strlen($root)) : $file;
}

function nibblyAgentAbsolute(string $relative): string {
    return rtrim(realpath(nibblyAgentSiteRoot()) ?: nibblyAgentSiteRoot(), '/') . '/' . $relative;
}

function nibblyAgentRawWrite(string $file, string $bytes): bool {
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $temporary = @tempnam($dir, '.nibbly-raw-');
    if ($temporary === false) return false;
    try {
        if (file_put_contents($temporary, $bytes) !== strlen($bytes)) return false;
        @chmod($temporary, is_file($file) ? (fileperms($file) & 0777) : (0666 & ~umask()));
        return rename($temporary, $file);
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

/**
 * Write several files as one unit. The caller holds the locks.
 *
 * @param array $changes [{file, data?: array, raw?: string, remove?: bool, trash?: string}]
 * @param array $options testHook: callable(string $event, string $file) for failure injection (CLI only)
 * @throws Throwable after rolling back
 */
function nibblyAgentWriteTransaction(string $csId, string $phase, array $changes, array $options = []): void {
    $hook = in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_callable($options['testHook'] ?? null) ? $options['testHook'] : null;
    $snapshots = nibblyAgentSnapshotDir($csId, $phase);
    $journal = ['csId' => $csId, 'phase' => $phase, 'state' => 'applying', 'startedAt' => date('c'), 'files' => []];
    foreach (array_values($changes) as $index => $change) {
        $file = $change['file'];
        $entry = ['path' => nibblyAgentRelative($file), 'existed' => is_file($file), 'beforeHash' => is_file($file) ? hash_file('sha256', $file) : null, 'snapshot' => null, 'trash' => null];
        if ($entry['existed']) {
            $snapshot = $snapshots . '/' . $index . '-' . basename($file);
            if (!is_dir($snapshots) && !@mkdir($snapshots, 0755, true)) throw new RuntimeException('Could not create the snapshot folder.');
            if (!@copy($file, $snapshot)) throw new RuntimeException('Could not snapshot ' . basename($file) . '.');
            $entry['snapshot'] = nibblyAgentRelative($snapshot);
        }
        if (!empty($change['trash'])) $entry['trash'] = nibblyAgentRelative($change['trash']);
        $journal['files'][] = $entry;
    }
    if (!nibblyJsonAtomicWrite(nibblyAgentJournalPath($csId, $phase), $journal)) throw new RuntimeException('Could not write the journal.');

    try {
        foreach (array_values($changes) as $index => $change) {
            $file = $change['file'];
            if (!empty($change['remove'])) {
                if (!empty($change['trash'])) {
                    if (!is_dir(dirname($change['trash'])) && !@mkdir(dirname($change['trash']), 0755, true)) throw new RuntimeException('Could not create the trash folder.');
                    if (!@rename($file, $change['trash'])) throw new RuntimeException('Could not move ' . basename($file) . ' to the trash.');
                } elseif (!@unlink($file)) {
                    throw new RuntimeException('Could not remove ' . basename($file) . '.');
                }
            } elseif (isset($change['raw'])) {
                if (!nibblyAgentRawWrite($file, (string)$change['raw'])) throw new RuntimeException('Could not write ' . basename($file) . '.');
            } elseif (!nibblyJsonAtomicWrite($file, (array)$change['data'])) {
                throw new RuntimeException('Could not write ' . basename($file) . '.');
            }
            if ($hook) $hook('written', $file);
        }
    } catch (Throwable $error) {
        nibblyAgentRollbackJournal($journal);
        @unlink(nibblyAgentJournalPath($csId, $phase));
        throw $error;
    }
    @unlink(nibblyAgentJournalPath($csId, $phase));
}

/** Put every file of a journal back to its state before the transaction. */
function nibblyAgentRollbackJournal(array $journal): void {
    foreach (array_reverse($journal['files']) as $entry) {
        $file = nibblyAgentAbsolute($entry['path']);
        if ($entry['trash'] !== null) {
            $trash = nibblyAgentAbsolute($entry['trash']);
            if (!is_file($file) && is_file($trash)) @rename($trash, $file);
        }
        if ($entry['existed']) {
            $snapshot = $entry['snapshot'] !== null ? nibblyAgentAbsolute($entry['snapshot']) : '';
            if ($snapshot !== '' && is_file($snapshot) && (!is_file($file) || hash_file('sha256', $file) !== $entry['beforeHash'])) {
                nibblyAgentRawWrite($file, (string)file_get_contents($snapshot));
            }
        } elseif (is_file($file)) {
            @unlink($file); // created by the interrupted transaction
        }
    }
}

/**
 * Roll back transactions that were interrupted (crash, killed process) and mark their
 * change sets failed. Runs under the global lock, so nothing is in flight.
 *
 * @return string[] ids of the recovered change sets
 */
function nibblyAgentRecoverJournalsLocked(): array {
    $recovered = [];
    foreach (glob(nibblyAgentDir() . '/journal/*.json') ?: [] as $path) {
        $journal = nibblyAgentReadJsonFile($path);
        if (!$journal || ($journal['state'] ?? '') !== 'applying') {
            @unlink($path);
            continue;
        }
        $locks = [];
        try {
            $locks = nibblyAgentLockFiles(array_map(static fn($e) => nibblyAgentAbsolute($e['path']), $journal['files']));
            nibblyAgentRollbackJournal($journal);
        } finally {
            nibblyAgentUnlock($locks);
        }
        @unlink($path);
        $csId = (string)$journal['csId'];
        nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($journal) {
            if (in_array($cs['status'], ['applying', 'ready', 'applied'], true) && ($journal['phase'] ?? '') === 'apply') {
                $cs['status'] = 'failed';
                $cs['error'] = 'The apply was interrupted; the files were restored.';
                return true;
            }
            return false;
        });
        nibblyAgentAudit('agent-recover', true, ['cs' => $csId, 'phase' => $journal['phase'] ?? '']);
        $recovered[] = $csId;
    }
    return $recovered;
}

/** @return string[] */
function nibblyAgentRecoverJournals(): array {
    if (!glob(nibblyAgentDir() . '/journal/*.json')) return [];
    $lock = nibblyAgentGlobalLock(0);
    if ($lock === false) return []; // another process is writing right now
    try {
        return nibblyAgentRecoverJournalsLocked();
    } finally {
        nibblyAgentUnlock([$lock]);
    }
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------

/** Items the user can publish afterwards, from the replay of the applied package. */
function nibblyAgentPublishableItems(array $cs, array $state): array {
    $items = [];
    foreach ($state['created'] as $target => $opId) {
        $kind = str_starts_with($target, 'news:') ? 'news' : (str_starts_with($target, 'event:') ? 'event' : 'page');
        $items[] = ['kind' => $kind, 'target' => $target, 'opId' => $opId, 'published' => false];
    }
    foreach ($cs['ops'] as $op) {
        if ($op['type'] === 'section_insert') {
            $items[] = ['kind' => 'section', 'target' => $op['target'], 'sectionId' => $op['section']['id'], 'opId' => $op['id'], 'published' => false];
        }
    }
    foreach ($state['tokens'] as $key => $tokens) {
        [$target, $listKey] = explode('|', $key, 2);
        $doc = nibblyAgentFinalDoc($state, $target);
        if ($doc === null) continue;
        $listItems = nibblyAgentListItemsOf($doc, $listKey);
        foreach ($tokens as $index => $opId) {
            if ($opId === null || !isset($listItems[$index])) continue;
            $op = null;
            foreach ($cs['ops'] as $candidate) if ($candidate['id'] === $opId) $op = $candidate;
            if ($op === null || $op['type'] !== 'list_insert' || empty($listItems[$index]['hidden'])) continue;
            $items[] = ['kind' => 'list_item', 'target' => $target, 'listKey' => $listKey, 'index' => $index, 'opId' => $opId,
                'titleHash' => hash('sha256', nibblyAgentItemLabel($listItems[$index])), 'published' => false];
        }
    }
    return $items;
}

/** What gets written for a changed file: the replayed document plus a modification time. */
function nibblyAgentFileChanges(array $changed): array {
    $changes = [];
    foreach ($changed as $file => $change) {
        $data = $change['after'];
        if (is_array($data)) $data['lastModified'] = date('c');
        $changes[] = ['file' => $file, 'data' => $data];
    }
    return $changes;
}

/**
 * Apply a reviewed change set.
 *
 * @param string $previewHash the hash the user confirmed (from the review panel)
 * @param array  $actor       {userKey, isAdmin}
 * @param array  $options     testHook (CLI only)
 * @return array{ok:bool,status:string,error?:string,undoToken?:string,files?:array}
 */
function nibblyAgentChangesetApply(string $csId, string $previewHash, array $actor, array $options = []): array {
    $cs = nibblyAgentChangesetLoad($csId);
    if ($cs === null || !nibblyAgentCanSeeChangeset($cs, $actor)) return ['ok' => false, 'status' => 'missing', 'error' => 'Unknown change set.'];
    if ($cs['status'] !== 'ready') return ['ok' => false, 'status' => $cs['status'], 'error' => 'This change set is ' . $cs['status'] . ' and cannot be applied.'];
    if ($previewHash === '' || !hash_equals((string)$cs['previewHash'], $previewHash)) {
        return ['ok' => false, 'status' => 'ready', 'error' => 'The change set changed after the preview. Review it again before applying.'];
    }
    if ($cs['media'] && !function_exists('nibblyAgentApplyMedia')) {
        return ['ok' => false, 'status' => 'ready', 'error' => 'Images in change sets are not supported yet.'];
    }

    $global = nibblyAgentGlobalLock();
    if ($global === false) return ['ok' => false, 'status' => 'ready', 'error' => 'Another change is being applied. Try again in a moment.'];
    $locks = [];
    try {
        nibblyAgentRecoverJournalsLocked();
        $cs = nibblyAgentChangesetLoad($csId);
        if ($cs === null || $cs['status'] !== 'ready') return ['ok' => false, 'status' => $cs['status'] ?? 'missing', 'error' => 'This change set is no longer ready.'];

        $files = [];
        foreach (nibblyAgentOpTargets($cs['ops']) as $target) $files[] = nibblyAgentTargetStorage($target)['file'];
        $locks = nibblyAgentLockFiles($files);

        if ($conflicts = nibblyAgentConflicts($cs)) {
            nibblyAgentChangesetMutate($csId, static function (array &$cs) { $cs['status'] = 'conflict'; return true; });
            nibblyAgentAudit('agent-apply', false, ['cs' => $csId, 'reason' => 'conflict', 'targets' => $conflicts]);
            return ['ok' => false, 'status' => 'conflict', 'error' => 'The content changed since this change set was started (' . implode(', ', $conflicts) . '). Revalidate it first.'];
        }
        $replay = nibblyAgentReplayOps($cs['ops']);
        $validation = nibblyAgentValidate($cs, $replay, true);
        if (!$validation['ok']) {
            return ['ok' => false, 'status' => 'ready', 'error' => 'Validation failed: ' . ($validation['errors'][0]['message'] ?? 'unknown error')];
        }
        if (($cs['renderCheck']['status'] ?? '') !== 'passed' || !hash_equals(nibblyAgentPreviewHash($cs, $replay), $previewHash)) {
            return ['ok' => false, 'status' => 'ready', 'error' => 'The render check has not passed for the current content. Review the change set again.'];
        }

        nibblyAgentChangesetMutate($csId, static function (array &$cs) { $cs['status'] = 'applying'; return true; });
        $changed = nibblyAgentChangedFiles($replay['state']);
        $changes = nibblyAgentFileChanges($changed);
        foreach ($changed as $file => $change) {
            if ($change['exists']) nibblyAgentBackupFile($file);
        }
        try {
            if ($cs['media']) nibblyAgentApplyMedia($cs);
            nibblyAgentWriteTransaction($csId, 'apply', $changes, $options);
        } catch (Throwable $error) {
            nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($error) {
                $cs['status'] = 'failed';
                $cs['error'] = mb_substr($error->getMessage(), 0, 300, 'UTF-8');
                return true;
            });
            nibblyAgentAudit('agent-apply', false, ['cs' => $csId, 'reason' => 'write-failed']);
            return ['ok' => false, 'status' => 'failed', 'error' => 'Applying failed and nothing was changed: ' . $error->getMessage()];
        }

        $revisions = [];
        $fileInfo = [];
        foreach ($changed as $file => $change) {
            $relative = nibblyAgentRelative($file);
            $revisions[$relative] = nibblyJsonRevision($file);
            $fileInfo[$relative] = ['existed' => $change['exists'], 'kind' => nibblyAgentTargetKindOfFile($file)];
        }
        $applied = ['at' => date('c'), 'by' => (string)($actor['userKey'] ?? ''), 'revisions' => $revisions, 'files' => $fileInfo,
            'items' => nibblyAgentPublishableItems($cs, $replay['state'])];
        nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($applied) {
            $cs['status'] = 'applied';
            $cs['applied'] = $applied;
            unset($cs['error']);
            return true;
        });
        nibblyAgentAudit('agent-apply', true, [
            'cs' => $csId, 'run' => $cs['runId'], 'targets' => nibblyAgentOpTargets($cs['ops']),
            'operations' => array_map(static fn($op) => ['id' => $op['id'], 'type' => $op['type'], 'path' => $op['path'] ?? ($op['listKey'] ?? null), 'valueHash' => nibblyAgentValueHash($op['value'] ?? ($op['item'] ?? ($op['post'] ?? ($op['page'] ?? ($op['event'] ?? ($op['src'] ?? null))))))], $cs['ops']),
        ]);
        return ['ok' => true, 'status' => 'applied', 'undoToken' => nibblyAgentUndoToken($csId), 'files' => array_keys($revisions), 'items' => $applied['items']];
    } finally {
        nibblyAgentUnlock($locks);
        nibblyAgentUnlock([$global]);
    }
}

// ---------------------------------------------------------------------------
// Undo
// ---------------------------------------------------------------------------

/**
 * Undo an applied change set as a whole.
 *
 * Refused when a file no longer has the revision the apply left behind (someone else
 * changed it since). New pages go to the pages trash, new news posts are removed (a copy
 * stays in the snapshots), events are restored with the rest of events.json.
 *
 * @return array{ok:bool,status:string,error?:string}
 */
function nibblyAgentChangesetUndo(string $csId, string $token, array $actor, array $options = []): array {
    $cs = nibblyAgentChangesetLoad($csId);
    if ($cs === null || !nibblyAgentCanSeeChangeset($cs, $actor) || !nibblyAgentVerifyUndoToken($csId, $token)) {
        return ['ok' => false, 'status' => 'missing', 'error' => 'Undo is not possible for this change set.'];
    }
    if ($cs['status'] !== 'applied' || empty($cs['applied'])) return ['ok' => false, 'status' => $cs['status'], 'error' => 'Only an applied change set can be undone.'];

    $global = nibblyAgentGlobalLock();
    if ($global === false) return ['ok' => false, 'status' => 'applied', 'error' => 'Another change is being applied. Try again in a moment.'];
    $locks = [];
    try {
        nibblyAgentRecoverJournalsLocked();
        $cs = nibblyAgentChangesetLoad($csId);
        $applied = $cs['applied'];
        $files = array_map('nibblyAgentAbsolute', array_keys($applied['revisions']));
        $locks = nibblyAgentLockFiles($files);

        $changes = [];
        foreach ($applied['revisions'] as $relative => $revision) {
            $file = nibblyAgentAbsolute($relative);
            if (nibblyJsonRevision($file) !== $revision) {
                return ['ok' => false, 'status' => 'applied', 'error' => basename($file) . ' was changed after this change set was applied, so it cannot be undone safely.'];
            }
            $info = $applied['files'][$relative];
            if ($info['existed']) {
                $snapshot = nibblyAgentSnapshotDir($csId, 'apply');
                $source = null;
                foreach (glob($snapshot . '/*-' . basename($file)) ?: [] as $candidate) $source = $candidate;
                if ($source === null) return ['ok' => false, 'status' => 'applied', 'error' => 'The snapshot of ' . basename($file) . ' is gone.'];
                $changes[] = ['file' => $file, 'raw' => (string)file_get_contents($source)];
            } elseif ($info['kind'] === 'page') {
                $trashDir = defined('PAGES_TRASH_PATH') ? rtrim((string)PAGES_TRASH_PATH, '/') : nibblyAgentSiteRoot() . '/content/pages-trash';
                $changes[] = ['file' => $file, 'remove' => true, 'trash' => $trashDir . '/' . basename($file, '.json') . '_' . date('Y-m-d_His') . '.json'];
            } else {
                // News posts have no trash: keep a copy next to the snapshots.
                $keep = nibblyAgentDir() . '/snapshots/' . $csId . '/undone-' . basename($file);
                if (!@copy($file, $keep)) return ['ok' => false, 'status' => 'applied', 'error' => 'Could not keep a copy of ' . basename($file) . '.'];
                $changes[] = ['file' => $file, 'remove' => true];
            }
        }
        foreach ($changes as $change) {
            if (is_file($change['file'])) nibblyAgentBackupFile($change['file']);
        }
        try {
            nibblyAgentWriteTransaction($csId, 'undo', $changes, $options);
        } catch (Throwable $error) {
            nibblyAgentAudit('agent-undo', false, ['cs' => $csId, 'reason' => 'write-failed']);
            return ['ok' => false, 'status' => 'applied', 'error' => 'Undo failed and nothing was changed: ' . $error->getMessage()];
        }
        nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($actor) {
            $cs['status'] = 'undone';
            $cs['undone'] = ['at' => date('c'), 'by' => (string)($actor['userKey'] ?? '')];
            return true;
        });
        nibblyAgentAudit('agent-undo', true, ['cs' => $csId, 'files' => array_keys($applied['revisions'])]);
        return ['ok' => true, 'status' => 'undone'];
    } finally {
        nibblyAgentUnlock($locks);
        nibblyAgentUnlock([$global]);
    }
}

// ---------------------------------------------------------------------------
// Publish
// ---------------------------------------------------------------------------

/**
 * Make one applied item visible: a news post, event, page (leaves private), list item or
 * section. Needs the publish right (admins have it). Updates the stored post-apply
 * revision, so the change set can still be undone as a whole afterwards.
 *
 * @param array $item {kind, target, listKey?, index?|sectionId?}, as listed in the apply result
 * @param array $actor {userKey, isAdmin, canPublish}
 * @return array{ok:bool,error?:string}
 */
function nibblyAgentChangesetPublish(string $csId, array $item, array $actor, array $options = []): array {
    $cs = nibblyAgentChangesetLoad($csId);
    if ($cs === null || !nibblyAgentCanSeeChangeset($cs, $actor)) return ['ok' => false, 'error' => 'Unknown change set.'];
    if (empty($actor['isAdmin']) && empty($actor['canPublish'])) return ['ok' => false, 'error' => 'You are not allowed to publish.'];
    if ($cs['status'] !== 'applied' || empty($cs['applied'])) return ['ok' => false, 'error' => 'Only applied change sets can be published.'];

    $found = null;
    foreach ($cs['applied']['items'] as $index => $candidate) {
        if ($candidate['kind'] === ($item['kind'] ?? '') && $candidate['target'] === ($item['target'] ?? '')
            && (int)($candidate['index'] ?? -1) === (int)($item['index'] ?? ($candidate['index'] ?? -1))
            && ($candidate['sectionId'] ?? '') === ($item['sectionId'] ?? ($candidate['sectionId'] ?? ''))
            && ($candidate['listKey'] ?? '') === ($item['listKey'] ?? ($candidate['listKey'] ?? ''))) {
            $found = $index;
            break;
        }
    }
    if ($found === null) return ['ok' => false, 'error' => 'This item is not part of the change set.'];
    $entry = $cs['applied']['items'][$found];
    if (!empty($entry['published'])) return ['ok' => false, 'error' => 'This item is already published.'];

    $storage = nibblyAgentTargetStorage($entry['target']);
    $global = nibblyAgentGlobalLock();
    if ($global === false) return ['ok' => false, 'error' => 'Another change is being applied. Try again in a moment.'];
    $locks = [];
    try {
        nibblyAgentRecoverJournalsLocked();
        $locks = nibblyAgentLockFiles([$storage['file']]);
        $data = nibblyAgentReadJsonFile($storage['file']);
        if ($data === null) return ['ok' => false, 'error' => 'The item no longer exists.'];
        $relative = nibblyAgentRelative($storage['file']);
        $matched = nibblyJsonRevision($storage['file']) === ($cs['applied']['revisions'][$relative] ?? '');

        switch ($entry['kind']) {
            case 'news':
                if (empty($data['hidden'])) return ['ok' => false, 'error' => 'The post is already visible.'];
                $data = nibblyCopilotPublishNewsData($data);
                break;
            case 'event':
                try {
                    $data = nibblyCopilotPublishEventData($data, $storage['key']);
                } catch (RuntimeException $e) {
                    return ['ok' => false, 'error' => $e->getMessage()];
                }
                break;
            case 'page':
                if (empty($data['visibility'])) return ['ok' => false, 'error' => 'The page is already public.'];
                $data = nibblyCopilotPublishPageData($data);
                break;
            case 'section':
                $done = false;
                foreach ((array)($data['sections'] ?? []) as $i => $section) {
                    if (is_array($section) && ($section['id'] ?? null) === $entry['sectionId']) {
                        unset($data['sections'][$i]['hidden']);
                        $done = true;
                    }
                }
                if (!$done) return ['ok' => false, 'error' => 'The section no longer exists.'];
                break;
            case 'list_item':
                $items = nibblyAgentListItemsOf($data, $entry['listKey']);
                $index = (int)$entry['index'];
                if (!isset($items[$index]) || empty($items[$index]['hidden']) || !hash_equals($entry['titleHash'], hash('sha256', nibblyAgentItemLabel($items[$index])))) {
                    return ['ok' => false, 'error' => 'The list changed since it was applied; publish this item in the editor.'];
                }
                unset($items[$index]['hidden']);
                setNestedValue($data, $entry['listKey'], array_values($items));
                break;
            default:
                return ['ok' => false, 'error' => 'Unknown item.'];
        }
        $data['lastModified'] = date('c');
        nibblyAgentBackupFile($storage['file']);
        $phase = 'publish-' . ($found + 1);
        try {
            nibblyAgentWriteTransaction($csId, $phase, [['file' => $storage['file'], 'data' => $data]], $options);
        } catch (Throwable $error) {
            nibblyAgentAudit('agent-publish', false, ['cs' => $csId, 'item' => $entry['kind'], 'reason' => 'write-failed']);
            return ['ok' => false, 'error' => 'Publishing failed and nothing was changed: ' . $error->getMessage()];
        }
        $newRevision = nibblyJsonRevision($storage['file']);
        nibblyAgentChangesetMutate($csId, static function (array &$cs) use ($found, $relative, $matched, $newRevision) {
            $cs['applied']['items'][$found]['published'] = true;
            // The publish belongs to this change set; keep Undo possible as long as nobody else touched the file.
            if ($matched) $cs['applied']['revisions'][$relative] = $newRevision;
            return true;
        });
        nibblyAgentAudit('agent-publish', true, ['cs' => $csId, 'item' => $entry['kind'], 'target' => $entry['target']]);
        return ['ok' => true];
    } finally {
        nibblyAgentUnlock($locks);
        nibblyAgentUnlock([$global]);
    }
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

/** Remove old runs' leftovers: unfinished change sets and staging after 24 hours, snapshots after 30 days. */
function nibblyAgentCleanup(?int $now = null): array {
    $now ??= time();
    $removed = ['changesets' => 0, 'staging' => 0, 'snapshots' => 0];
    foreach (glob(nibblyAgentDir() . '/changesets/*.json') ?: [] as $file) {
        $cs = nibblyAgentReadJsonFile($file);
        if (!$cs) continue;
        $age = $now - (int)strtotime((string)($cs['updatedAt'] ?? 'now'));
        $finishedOrStale = in_array($cs['status'], ['discarded', 'undone', 'failed', 'staging', 'ready', 'conflict'], true);
        if ($finishedOrStale && $age > NIBBLY_AGENT_STAGING_HOURS * 3600 && @unlink($file)) {
            $removed['changesets']++;
            nibblyAgentRemoveTree(nibblyAgentDir() . '/staging/' . $cs['id']);
        }
    }
    foreach (glob(nibblyAgentDir() . '/staging/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_file(nibblyAgentChangesetPath(basename($dir))) && $now - filemtime($dir) > NIBBLY_AGENT_STAGING_HOURS * 3600) {
            nibblyAgentRemoveTree($dir);
            $removed['staging']++;
        }
    }
    foreach (glob(nibblyAgentDir() . '/snapshots/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if ($now - filemtime($dir) > NIBBLY_AGENT_SNAPSHOT_DAYS * 86400) {
            nibblyAgentRemoveTree($dir);
            $removed['snapshots']++;
        }
    }
    return $removed;
}
