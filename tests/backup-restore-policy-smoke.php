<?php

require_once __DIR__ . '/../includes/backup-helper.php';

function backupPolicyAssert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$required = backupRestoreRequiredFiles();
backupPolicyAssert(in_array('route.php', $required, true), 'Production route.php must be required.');
backupPolicyAssert(!in_array('router.php', $required, true), 'Development-only router.php must not be required.');

foreach ([
    '410.php',
    'error.php',
    'templates/home.php',
    'de/index.php',
] as $entry) {
    backupPolicyAssert(backupRestorePhpEntryAllowed($entry), "{$entry} should be accepted.");
}
backupPolicyAssert(
    !backupRestorePhpEntryAllowed('uploads/shell.php'),
    'PHP outside approved locations must be rejected.'
);
backupPolicyAssert(
    !backupRestorePhpEntryAllowed('tests/copilot-smoke.php'),
    'Development tests must not become an approved executable PHP location.'
);
backupPolicyAssert(
    backupRestoreEntryIgnored('tests/copilot-smoke.php'),
    'PHP tests from older backups must be ignored during restore.'
);
backupPolicyAssert(
    backupShouldSkipPath('tests/copilot-smoke.php'),
    'New full-site backups must exclude development tests.'
);
backupPolicyAssert(
    backupShouldSkipPath('.codex-qa/dashboard-toolbar.png'),
    'New full-site backups must exclude local Codex QA evidence.'
);
backupPolicyAssert(
    backupShouldSkipPath('examples/templates/page-simple.php'),
    'New full-site backups must exclude development examples.'
);
backupPolicyAssert(
    backupShouldSkipPath('CLAUDE.md') && backupShouldSkipPath('design-qa.md'),
    'New full-site backups must exclude internal root documentation.'
);
backupPolicyAssert(
    !backupShouldSkipPath('README.md') && !backupShouldSkipPath('THIRD-PARTY-NOTICES.md'),
    'Product and legal documentation must remain in full-site backups.'
);
backupPolicyAssert(
    !backupRestoreEntryIgnored('admin/api.php'),
    'Production PHP must not be ignored during restore.'
);

foreach ([
    'content/pages/de_home.json',
    'assets/images/hero.webp',
    'templates/home.php',
    'de/index.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/nav-config.php',
    'includes/site-page-hook.php',
    'css/fonts.css',
    'css/website.css',
    'css/page-home.css',
    'js/main.js',
    'backups/de_home_2026-07-23_120000.json',
] as $entry) {
    backupPolicyAssert(backupRestoreContentEntryAllowed($entry), "{$entry} should restore in content-only mode.");
}

foreach ([
    'admin/api.php',
    'css/style.css',
    'js/inline-editor.js',
    'backups/site-backup.zip',
] as $entry) {
    backupPolicyAssert(!backupRestoreContentEntryAllowed($entry), "{$entry} must stay out of content-only mode.");
}

echo "Backup restore policy smoke test passed.\n";
